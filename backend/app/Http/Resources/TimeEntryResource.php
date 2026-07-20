<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'user_id' => $this->user_id,
            'description' => $this->description,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'running' => $this->isRunning(),

            // elapsed, not the stored column: a running timer has seconds = 0
            // in the database, so a UI reading that would show 00:00 while the
            // clock is visibly ticking.
            'seconds' => $this->elapsedSeconds(),
            'billable' => $this->billable,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
