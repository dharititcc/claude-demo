<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'color' => $this->color,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),
            'customer_id' => $this->customer_id,
            'owner_id' => $this->owner_id,

            'starts_on' => $this->starts_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),

            'budget' => $this->budget === null ? null : (float) $this->budget,

            // Present only when the caller loaded the counts, so a detail view
            // does not silently trigger a query per project in a list.
            'tasks_count' => $this->whenCounted('tasks'),
            'completed_tasks_count' => $this->whenCounted('completed_tasks_count'),
            // Only computed when the count was loaded, so a list view never
            // triggers a query per project. whenCounted handles the presence
            // check; progress() then reads the loaded value.
            'progress' => $this->whenCounted('tasks', fn () => $this->progress()),

            'members' => ProjectMemberResource::collection($this->whenLoaded('members')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),

            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
