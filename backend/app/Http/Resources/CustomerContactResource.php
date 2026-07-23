<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerContact
 */
class CustomerContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            // Composed server-side so every screen renders a name the same way.
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'notes' => $this->notes,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
