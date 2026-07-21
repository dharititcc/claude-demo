<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use App\Enums\Role as RoleEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
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
            'role' => ['required', Rule::in($this->assignableRoles())],
        ];
    }

    /**
     * Roles a caller may hand out. Owner is included only for existing owners:
     * an admin must not be able to promote anyone (including themselves) to the
     * role that controls billing and deletion.
     *
     * @return array<int, string>
     */
    private function assignableRoles(): array
    {
        $values = RoleEnum::values();

        if (request()->user()->is_super_admin || request()->user()->hasRole(RoleEnum::Owner->value)) {
            return $values;
        }

        return array_values(array_diff($values, [RoleEnum::Owner->value]));
    }
}
