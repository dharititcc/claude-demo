<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read and lifecycle operations over every organization, for the Super Admin.
 *
 * Everything here stays in the central database on purpose. Counts that live in
 * tenant databases (projects, tasks, storage) are deliberately absent: fanning
 * out one query per organization across a list of thousands does not scale, so
 * those arrive in Phase 2 from a denormalised `organization_stats` table this
 * service will read like any other central column. User counts are the
 * exception — membership is the central `organization_user` pivot, so
 * `withCount('members')` is a single join, not a fan-out.
 */
class OrganizationAdminService
{
    /** Columns a client may sort by. Anything else is refused, not trusted. */
    private const SORTABLE = ['name', 'status', 'created_at', 'members_count'];

    private const MAX_PER_PAGE = 100;

    /**
     * A filtered, sorted, paginated page of organizations for the list screen.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()
            // Eager-load everything the list row renders, so a page of 50 orgs
            // is a constant handful of queries rather than 50×N. `subscriptions`
            // is Cashier's — the resource reads it via subscription(), and with
            // preventLazyLoading on, omitting it here is a hard error, not a
            // silent N+1.
            ->with(['plan', 'owners', 'subscriptions', 'stats'])
            ->withCount('members');

        $this->applyTrashed($query, $filters['trashed'] ?? null);
        $this->applySearch($query, $filters['search'] ?? null);
        $this->applyStatus($query, $filters['status'] ?? null);
        $this->applyPlan($query, $filters['plan'] ?? null);
        $this->applyDateRange($query, $filters['from'] ?? null, $filters['to'] ?? null);
        $this->applySort($query, $filters['sort'] ?? '-created_at');

        $perPage = min((int) ($filters['per_page'] ?? 25), self::MAX_PER_PAGE);

        return $query->paginate(max($perPage, 1))->withQueryString();
    }

    /**
     * Load one organization with everything the detail screen shows.
     */
    public function detail(Tenant $tenant): Tenant
    {
        return $tenant->loadMissing(['plan', 'owners', 'subscriptions', 'stats'])->loadCount('members');
    }

    /**
     * Platform-wide dashboard counters.
     *
     * Only the centrally cheap figures live here. Cross-tenant totals (projects,
     * storage) are Phase 2 and are returned as null rather than faked, so a
     * caller can tell "not built yet" from "genuinely zero".
     *
     * @return array<string, int|null>
     */
    public function stats(): array
    {
        // One grouped query for the status breakdown instead of five COUNTs.
        $byStatus = Tenant::query()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) $byStatus->get('active', 0),
            'trial' => (int) $byStatus->get('trial', 0),
            'suspended' => (int) $byStatus->get('suspended', 0),
            'cancelled' => (int) $byStatus->get('cancelled', 0),

            // Derived, not stored: a trial whose clock ran out but which never
            // converted. See Tenant::isTrialExpired().
            'expired' => Tenant::query()
                ->where('status', 'trial')
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', now())
                ->count(),

            // Paid = holds a subscription that has not ended. whereHas keeps this
            // a single central query against Cashier's subscriptions table.
            'paid' => Tenant::query()
                ->whereHas('subscriptions', fn (Builder $q) => $q
                    ->where('stripe_status', 'active')
                    ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())))
                ->count(),

            // Distinct people across the platform — a user in three orgs counts
            // once. Central pivot, so no tenant-database fan-out.
            'total_users' => (int) DB::table('organization_user')->distinct()->count('user_id'),

            // Summed from the rollup in one central query — no fan-out. Null
            // until the first refresh populates any row, so an empty rollup reads
            // as "not yet measured" rather than a fabricated zero.
            ...$this->rollupTotals(),
        ];
    }

    /**
     * Platform-wide totals summed from the rollup, or null when no organization
     * has been rolled up yet.
     *
     * @return array{total_projects: int|null, total_tasks: int|null, total_storage_mb: int|null}
     */
    private function rollupTotals(): array
    {
        // The query builder (not the model) on purpose: this reads aliased
        // aggregates, not OrganizationStat's typed columns.
        $row = DB::table('organization_stats')
            ->selectRaw('COUNT(*) as rows_present')
            ->selectRaw('COALESCE(SUM(projects_count), 0) as projects')
            ->selectRaw('COALESCE(SUM(tasks_count), 0) as tasks')
            ->selectRaw('COALESCE(SUM(storage_bytes), 0) as storage')
            ->first();

        // No rows means the rollup has never run — report null, not zero.
        if ($row === null || (int) $row->rows_present === 0) {
            return ['total_projects' => null, 'total_tasks' => null, 'total_storage_mb' => null];
        }

        return [
            'total_projects' => (int) $row->projects,
            'total_tasks' => (int) $row->tasks,
            'total_storage_mb' => (int) ceil(((int) $row->storage) / 1_048_576),
        ];
    }

    /**
     * Soft-delete an organization.
     *
     * The central row is trashed, which cuts access at once — the tenant
     * middleware resolves orgs by the default (non-trashed) scope, so a deleted
     * org can no longer be entered. The physical database is deliberately KEPT:
     * deletion is reversible via restore() until the retention window lapses and
     * `tenants:purge` drops it for good. (See TenancyServiceProvider for why the
     * database is not dropped on this event.)
     */
    public function softDelete(Tenant $tenant): Tenant
    {
        $tenant->delete();

        return $tenant;
    }

    /**
     * Restore a soft-deleted organization, bringing its members back online. The
     * database was never dropped, so nothing needs re-provisioning.
     */
    public function restore(Tenant $tenant): Tenant
    {
        $tenant->restore();

        return $tenant;
    }

    /**
     * Replace an organization's limit overrides.
     *
     * PUT-style: the map given becomes the complete set of overrides. A key that
     * is present overrides the plan (an integer ceiling, or null for unlimited);
     * a key that is absent is cleared and falls back to the plan. An empty map
     * stores null — no overrides at all — rather than an empty object.
     *
     * @param array<string, int|null> $overrides
     */
    public function setLimits(Tenant $tenant, array $overrides): Tenant
    {
        // Only these keys mean anything to UsageService; drop the rest rather
        // than persist noise a future reader would have to puzzle over.
        $allowed = array_intersect_key($overrides, array_flip(['users', 'customers', 'storage_mb']));

        $tenant->forceFill(['limit_overrides' => $allowed === [] ? null : $allowed])->save();

        return $tenant;
    }

    /**
     * Suspend an organization. Access is cut immediately: the tenant middleware
     * already refuses a suspended org on the very next request, so no token
     * needs to expire first.
     */
    public function suspend(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['status' => 'suspended'])->save();

        return $tenant;
    }

    /**
     * Reactivate a suspended organization.
     *
     * Restores to `active`, never to `trial`: winding an org back onto a trial
     * it may have already used would hand out a second free run.
     */
    public function activate(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['status' => 'active'])->save();

        return $tenant;
    }

    /**
     * Update an organization's profile. The slug is intentionally not editable —
     * it is the identifier clients send in `X-Organization`, and changing it
     * would break every integration pointed at the org. Status changes go
     * through suspend()/activate(), not a raw field write.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->fill(array_intersect_key($attributes, array_flip([
            'name', 'phone', 'timezone', 'currency', 'language',
        ])))->save();

        return $tenant;
    }

    /**
     * Control whether soft-deleted organizations appear.
     *
     *   (default) — only live orgs
     *   with       — live and deleted together
     *   only       — only deleted (the "trash" view)
     *
     * @param Builder<Tenant> $query
     */
    private function applyTrashed(Builder $query, ?string $trashed): void
    {
        match ($trashed) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };
    }

    /**
     * @param Builder<Tenant> $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        // Escape LIKE wildcards so a literal % or _ in the search box is matched
        // as itself, not as a wildcard.
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                // Owner name or email — matched through the pivot, not a
                // denormalised copy, so it can never drift out of sync.
                ->orWhereHas('owners', fn (Builder $o) => $o
                    ->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term));
        });
    }

    /**
     * @param Builder<Tenant> $query
     */
    private function applyStatus(Builder $query, ?string $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
    }

    /**
     * @param Builder<Tenant> $query
     */
    private function applyPlan(Builder $query, mixed $plan): void
    {
        if ($plan === null || $plan === '') {
            return;
        }

        // Accept either a plan id or a slug, so the filter works whether the UI
        // sends one or the other.
        $query->whereHas('plan', fn (Builder $p) => is_numeric($plan)
            ? $p->whereKey((int) $plan)
            : $p->where('slug', $plan));
    }

    /**
     * @param Builder<Tenant> $query
     */
    private function applyDateRange(Builder $query, ?string $from, ?string $to): void
    {
        if (! empty($from)) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (! empty($to)) {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    /**
     * @param Builder<Tenant> $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        // Unknown columns fall back to a stable default rather than being passed
        // through to the query — a client cannot order by an arbitrary column.
        if (! in_array($column, self::SORTABLE, true)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        $query->orderBy($column, $direction);
    }
}
