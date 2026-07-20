<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Organization-scoped role. Lives in the active tenant's database, so the same
 * user can be an owner in one organization and a viewer in another.
 */
class Role extends SpatieRole
{
    use UsesTenantConnection;
}
