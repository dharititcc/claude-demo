<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as seen from inside an organization: their central identity plus the
 * role they hold *here*. The same person may appear with a different role in
 * another organization.
 *
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            // A transient attribute attached by MemberController after resolving
            // roles from the tenant database — read via getAttribute() because it
            // is not a column on User and never will be.
            'role' => $this->resource->getAttribute('organization_role'),

            // Present because the user was loaded through tenant()->members().
            // `??` already covers a null pivot, so `?->` would be redundant here.
            'is_owner' => (bool) ($this->pivot->is_owner ?? false),
            'joined_at' => $this->pivot?->created_at?->toIso8601String(),
        ];
    }
}
