<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the central Super Admin audit trail.
 *
 * Append-only: written by AdminAudit, read by the activity endpoint, never
 * updated. Timestamps are disabled because there is only a created_at — an audit
 * row has no meaningful "last modified".
 *
 * @property int $id
 * @property int|null $admin_id
 * @property string $action
 * @property string $target_type
 * @property string|null $target_id
 * @property string|null $target_label
 * @property string|null $description
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property-read User|null $admin
 */
class AdminActivity extends Model
{
    use UsesCentralConnection;

    protected $table = 'admin_activities';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
