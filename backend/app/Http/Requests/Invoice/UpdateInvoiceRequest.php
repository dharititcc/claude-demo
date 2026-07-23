<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoice;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
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
     * Partial edit. Whether the figures may change at all depends on the
     * invoice's status, which is a rule about the record rather than about the
     * payload — InvoiceService enforces it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'issue_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date', 'after_or_equal:issue_date'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'items' => ['sometimes', 'array', 'min:1', 'max:200'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01', 'max:99999.99'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
