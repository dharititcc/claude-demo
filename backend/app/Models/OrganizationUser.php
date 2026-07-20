<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Membership of a user in an organization.
 *
 * Modelled explicitly rather than as an anonymous pivot so the `is_owner` flag
 * is typed, and so ownership rules have somewhere to live as they grow.
 *
 * @property int $user_id
 * @property string $tenant_id
 * @property bool $is_owner
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationUser extends Pivot
{
    use UsesCentralConnection;

    protected $table = 'organization_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
        ];
    }
}
