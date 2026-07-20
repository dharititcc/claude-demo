<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Admin\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Permanently destroy organizations that were soft-deleted long enough ago.
 *
 * This is the ONLY place a tenant's physical database is dropped (the model's
 * delete event no longer does it — see TenancyServiceProvider). Everything here
 * is irreversible, so it is deliberately a command, not an API button: it runs
 * on a retention delay, refuses to run unattended in production without --force,
 * and confirms before touching anything.
 *
 * Order per tenant: drop the database, then hard-delete the central row (its
 * cascade removes the stats and subscription rows), then audit the purge. The
 * audit is written last so only completed purges are recorded — and it captures
 * the org's name, which is about to stop existing anywhere else.
 */
class PurgeTenants extends Command
{
    protected $signature = 'tenants:purge
        {--days=30 : Only purge organizations soft-deleted at least this many days ago}
        {--force : Skip confirmation (required to run in production)}';

    protected $description = 'Permanently delete organizations soft-deleted beyond the retention window';

    public function handle(AdminAudit $audit): int
    {
        $days = max((int) $this->option('days'), 0);
        $cutoff = now()->subDays($days);

        /** @var Collection<int, Tenant> $due */
        $due = Tenant::onlyTrashed()->where('deleted_at', '<=', $cutoff)->get();

        if ($due->isEmpty()) {
            $this->info("Nothing to purge: no organizations were deleted before {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $this->warn("About to PERMANENTLY delete {$due->count()} organization(s) and their databases:");
        $this->table(
            ['Organization', 'Slug', 'Deleted at'],
            $due->map(fn (Tenant $t) => [$t->name, $t->slug, $t->deleted_at?->toDateTimeString()])->all(),
        );
        $this->line('There is no backup step here — take one first if you need it.');

        if (! $this->option('force')) {
            if (app()->environment('production')) {
                $this->error('Refusing to purge unattended in production. Re-run with --force once you are certain.');

                return self::FAILURE;
            }

            if (! $this->confirm('This cannot be undone. Purge them?')) {
                $this->comment('Aborted. Nothing was deleted.');

                return self::SUCCESS;
            }
        }

        $purged = 0;

        foreach ($due as $tenant) {
            try {
                // Drop the tenant's own database first; if this throws, the
                // central row survives so the org can be retried, not orphaned.
                $tenant->database()->manager()->deleteDatabase($tenant);

                // Hard delete the central row — cascades to stats & subscriptions.
                $tenant->forceDelete();

                // Record only after both succeeded. target_label keeps the name
                // readable in the log now that the org itself is gone.
                $audit->organization(null, 'organization.purged', $tenant, "Retention window ({$days}d) elapsed.");

                $this->line("  Purged {$tenant->name}.");
                $purged++;
            } catch (Throwable $e) {
                report($e);
                $this->error("  Failed to purge {$tenant->name}: {$e->getMessage()}");
            }
        }

        $this->info("Purged {$purged} of {$due->count()} organization(s).");

        return self::SUCCESS;
    }
}
