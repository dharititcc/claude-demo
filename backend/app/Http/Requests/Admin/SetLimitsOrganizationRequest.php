<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetLimitsOrganizationRequest extends FormRequest
{
    // Authorization is enforced in the controller via $this->authorize()/policies —
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
        return [
            'overrides' => ['present', 'array'],
            'overrides.users' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overrides.customers' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overrides.storage_mb' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
