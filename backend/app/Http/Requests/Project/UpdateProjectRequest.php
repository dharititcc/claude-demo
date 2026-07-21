<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
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
        $central = config('tenancy.database.central_connection');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],

            // Qualified with the central connection: users live there, and an
            // unqualified rule would look for the table in the tenant database.
            'owner_id' => ['nullable', 'integer', "exists:{$central}.users,id"],

            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
