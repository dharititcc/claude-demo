<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates list-view query parameters. Sort and status are constrained to
 * allow-lists here as well as in the repository — validation gives the caller a
 * clear 422 instead of silently falling back to a default.
 */
class IndexCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable'],
            'status.*' => [Rule::in(Customer::STATUSES)],
            'owner_id' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:50'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date', 'after_or_equal:created_after'],
            'trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(Customer::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            // Capped so a single request cannot ask for an unbounded page.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Accept `?status=lead,active` as well as `?status[]=lead&status[]=active`.
        if (is_string($this->input('status'))) {
            $this->merge([
                'status' => array_filter(explode(',', $this->input('status'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->only([
            'q', 'status', 'owner_id', 'tag',
            'created_after', 'created_before', 'trashed',
            'sort', 'direction',
        ]);
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 15);
    }
}
