<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Admin\RefreshOrganizationStats as RefreshService;
use Illuminate\Console\Command;

/**
 * Refresh the central organization_stats rollup that powers the Super Admin
 * dashboard and organization list.
 *
 * Scheduled hourly (see routes/console.php). Run by hand after a bulk import, or
 * for one organization while debugging.
 */
class RefreshOrganizationStats extends Command
{
    protected $signature = 'app:refresh-org-stats
        {tenant? : Refresh only this organization (id or slug); omit for all}
        {--sync : Process inline instead of queuing one job per organization}';

    protected $description = 'Recompute per-organization statistics into the central rollup';

    public function handle(RefreshService $service): int
    {
        $identifier = $this->argument('tenant');

        if ($identifier !== null) {
            $tenant = Tenant::where('id', $identifier)->orWhere('slug', $identifier)->first();

            if ($tenant === null) {
                $this->error("No organization matches: {$identifier}");

                return self::FAILURE;
            }

            $service->forTenant($tenant);
            $this->info("Refreshed stats for {$tenant->name}.");

            return self::SUCCESS;
        }

        // Queue by default (the scheduled run relies on this to scale); --sync
        // runs inline for a manual run where the operator wants to watch it.
        $sync = (bool) $this->option('sync');

        $processed = $service->refreshAll($sync);

        $this->info($sync
            ? "Refreshed stats for {$processed} organization(s)."
            : "Queued a refresh for {$processed} organization(s).");

        return self::SUCCESS;
    }
}
