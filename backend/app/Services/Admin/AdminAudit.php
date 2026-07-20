<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\AdminActivity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records Super Admin actions to the central audit trail.
 *
 * One narrow seam that every mutating admin action calls, so the "did we log
 * it?" question has a single answer instead of being sprinkled through
 * controllers. The organization's name is snapshotted into `target_label` at
 * write time, so the log stays readable even after the org is purged and its
 * row — and its name — are gone.
 */
class AdminAudit
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record an action taken against an organization.
     *
     * @param array<string, mixed> $properties Changed fields, a reason, etc.
     */
    public function organization(
        ?User $admin,
        string $action,
        Tenant $target,
        ?string $description = null,
        array $properties = [],
    ): AdminActivity {
        return AdminActivity::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'target_type' => 'organization',
            'target_id' => $target->id,
            // Snapshot the name now — after a purge, the tenant row is gone and
            // this is the only place the org's name survives.
            'target_label' => $target->name,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            // A system/scheduled action (e.g. the purge command) has no request.
            'ip_address' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }
}
