<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validates the calendar window query parameters. The window is bounded here so
 * a malformed range cannot expand into an unbounded number of occurrences.
 */
class IndexEventRequest extends FormRequest
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
            'from' => ['required', 'date'],
            // Bounded window: expansion is per-window, so an unbounded range
            // would let one request generate an unbounded number of occurrences.
            'to' => ['required', 'date', 'after:from', 'before_or_equal:'.Carbon::parse($this->input('from', 'now'))->addYear()->toDateString()],
            'project_id' => ['nullable', 'integer'],
        ];
    }
}
