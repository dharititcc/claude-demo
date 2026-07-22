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

            // Owner must be a member of this organization: membership is enforced
            // against the central pivot, so a user id from another tenant — which
            // merely exists in the central users table — is rejected.
            'owner_id' => ['nullable', 'integer', Rule::exists("{$central}.organization_user", 'user_id')->where('tenant_id', tenant()->id)],

            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
