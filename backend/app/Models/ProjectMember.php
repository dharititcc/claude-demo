<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership of a central user in a tenant-side project.
 *
 * Modelled as its own table rather than a plain pivot because `user_id` points
 * at the central database and cannot be an Eloquent relation from here — a
 * belongsTo would try to read `users` from the tenant database.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectMember extends Model
{
    use UsesTenantConnection;

    /** @var list<string> */
    protected $fillable = ['project_id', 'user_id', 'role'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
