<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CustomerContact;
use App\Models\User;

/**
 * Authorization for customer contacts.
 *
 * Contacts are part of a customer record rather than a resource anyone holds
 * separate rights over, so they ride the customers.* permissions: whoever may
 * edit a customer may manage the people at that company. Introducing
 * contacts.* permissions would mean a role could be granted one without the
 * other, which has no meaning here and only adds a way to misconfigure a role.
 *
 * Permissions are read from the *active tenant's* database, so the same user may
 * manage contacts in one organization and be read-only in another. Super admins
 * bypass every check via the Gate::before hook in AppServiceProvider.
 *
 * Auto-discovered via the App\Models\CustomerContact → App\Policies\CustomerContactPolicy
 * naming convention.
 */
class CustomerContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function view(User $user, CustomerContact $contact): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    public function update(User $user, CustomerContact $contact): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    /**
     * Deleting a contact edits the customer record rather than destroying a
     * customer, so it needs customers.update — not customers.delete, which
     * governs removing the company itself.
     */
    public function delete(User $user, CustomerContact $contact): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }

    public function restore(User $user, CustomerContact $contact): bool
    {
        return $user->can(Permission::CustomersUpdate->value);
    }
}
