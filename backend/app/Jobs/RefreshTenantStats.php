<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Admin\RefreshOrganizationStats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes one organization's central stats rollup.
 *
 * The scheduled refresh dispatches one of these per tenant instead of walking
 * every organization in a single process — thousands of orgs then spread across
 * the queue workers rather than risking one long, timeout-prone run.
 *
 * It carries the tenant *id*, not the model: the id is small to serialize, and
 * re-loading gives the job the tenant's current state (it may have been deleted
 * between dispatch and execution).
 */
class RefreshTenantStats implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(RefreshOrganizationStats $stats): void
    {
        $tenant = Tenant::find($this->tenantId);

        // Deleted between dispatch and execution — nothing to roll up, and the
        // cascade on organization_stats has already removed any stale row.
        if ($tenant === null) {
            return;
        }

        $stats->forTenant($tenant);
    }
}
