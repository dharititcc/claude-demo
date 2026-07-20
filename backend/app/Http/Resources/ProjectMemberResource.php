<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectMember
 */
class ProjectMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Resolved against the central users table by the caller — this row
            // only holds the id, since the FK cannot cross databases.
            'user_id' => $this->user_id,
            'role' => $this->role,
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
