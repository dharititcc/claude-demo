<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoice;

use App\Models\Invoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInvoiceRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            // 'overdue' is accepted alongside the stored statuses because it is
            // what a user actually wants to filter by, even though it is derived.
            'status' => ['nullable', Rule::in([...Invoice::STATUSES, 'overdue'])],
            'customer_id' => ['nullable', 'integer'],
            'due_after' => ['nullable', 'date'],
            'due_before' => ['nullable', 'date', 'after_or_equal:due_after'],
            'sort' => ['nullable', Rule::in(Invoice::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            // Capped so a single request cannot ask for an unbounded page.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
