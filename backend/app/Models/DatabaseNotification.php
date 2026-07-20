<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Notifications\DatabaseNotification as BaseNotification;

/**
 * In-app notification, pinned to the active tenant's database.
 *
 * Same reasoning as Role and the Activity model: the `notifications` table lives
 * in each tenant database, and `$user->notifications()` would otherwise inherit
 * the User's central connection (Laravel copies the parent's connection onto a
 * related model that declares none), querying a central table that does not
 * exist.
 */
class DatabaseNotification extends BaseNotification
{
    use UsesTenantConnection;
}
