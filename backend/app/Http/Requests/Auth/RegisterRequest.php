<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // `rfc` only: a DNS lookup would add latency and an external
            // dependency to signup, and the verification email already proves
            // the address is deliverable.
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'organization_name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'organization_name.required' => 'Please provide a name for your organization.',
        ];
    }
}
