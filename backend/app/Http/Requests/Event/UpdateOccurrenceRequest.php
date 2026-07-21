<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOccurrenceRequest extends FormRequest
{
    /**
     * Authorization is enforced in the controller via $this->authorize() and
     * EventPolicy — this Form Request only shapes input.
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
            'original_starts_at' => ['required', 'date'],
            'cancel' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
