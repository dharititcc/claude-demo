<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Organization-scoped permission, resolved from the active tenant's database.
 */
class Permission extends SpatiePermission
{
    use UsesTenantConnection;
}
