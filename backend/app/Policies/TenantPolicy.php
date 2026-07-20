<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

/**
 * Authorization for organization-level actions (settings, team, billing).
 *
 * Named TenantPolicy so Laravel's App\Models\Tenant → App\Policies\TenantPolicy
 * convention discovers it. Permissions resolve from the active tenant database,
 * so the same user can administer one organization and merely view another.
 */
class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::SettingsView->value);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::SettingsUpdate->value);
    }

    public function viewTeam(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TeamView->value);
    }

    public function inviteTeam(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TeamInvite->value);
    }

    /**
     * Changing roles and removing people is a higher bar than inviting: a
     * manager may grow the team but not restructure or evict it.
     */
    public function manageTeam(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::TeamUpdate->value)
            && $user->can(Permission::TeamRemove->value);
    }

    public function viewAudit(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function viewBilling(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::BillingView->value);
    }

    public function manageBilling(User $user, Tenant $tenant): bool
    {
        return $user->can(Permission::BillingManage->value);
    }

    /**
     * Deleting an organization destroys its database. Owners only — never an
     * admin, and never via a permission that could be granted to a custom role.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->hasRole(Role::Owner->value);
    }
}
