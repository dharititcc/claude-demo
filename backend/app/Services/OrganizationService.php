<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates and tears down organizations (tenants).
 *
 * Creating a Tenant fires stancl's TenantCreated event, whose job pipeline
 * provisions the tenant database, runs the tenant migrations, and seeds the
 * roles/permissions. Role assignment must therefore happen *inside* that
 * tenant's context, which is why we initialize tenancy below.
 */
class OrganizationService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(User $owner, array $attributes): Tenant
    {
        $tenant = Tenant::create([
            'name' => $attributes['name'],
            'slug' => $this->uniqueSlug($attributes['slug'] ?? $attributes['name']),
            'timezone' => $attributes['timezone'] ?? 'UTC',
            'currency' => $attributes['currency'] ?? 'USD',
            'language' => $attributes['language'] ?? 'en',
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->addMember($tenant, $owner, Role::Owner, isOwner: true);

        return $tenant;
    }

    /**
     * Attach a central user to an organization and grant them a role inside
     * that organization's database.
     */
    public function addMember(Tenant $tenant, User $user, Role $role, bool $isOwner = false): void
    {
        $tenant->members()->syncWithoutDetaching([
            $user->id => ['is_owner' => $isOwner],
        ]);

        $tenant->run(function () use ($user, $role) {
            // Relations are cached per-instance; drop them so the role lookup
            // resolves against this tenant's database rather than a prior one.
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->syncRoles([$role->value]);
        });
    }

    public function removeMember(Tenant $tenant, User $user): void
    {
        $tenant->members()->detach($user->id);

        $tenant->run(function () use ($user) {
            $user->unsetRelation('roles');
            $user->syncRoles([]);
        });
    }

    /**
     * Resolve the caller's role within an organization.
     */
    public function roleFor(Tenant $tenant, User $user): ?string
    {
        return $tenant->run(function () use ($user) {
            $user->unsetRelation('roles');

            return $user->roles->first()?->name;
        });
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'org';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
