<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Jobs\RefreshTenantStats;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\File;
use App\Models\OrganizationStat;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use Throwable;

/**
 * Recomputes the central `organization_stats` rollup from each tenant database.
 *
 * This is the one place that pays the cross-database cost — deliberately, in the
 * background — so that every Super Admin read stays a single central query. The
 * counts are gathered *inside* the tenant (where those tables live) and written
 * *to* the central OrganizationStat, which is connection-pinned, so the write
 * lands centrally even while a tenant connection is active.
 */
class RefreshOrganizationStats
{
    /**
     * Refresh one organization's rollup.
     *
     * Counts reflect live (non-soft-deleted) records: a deleted project should
     * not inflate the org's project count. Storage is the one place that could
     * diverge — soft-deleted files still occupy disk until purged — but counting
     * live files keeps the number consistent with everything beside it; revisit
     * if storage ever drives billing.
     */
    public function forTenant(Tenant $tenant): OrganizationStat
    {
        // Everything in this closure runs against the tenant's own database.
        $counts = $tenant->run(fn (): array => [
            'customers_count' => Customer::count(),
            'projects_count' => Project::count(),
            'tasks_count' => Task::count(),
            'files_count' => File::count(),
            'storage_bytes' => (int) File::sum('size') + (int) Attachment::sum('size'),
            // The most recent audit entry is a truthful "last used" signal; null
            // for an organization that has done nothing yet.
            'last_activity_at' => Activity::max('created_at'),
        ]);

        $counts['refreshed_at'] = now();

        // OrganizationStat pins the central connection, so this upsert lands in
        // the central database even though a tenant connection is still active.
        return OrganizationStat::updateOrCreate(['tenant_id' => $tenant->id], $counts);
    }

    /**
     * Refresh every (non-trashed) organization.
     *
     * @param bool $sync Run inline; otherwise queue one job per tenant, which
     *                   is how the scheduled run scales to thousands of orgs
     *                   without one long-running process.
     * @return int Number of organizations processed (dispatched or refreshed).
     */
    public function refreshAll(bool $sync = false): int
    {
        $processed = 0;

        // cursor() streams tenants one at a time rather than hydrating thousands
        // of models at once.
        Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$processed, $sync): void {
            if ($sync) {
                try {
                    $this->forTenant($tenant);
                } catch (Throwable $e) {
                    // One unreachable tenant database (mid-provision, or pending
                    // purge) must not abort the whole run. Skip it and keep its
                    // previous stats rather than overwriting them with zeros.
                    report($e);

                    return;
                }
            } else {
                RefreshTenantStats::dispatch($tenant->id);
            }

            $processed++;
        });

        return $processed;
    }
}
