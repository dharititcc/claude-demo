<?php

declare(strict_types=1);

namespace App\Listeners;

use Spatie\Permission\PermissionRegistrar;

/**
 * Spatie caches the resolved permission set in-memory/in-store. Because roles
 * live in per-tenant databases, that cache must be dropped whenever the tenant
 * context changes — otherwise organization A's permissions could be evaluated
 * while serving organization B.
 */
class ForgetCachedPermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(object $event): void
    {
        $this->registrar->forgetCachedPermissions();
    }
}
