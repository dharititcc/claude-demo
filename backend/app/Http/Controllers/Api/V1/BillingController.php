<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use App\Http\Requests\Billing\SwapPlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use OpenApi\Attributes as OA;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing for the active organization.
 *
 * Card details never reach this application: the browser exchanges them with
 * Stripe directly (via a SetupIntent) and sends us only a payment-method id.
 * That is what keeps this codebase out of PCI scope.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly UsageService $usage,
    ) {}

    /**
     * Plans available to this organization.
     */
    #[OA\Get(
        path: '/api/v1/billing/plans',
        summary: 'Available plans',
        description: 'Amounts are for display only; Stripe is the source of truth for what is actually charged. Stripe price ids are never exposed. A null limit means unlimited, which is different from 0.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Active plans with limits and features'), new OA\Response(response: 403, description: 'Lacks billing.view')],
    )]
    public function plans(Request $request): JsonResponse
    {
        $this->authorize('viewBilling', tenant());

        $current = $this->billing->planForSubscription(
            $this->billing->activeSubscription(tenant()),
        ) ?? $this->usage->planFor(tenant());

        return response()->json([
            'data' => PlanResource::forOrganization(Plan::active()->get(), $current?->id),
        ]);
    }

    /**
     * Subscription state, usage, and the card on file.
     */
    #[OA\Get(
        path: '/api/v1/billing',
        summary: 'Subscription, usage, and payment method',
        description: 'Renders entirely from local state: an organization that has never paid has no Stripe customer, and the billing page must not depend on Stripe uptime.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Subscription state, usage against limits, and the card on file'), new OA\Response(response: 403, description: 'Lacks billing.view')],
    )]
    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewBilling', tenant());

        return response()->json(['data' => $this->overviewPayload()]);
    }

    /**
     * A SetupIntent client secret for Stripe.js to collect card details.
     */
    #[OA\Get(
        path: '/api/v1/billing/setup-intent',
        summary: 'A Stripe SetupIntent client secret',
        description: 'Stripe.js uses this to collect card details in the browser. Raw card data never touches our servers, which is what keeps this application out of PCI scope.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'The client secret'), new OA\Response(response: 403, description: 'Lacks billing.manage')],
    )]
    public function setupIntent(Request $request): JsonResponse
    {
        $this->authorize('manageBilling', tenant());

        $tenant = tenant();
        $tenant->createOrGetStripeCustomer();

        return response()->json([
            'data' => ['client_secret' => $tenant->createSetupIntent()->client_secret],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/billing/subscribe',
        summary: 'Start a subscription',
        description: 'payment_method is a Stripe payment-method id from Stripe.js, never a card number. Remaining trial is preserved rather than restarted, and an organization that already subscribed once does not get a second free trial.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['plan', 'interval'], properties: [new OA\Property(property: 'plan', type: 'string', description: 'Plan slug'), new OA\Property(property: 'interval', type: 'string', enum: ['monthly', 'annual']), new OA\Property(property: 'payment_method', type: 'string', nullable: true, description: 'Stripe payment-method id'), new OA\Property(property: 'coupon', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Subscribed'), new OA\Response(response: 402, description: 'Payment failed or requires action'), new OA\Response(response: 403, description: 'Lacks billing.manage — owners only'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $this->authorize('manageBilling', tenant());

        $validated = $request->validated();

        $plan = Plan::where('slug', $validated['plan'])->firstOrFail();

        $this->billing->subscribe(
            tenant(),
            $plan,
            $validated['interval'],
            $validated['payment_method'] ?? null,
            $validated['coupon'] ?? null,
        );

        return response()->json([
            'message' => "Subscribed to {$plan->name}.",
            'data' => $this->overviewPayload(),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/billing/subscription',
        summary: 'Change plan or billing interval',
        description: 'Proration is invoiced immediately rather than accruing silently onto the next invoice: an upgrade the customer just chose should not surprise them a month later.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['plan', 'interval'], properties: [new OA\Property(property: 'plan', type: 'string', description: 'Plan slug'), new OA\Property(property: 'interval', type: 'string', enum: ['monthly', 'annual'])])),
        responses: [new OA\Response(response: 200, description: 'Plan changed'), new OA\Response(response: 402, description: 'Payment failed'), new OA\Response(response: 403, description: 'Lacks billing.manage'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))],
    )]
    public function swap(SwapPlanRequest $request): JsonResponse
    {
        $this->authorize('manageBilling', tenant());

        $validated = $request->validated();

        $plan = Plan::where('slug', $validated['plan'])->firstOrFail();

        $this->billing->swap(tenant(), $plan, $validated['interval']);

        return response()->json([
            'message' => "Switched to {$plan->name}.",
            'data' => $this->overviewPayload(),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/billing/subscription',
        summary: 'Cancel at the end of the period',
        description: 'Access continues until the period ends (the grace period): cancelling must not destroy access already paid for. Use resume to undo.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Will end at period end'), new OA\Response(response: 403, description: 'Lacks billing.manage'), new OA\Response(response: 422, description: 'No active subscription')],
    )]
    public function cancel(Request $request): JsonResponse
    {
        $this->authorize('manageBilling', tenant());

        $this->billing->cancel(tenant());

        return response()->json([
            'message' => 'Your subscription will end at the close of the current period.',
            'data' => $this->overviewPayload(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/billing/subscription/resume',
        summary: 'Resume a cancelled subscription',
        description: 'Only possible during the grace period; afterwards a new subscription is required.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Resumed'), new OA\Response(response: 403, description: 'Lacks billing.manage'), new OA\Response(response: 422, description: 'Not within a grace period')],
    )]
    public function resume(Request $request): JsonResponse
    {
        $this->authorize('manageBilling', tenant());

        $this->billing->resume(tenant());

        return response()->json([
            'message' => 'Subscription resumed.',
            'data' => $this->overviewPayload(),
        ]);
    }

    /**
     * Invoices, fetched live from Stripe — we do not keep our own copy of
     * financial records that Stripe already owns and can amend.
     */
    #[OA\Get(
        path: '/api/v1/billing/invoices',
        summary: 'Invoice history',
        description: 'Read live from Stripe rather than kept as our own copy of records Stripe owns. Empty for an organization with no Stripe customer.',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader')],
        responses: [new OA\Response(response: 200, description: 'Invoices with totals, tax, and download links'), new OA\Response(response: 403, description: 'Lacks billing.view')],
    )]
    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('viewBilling', tenant());

        $tenant = tenant();

        // An organization that has never paid has no Stripe customer at all.
        if ($tenant->stripe_id === null) {
            return response()->json(['data' => []]);
        }

        try {
            $invoices = $tenant->invoices();
        } catch (InvalidCustomer|ApiErrorException) {
            // Billing history is not worth a 500 on the billing page.
            return response()->json(['data' => []]);
        }

        return response()->json([
            'data' => $invoices->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'date' => $invoice->date()->toIso8601String(),
                'total' => $invoice->total(),
                'subtotal' => $invoice->subtotal(),
                'tax' => $invoice->tax(),
                'status' => $invoice->status ?? 'paid',
                'paid' => $invoice->status === 'paid',
                'download_url' => route('billing.invoice', ['invoice' => $invoice->id]),
            ])->values(),
        ]);
    }

    /**
     * Stream a single invoice PDF.
     */
    #[OA\Get(
        path: '/api/v1/billing/invoices/{invoice}',
        summary: 'Download an invoice PDF',
        security: [['sanctum' => []]],
        tags: ['Billing'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'), new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Invoice PDF'), new OA\Response(response: 403, description: 'Lacks billing.view'), new OA\Response(response: 404, description: 'Not an invoice of this organization')],
    )]
    public function downloadInvoice(Request $request, string $invoice): Response
    {
        $this->authorize('viewBilling', tenant());

        // downloadInvoice() verifies the invoice belongs to this customer, so a
        // guessed id from another organization cannot be fetched.
        return tenant()->downloadInvoice($invoice, [
            'vendor' => config('app.name'),
            'product' => tenant()->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overviewPayload(): array
    {
        $tenant = tenant();
        $subscription = $tenant->subscription(BillingService::SUBSCRIPTION);
        $plan = $this->billing->planForSubscription($subscription) ?? $this->usage->planFor($tenant);

        return [
            'subscription' => [
                'active' => $subscription?->valid() ?? false,
                'status' => $subscription?->stripe_status,
                'plan' => $plan ? (new PlanResource($plan))->markCurrent($plan->id) : null,
                'interval' => $this->billing->intervalForSubscription($subscription),
                'on_trial' => $tenant->onTrial(BillingService::SUBSCRIPTION) || $tenant->isOnTrial(),
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                'on_grace_period' => $subscription?->onGracePeriod() ?? false,
                'cancelled' => $subscription?->canceled() ?? false,
                'ends_at' => $subscription?->ends_at?->toIso8601String(),

                // Read from our own column, not asStripeSubscription(): that is a
                // live API call, and rendering one date must not put Stripe's
                // uptime in the path of loading the billing page. Kept current by
                // the subscription webhooks.
                'renews_at' => $subscription?->current_period_end?->toIso8601String(),
            ],
            'usage' => $this->usage->report($tenant),
            'payment_method' => $tenant->pm_last_four === null ? null : [
                'brand' => $tenant->pm_type,
                'last_four' => $tenant->pm_last_four,
                'exp_month' => null,
                'exp_year' => null,
            ],
        ];
    }
}
