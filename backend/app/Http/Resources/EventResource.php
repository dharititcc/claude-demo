<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A raw event row (not an expanded occurrence — the calendar index returns
 * plain occurrence arrays from the expander).
 *
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'type' => $this->type,
            'color' => $this->color,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'all_day' => $this->all_day,

            'recurrence' => $this->recurrence_frequency === null ? null : [
                'frequency' => $this->recurrence_frequency,
                'interval' => $this->recurrence_interval,
                'by_day' => $this->recurrence_by_day,
                'until' => $this->recurrence_until?->toIso8601String(),
                'count' => $this->recurrence_count,
            ],

            'project_id' => $this->project_id,
            'created_by' => $this->created_by,

            'attendees' => EventAttendeeResource::collection($this->whenLoaded('attendees')),
            'reminders' => $this->whenLoaded('reminders', fn () => $this->reminders->map(fn ($r) => [
                'id' => $r->id,
                'minutes_before' => $r->minutes_before,
                'channel' => $r->channel,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
