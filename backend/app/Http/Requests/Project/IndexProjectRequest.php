<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates list-view query parameters. Status and sort are constrained to
 * allow-lists here so the caller gets a clear 422 instead of a silent fallback.
 */
class IndexProjectRequest extends FormRequest
{
    /**
     * Authorization is enforced in the controller via $this->authorize() and
     * ProjectPolicy — this Form Request only shapes input.
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
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'customer_id' => ['nullable', 'integer'],
            'overdue' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(Project::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
