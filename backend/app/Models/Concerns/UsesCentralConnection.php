<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Pins a model to the central database connection.
 *
 * Under database-per-tenant, stancl/tenancy swaps the *default* connection to
 * the active tenant's database. Models that live in the central database
 * (users, tokens, tenants, login history) must ignore that swap, otherwise
 * queries would hit a tenant DB where those tables don't exist.
 */
trait UsesCentralConnection
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
