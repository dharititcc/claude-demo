<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class OrganizationResource extends JsonResource
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
            'logo' => $this->logo,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'language' => $this->language,
            'status' => $this->status,
            'on_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Present when the org was loaded through the user's membership.
            'is_owner' => $this->whenPivotLoaded('organization_user', fn () => (bool) $this->pivot->is_owner),
        ];
    }
}
