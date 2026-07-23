<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\CustomerContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
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
     * Every field is `sometimes`: this is a partial edit, and an absent key must
     * stay untouched — distinct from a key sent as null, which clears it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(CustomerContact::STATUSES)],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
