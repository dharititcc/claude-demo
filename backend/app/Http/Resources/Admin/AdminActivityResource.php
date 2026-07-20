<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminActivity
 */
class AdminActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            // The acting admin, loaded when available. Null for a system action
            // (e.g. the scheduled purge), which is itself meaningful.
            'admin' => $this->whenLoaded('admin', fn () => $this->admin === null ? null : [
                'id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
            ]),
            'target' => [
                'type' => $this->target_type,
                'id' => $this->target_id,
                // The org name as it was at the time — survives a purge that
                // removed the org itself.
                'label' => $this->target_label,
            ],
            'description' => $this->description,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
