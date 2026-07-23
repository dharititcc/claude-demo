<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;

/**
 * Authorization for customer invoices.
 *
 * Distinct from billing.*, which governs the organization's own subscription:
 * someone who raises invoices for customers has no business reading what the
 * organization pays us, and vice versa.
 *
 * Permissions are read from the *active tenant's* database, so the same user may
 * raise invoices in one organization and be read-only in another. Super admins
 * bypass every check via the Gate::before hook in AppServiceProvider.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::InvoicesView->value);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::InvoicesCreate->value);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesUpdate->value);
    }

    /**
     * Voiding cancels an issued financial record, so it sits with delete rather
     * than update: it is not an edit, it is a withdrawal.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesDelete->value);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesDelete->value);
    }
}
