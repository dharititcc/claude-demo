<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Binds a model to whichever connection is currently the default — which, once
 * stancl/tenancy has bootstrapped a tenant, is that tenant's database.
 *
 * Why this is required (subtle):
 * Laravel's `newRelatedInstance()` copies the *parent* model's connection onto
 * a related model that has no explicit connection of its own. Because `User` is
 * pinned to the central database, Spatie's Role/Permission models would silently
 * inherit the central connection and look for `roles` in the central DB.
 * Returning a connection name here is non-null, so Laravel leaves it alone.
 */
trait UsesTenantConnection
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }
}
