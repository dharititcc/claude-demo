<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\CustomerContact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(CustomerContact::STATUSES)],
            // Promoting is a write to the other contacts too, so the service
            // handles it; this only expresses intent.
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
