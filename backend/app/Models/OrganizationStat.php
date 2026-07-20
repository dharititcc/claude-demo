<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A denormalised rollup of one organization's tenant-database counts, held in
 * the central database so the Super Admin screens never fan out per org.
 *
 * Written only by RefreshOrganizationStats; read everywhere else.
 *
 * @property string $tenant_id
 * @property int $customers_count
 * @property int $projects_count
 * @property int $tasks_count
 * @property int $files_count
 * @property int $storage_bytes
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $refreshed_at
 * @property-read Tenant $tenant
 */
class OrganizationStat extends Model
{
    use UsesCentralConnection;

    protected $table = 'organization_stats';

    // The tenant id is a UUID string, supplied explicitly, not auto-incremented.
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'customers_count' => 'integer',
            'projects_count' => 'integer',
            'tasks_count' => 'integer',
            'files_count' => 'integer',
            'storage_bytes' => 'integer',
            'last_activity_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * Storage rounded up to whole megabytes, for display.
     */
    public function storageMb(): int
    {
        return (int) ceil($this->storage_bytes / 1_048_576);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
