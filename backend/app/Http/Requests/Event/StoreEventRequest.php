<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Event::TYPES)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'all_day' => ['sometimes', 'boolean'],

            'recurrence_frequency' => ['nullable', Rule::in(Event::FREQUENCIES)],
            'recurrence_interval' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'recurrence_by_day' => ['nullable', 'array'],
            'recurrence_by_day.*' => [Rule::in(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'])],
            'recurrence_until' => ['nullable', 'date', 'after:starts_at'],
            'recurrence_count' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ];
    }
}
