<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\IndexInvoiceRequest;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Invoices an organization issues to its customers.
 *
 * Not the Billing module: that reads Stripe for what this organization owes us
 * for the platform. These are the organization's own sales documents, and they
 * are governed by invoices.* rather than billing.*.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    #[OA\Get(
        path: '/api/v1/invoices',
        summary: 'List invoices',
        description: 'Across all customers, or one via customer_id. `status=overdue` filters on the derived state — unpaid and past due — in SQL rather than in PHP, so pagination stays correct.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'q', in: 'query', description: 'Search invoice number or notes', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'sent', 'paid', 'void', 'overdue'])),
            new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'due_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'due_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated invoices'),
            new OA\Response(response: 403, description: 'Lacks invoices.view'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function index(IndexInvoiceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validated();

        $query = Invoice::query()
            // Fixed query count regardless of page size; lazy loading is off.
            ->with('customer:id,name,customer_number')
            ->search($filters['q'] ?? null);

        if (($filters['status'] ?? null) === 'overdue') {
            $query->overdue();
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['due_after'])) {
            $query->whereDate('due_date', '>=', $filters['due_after']);
        }

        if (! empty($filters['due_before'])) {
            $query->whereDate('due_date', '<=', $filters['due_before']);
        }

        // Allow-listed by IndexInvoiceRequest, so `sort` can never reach SQL as
        // arbitrary user input.
        $sort = $filters['sort'] ?? 'issue_date';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction)->orderBy('id', 'desc');

        return InvoiceResource::collection(
            $query->paginate($filters['per_page'] ?? 15)->withQueryString(),
        );
    }

    #[OA\Get(
        path: '/api/v1/invoices/{invoice}',
        summary: 'One invoice with its lines',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The invoice'),
            new OA\Response(response: 403, description: 'Lacks invoices.view'),
            new OA\Response(response: 404, description: 'Not in this organization'),
        ],
    )]
    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load(['items', 'customer']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    #[OA\Post(
        path: '/api/v1/customers/{customer}/invoices',
        summary: 'Raise an invoice for a customer',
        description: 'Created as a draft. The number is issued by the server and the totals are computed from the lines in integer minor units — neither can be supplied by the client, or an invoice could be raised for any amount.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['items'], properties: [
            new OA\Property(property: 'issue_date', type: 'string', format: 'date'),
            new OA\Property(property: 'due_date', type: 'string', format: 'date'),
            new OA\Property(property: 'currency', type: 'string', example: 'USD'),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'terms', type: 'string', nullable: true),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'quantity', type: 'number'),
                new OA\Property(property: 'unit_price', type: 'number'),
                new OA\Property(property: 'tax_rate', type: 'number', description: 'Percent'),
            ])),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Draft created'),
            new OA\Response(response: 403, description: 'Lacks invoices.create'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreInvoiceRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $invoice = $this->invoices->create($customer, $request->validated(), $request->user());

        return response()->json([
            'message' => "Invoice {$invoice->number} created.",
            'data' => new InvoiceResource($invoice),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/invoices/{invoice}',
        summary: 'Edit an invoice',
        description: 'Figures may only change while the invoice is a draft: once sent, the customer holds a copy, and restating the amounts would make our record disagree with theirs. Notes, terms and the due date stay editable.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Lacks invoices.update'),
            new OA\Response(response: 422, description: 'Issued invoice, or validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $updated = $this->invoices->update($invoice, $request->validated());

        return response()->json([
            'message' => 'Invoice updated.',
            'data' => new InvoiceResource($updated),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/send',
        summary: 'Issue a draft invoice',
        description: 'Moves draft → sent and freezes the figures. An invoice with no lines is refused: sending an empty demand for payment is never intended.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sent'),
            new OA\Response(response: 403, description: 'Lacks invoices.update'),
            new OA\Response(response: 422, description: 'Not a draft, or has no lines', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function send(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $sent = $this->invoices->send($invoice);

        return response()->json([
            'message' => "Invoice {$sent->number} sent.",
            'data' => new InvoiceResource($sent->load('items')),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/payments',
        summary: 'Record a payment',
        description: 'Part payments are accepted. The invoice only becomes paid once the balance reaches zero, and paid_at is stamped at that moment rather than on the first instalment. Paying more than the balance is refused.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [
            new OA\Property(property: 'amount', type: 'number', format: 'float'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Payment recorded'),
            new OA\Response(response: 403, description: 'Lacks invoices.update'),
            new OA\Response(response: 422, description: 'Void invoice, or more than the balance', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
        ]);

        $updated = $this->invoices->recordPayment($invoice, (float) $validated['amount']);

        return response()->json([
            'message' => 'Payment recorded.',
            'data' => new InvoiceResource($updated),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/void',
        summary: 'Void an invoice',
        description: 'Cancels an issued invoice while keeping its number. Deleting it would leave a gap in the sequence, which is exactly what an auditor asks about. A paid invoice cannot be voided.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Voided'),
            new OA\Response(response: 403, description: 'Lacks invoices.delete'),
            new OA\Response(response: 422, description: 'Already paid', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function void(Invoice $invoice): JsonResponse
    {
        $this->authorize('void', $invoice);

        $voided = $this->invoices->void($invoice);

        return response()->json([
            'message' => "Invoice {$voided->number} voided.",
            'data' => new InvoiceResource($voided),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/invoices/{invoice}',
        summary: 'Delete a draft invoice',
        description: 'Only a draft may be deleted, because it was never issued. Anything sent must be voided instead so its number survives.',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/OrganizationHeader'),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Lacks invoices.delete'),
            new OA\Response(response: 422, description: 'Not a draft', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);

        if (! $invoice->isEditable()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['status' => [__('An issued invoice cannot be deleted. Void it instead so its number survives.')]],
            ], 422);
        }

        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted.']);
    }
}
