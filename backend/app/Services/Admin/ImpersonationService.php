<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Starts and ends Super Admin impersonation sessions.
 *
 * Impersonation issues a Sanctum token on the *target* user — so every
 * permission check downstream evaluates as that user, with no special-casing —
 * but the token is tagged with the real actor and confined to one organization
 * and a short lifetime. The confinement and expiry are what make this safe to
 * hand out: an impersonation token cannot reach the admin surface (its user is
 * not a super admin), cannot leave the chosen org (the tenant middleware checks
 * the tag), and dies on its own within the hour.
 */
class ImpersonationService
{
    /**
     * How long an impersonation token lives. Short on purpose: it is enough to
     * reproduce a problem, not enough to be a standing back door.
     */
    private const TTL_MINUTES = 60;

    public function __construct(private readonly AdminAudit $audit) {}

    /**
     * Begin impersonating a member of an organization.
     *
     * @param int|null $targetUserId A specific member; defaults to an owner.
     * @return array{token: string, expires_at: Carbon, user: User}
     *
     * @throws ValidationException
     */
    public function start(User $admin, Tenant $organization, ?int $targetUserId = null): array
    {
        $target = $this->resolveTarget($organization, $targetUserId);

        $this->guard($admin, $target, $organization);

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        // A normal Sanctum token on the target user — full abilities, because the
        // point is to be that user; their real capabilities are still gated by
        // their per-org role. Expiry is enforced by Sanctum itself.
        $newToken = $target->createToken("impersonation:{$organization->slug}", ['*'], $expiresAt);

        /** @var PersonalAccessToken $token */
        $token = $newToken->accessToken;
        $token->forceFill([
            'impersonator_id' => $admin->id,
            'impersonated_tenant_id' => $organization->id,
        ])->save();

        $this->audit->organization(
            $admin,
            'organization.impersonation.started',
            $organization,
            "Impersonating {$target->email}.",
            ['target_user_id' => $target->id, 'target_email' => $target->email, 'expires_at' => $expiresAt->toIso8601String()],
        );

        return [
            'token' => $newToken->plainTextToken,
            'expires_at' => $expiresAt,
            'user' => $target,
        ];
    }

    /**
     * End an impersonation session by revoking its token.
     *
     * The actor here is the impersonated user (they hold the token), so the real
     * admin identity and the org come off the token itself, not the request.
     */
    public function stop(PersonalAccessToken $token): void
    {
        $admin = $token->impersonator;
        $organization = $token->impersonatedTenant;

        if ($admin !== null && $organization !== null) {
            $this->audit->organization(
                $admin,
                'organization.impersonation.stopped',
                $organization,
                'Impersonation ended.',
            );
        }

        // Revoke the token so it cannot be reused after the session ends, even
        // before its expiry would have killed it.
        $token->delete();
    }

    /**
     * Pick the user to impersonate: the one named, or an owner by default.
     *
     * @throws ValidationException
     */
    private function resolveTarget(Tenant $organization, ?int $targetUserId): User
    {
        if ($targetUserId !== null) {
            /** @var User|null $member */
            $member = $organization->members()->whereKey($targetUserId)->first();

            if ($member === null) {
                throw ValidationException::withMessages([
                    'user_id' => __('That user is not a member of this organization.'),
                ]);
            }

            return $member;
        }

        /** @var User|null $owner */
        $owner = $organization->owners()->first();

        if ($owner === null) {
            throw ValidationException::withMessages([
                'user_id' => __('This organization has no owner to impersonate; name a member explicitly.'),
            ]);
        }

        return $owner;
    }

    /**
     * The rules that make impersonation safe to expose.
     *
     * @throws ValidationException
     */
    private function guard(User $admin, User $target, Tenant $organization): void
    {
        // Never impersonate another platform admin — that would be a lateral
        // move into someone else's full privileges, the exact thing this feature
        // must not enable.
        if ($target->is_super_admin) {
            throw ValidationException::withMessages([
                'user_id' => __('A super admin cannot be impersonated.'),
            ]);
        }

        // Impersonating yourself is a no-op that would only muddy the audit log.
        if ($target->id === $admin->id) {
            throw ValidationException::withMessages([
                'user_id' => __('You cannot impersonate yourself.'),
            ]);
        }

        // Defence in depth: resolveTarget already guarantees membership, but a
        // named user must belong to this org.
        if (! $target->belongsToOrganization($organization->id)) {
            throw ValidationException::withMessages([
                'user_id' => __('That user is not a member of this organization.'),
            ]);
        }
    }
}
