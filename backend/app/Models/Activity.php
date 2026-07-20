<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit-log entry, pinned to the active tenant's database.
 *
 * Like Role and Permission, this must follow the tenancy connection swap: audit
 * entries are written and read inside tenant context, and the `activity_log`
 * table exists only in tenant databases. Without the trait the default Spatie
 * model could resolve against central, where the table is absent.
 */
class Activity extends SpatieActivity
{
    use UsesTenantConnection;
}
