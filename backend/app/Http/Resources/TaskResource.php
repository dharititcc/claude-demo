<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
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
            'status' => $this->status,
            'priority' => $this->priority,

            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project?->id,
                'name' => $this->project?->name,
                'color' => $this->project?->color,
            ]),

            'parent_id' => $this->parent_id,
            'assignee_id' => $this->assignee_id,
            'created_by' => $this->created_by,

            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),

            // Kanban ordering. Exposed so the client can compute an optimistic
            // drop position without waiting for the server round-trip.
            'position' => $this->position,

            'tracked_seconds' => $this->tracked_seconds,
            'estimated_minutes' => $this->estimated_minutes,

            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'time_entries' => TimeEntryResource::collection($this->whenLoaded('timeEntries')),

            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
