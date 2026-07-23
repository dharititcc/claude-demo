<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoice;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
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
     * Note what is absent: number, status and every total. Those are issued and
     * computed by InvoiceService — a client that could post its own total could
     * invoice any amount it liked.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'issue_date' => ['nullable', 'date'],
            // An invoice due before it was issued is a data-entry slip worth
            // catching here rather than at the customer.
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
