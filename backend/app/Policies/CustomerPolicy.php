<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

/**
 * Authorization for the Customers module.
 *
 * Permissions are read from the *active tenant's* database, so the same user
 * may manage customers in one organization and be read-only in another. Super
 * admins bypass every check via the Gate::before hook in AppServiceProvider.
 *
 * Auto-discovered by Laravel via the App\Models\Customer → App\Policies\CustomerPolicy
 * naming convention.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersCreate->value);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersDelete->value);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->can(Permission::CustomersDelete->value);
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        // Permanent deletion is destructive and irreversible: owners only.
        return $user->hasRole('owner');
    }

    public function import(User $user): bool
    {
        return $user->can(Permission::CustomersImport->value);
    }

    public function export(User $user): bool
    {
        return $user->can(Permission::CustomersExport->value);
    }
}
