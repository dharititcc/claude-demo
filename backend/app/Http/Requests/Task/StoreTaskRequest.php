<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
        $central = config('tenancy.database.central_connection');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'status' => ['sometimes', Rule::in(Task::STATUSES)],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],

            // Users are central; an unqualified rule would query the tenant DB.
            'assignee_id' => ['nullable', 'integer', "exists:{$central}.users,id"],

            'due_on' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'labels' => ['sometimes', 'array', 'max:20'],
            'labels.*' => ['string', 'max:50'],
        ];
    }
}
