<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    // Authorization is enforced by the `super-admin` middleware on the route —
    // Form Requests only shape input.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is `sometimes`: this is a partial edit, and a key that is
     * absent must stay untouched. Note the difference from a key sent as null,
     * which explicitly clears a limit back to "unlimited".
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $central = config('tenancy.database.central_connection');

        // Ignore this plan's own row, or renaming without changing the slug
        // would collide with itself.
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique("{$central}.plans", 'slug')->ignore($planId)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'stripe_monthly_price_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_annual_price_id' => ['sometimes', 'nullable', 'string', 'max:255'],

            'monthly_amount' => ['sometimes', 'integer', 'min:0'],
            'annual_amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            // null clears the ceiling to "unlimited"; 0 means "none allowed".
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_customers' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_storage_mb' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'features' => ['sometimes', 'nullable', 'array', 'max:50'],
            'features.*' => ['string', 'max:255'],

            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
