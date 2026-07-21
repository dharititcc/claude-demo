<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class SubscribeRequest extends FormRequest
{
    /**
     * Authorization is enforced in the controller via $this->authorize()/policies —
     * Form Requests only shape input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', $this->existingPlanRule()],
            'interval' => ['required', Rule::in(Plan::INTERVALS)],
            'payment_method' => ['nullable', 'string'],
            'coupon' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * "Plan exists and is active", qualified with the central connection.
     *
     * These routes run inside tenant context, so the default connection is the
     * organization's own database — an unqualified exists rule would look for
     * `plans` there and fail with a missing-table error on *every* call, valid
     * input included.
     */
    private function existingPlanRule(): Exists
    {
        $central = config('tenancy.database.central_connection');

        return Rule::exists("{$central}.plans", 'slug')->where('is_active', true);
    }
}
