<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invite people to an organization, and accept those invitations.
 */
class InvitationService
{
    /** How long an invitation stays valid. */
    private const TTL_DAYS = 7;

    public function __construct(private readonly OrganizationService $organizations) {}

    /**
     * Create (or replace) an invitation and email the link.
     *
     * Returns the plaintext token, which exists only in this response and the
     * email — the database keeps a hash.
     *
     * @return array{0: Invitation, 1: string}
     */
    public function invite(Tenant $tenant, string $email, Role $role, User $inviter): array
    {
        $email = mb_strtolower(trim($email));

        if ($this->isAlreadyMember($tenant, $email)) {
            throw ValidationException::withMessages([
                'email' => __('That person is already a member of this organization.'),
            ]);
        }

        $token = Str::random(48);

        // updateOrCreate: re-inviting the same address refreshes the token and
        // expiry rather than leaving several live invitations for one person.
        $invitation = Invitation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'role' => $role->value,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ],
        );

        // Re-inviting revives a previously consumed invitation. `accepted_at` is
        // state the application controls — it marks the invitation as spent — so
        // it is deliberately not mass-assignable and is set explicitly here.
        $invitation->forceFill(['accepted_at' => null])->save();

        $invitation->notify(new OrganizationInvitation($invitation, $tenant, $inviter, $token));

        return [$invitation, $token];
    }

    /**
     * Resolve a plaintext token to a pending invitation.
     *
     * @throws ValidationException
     */
    public function resolve(string $token): Invitation
    {
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hashToken($token))
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'token' => __('This invitation is invalid, expired, or has already been used.'),
            ]);
        }

        return $invitation;
    }

    /**
     * Accept an invitation on behalf of a signed-in user.
     *
     * @throws ValidationException
     */
    public function accept(string $token, User $user): Tenant
    {
        $invitation = $this->resolve($token);

        // The invitation is bound to an address. Without this check, anyone
        // holding the link could join as themselves.
        if (mb_strtolower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages([
                'token' => __('This invitation was issued to a different email address.'),
            ]);
        }

        $tenant = Tenant::findOrFail($invitation->tenant_id);

        $this->organizations->addMember($tenant, $user, $invitation->roleEnum());

        $invitation->forceFill(['accepted_at' => now()])->save();

        return $tenant;
    }

    public function revoke(Tenant $tenant, int $invitationId): void
    {
        Invitation::where('tenant_id', $tenant->id)->whereKey($invitationId)->delete();
    }

    private function isAlreadyMember(Tenant $tenant, string $email): bool
    {
        $user = User::where('email', $email)->first();

        return $user !== null && $user->belongsToOrganization($tenant->id);
    }
}
