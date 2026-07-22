<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\StripePrice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    // Authorization is enforced by the `super-admin` middleware on the route —
    // Form Requests only shape input.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Plans are central. These admin routes never boot tenancy, so the
        // default connection is already central — but the rule is qualified
        // anyway, matching the billing rules, so it cannot break if the route
        // ever moves behind a tenant-aware group.
        $central = config('tenancy.database.central_connection');

        return [
            'name' => ['required', 'string', 'max:255'],

            // Optional: derived from the name when omitted. The slug is the
            // stable public handle (billing/subscribe takes a slug), so it is
            // constrained to url-safe characters.
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique("{$central}.plans", 'slug')],

            'description' => ['nullable', 'string', 'max:1000'],

            /**
             * Stripe price ids. Nullable on purpose: a plan can be drafted
             * before its Stripe prices exist. Until an interval has a price id
             * it cannot be subscribed to — see AdminPlanResource's `stripe`
             * block, which reports that state rather than hiding it.
             */
            'stripe_monthly_price_id' => ['nullable', 'string', 'max:255', new StripePrice('month')],
            'stripe_annual_price_id' => ['nullable', 'string', 'max:255', new StripePrice('year')],

            // Minor units (cents), display only. Never used to charge.
            'monthly_amount' => ['nullable', 'integer', 'min:0'],
            'annual_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            /**
             * Usage ceilings. null is "unlimited" and is deliberately different
             * from 0, which means "none allowed" — UsageService relies on that
             * distinction, so the rules must let null through rather than
             * coercing it.
             */
            'max_users' => ['nullable', 'integer', 'min:0'],
            'max_customers' => ['nullable', 'integer', 'min:0'],
            'max_storage_mb' => ['nullable', 'integer', 'min:0'],

            'features' => ['nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:255'],

            'is_active' => ['nullable', 'boolean'],
            // unsignedSmallInteger in the schema.
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
