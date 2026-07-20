<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\File;
use App\Models\OrganizationStat;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Services\Admin\RefreshOrganizationStats;

/**
 * Promote a fresh user to super admin and return their bearer token.
 */
function statsSuperAdminToken(): string
{
    [$user, , $token] = registerUser('rollup-admin@example.test', 'Rollup HQ');
    $user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();

    return $token;
}

/**
 * Seed a known amount of data inside a tenant's own database.
 */
function seedTenantData(Tenant $tenant, int $customers, int $projects, int $tasks, int $fileBytes): void
{
    $tenant->run(function () use ($customers, $projects, $tasks, $fileBytes): void {
        Customer::factory()->count($customers)->create();
        Project::factory()->count($projects)->create();
        Task::factory()->count($tasks)->create();

        if ($fileBytes > 0) {
            File::create([
                'name' => 'report.pdf',
                'disk' => 'local',
                'path' => 'files/report.pdf',
                'mime_type' => 'application/pdf',
                'size' => $fileBytes,
                'created_by' => 1,
            ]);
        }
    });
}

it('rolls up tenant-database counts into the central stats table', function () {
    [, $tenant] = registerUser('counts@example.test', 'Counts Org');
    // Registration seeds nothing, so these numbers are exactly what we create.
    seedTenantData($tenant, customers: 5, projects: 3, tasks: 7, fileBytes: 2_097_152); // 2 MB

    app(RefreshOrganizationStats::class)->forTenant($tenant);

    $stat = OrganizationStat::find($tenant->id);

    expect($stat)->not->toBeNull()
        ->and($stat->customers_count)->toBe(5)
        ->and($stat->projects_count)->toBe(3)
        ->and($stat->tasks_count)->toBe(7)
        ->and($stat->files_count)->toBe(1)
        ->and($stat->storage_bytes)->toBe(2_097_152)
        ->and($stat->storageMb())->toBe(2)
        ->and($stat->refreshed_at)->not->toBeNull();
});

it('is idempotent — a second run updates the same row, never duplicates it', function () {
    [, $tenant] = registerUser('idem@example.test', 'Idem Org');
    seedTenantData($tenant, customers: 2, projects: 0, tasks: 0, fileBytes: 0);

    $service = app(RefreshOrganizationStats::class);
    $service->forTenant($tenant);

    // Add more, refresh again: the row is updated in place.
    $tenant->run(fn () => Customer::factory()->count(3)->create());
    $service->forTenant($tenant);

    expect(OrganizationStat::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(OrganizationStat::find($tenant->id)->customers_count)->toBe(5);
});

it('surfaces the rolled-up metrics through the admin list', function () {
    $token = statsSuperAdminToken();
    [, $tenant] = registerUser('surfaced@example.test', 'Surfaced Org');
    seedTenantData($tenant, customers: 4, projects: 2, tasks: 0, fileBytes: 0);

    app(RefreshOrganizationStats::class)->forTenant($tenant);
    app('auth')->forgetGuards();

    $row = collect(
        test()->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admin/organizations')
            ->json('data'),
    )->firstWhere('name', 'Surfaced Org');

    expect($row['metrics']['total_customers'])->toBe(4)
        ->and($row['metrics']['total_projects'])->toBe(2)
        ->and($row['metrics']['stats_refreshed_at'])->not->toBeNull();
});

it('reports metrics as null until an organization has been rolled up', function () {
    $token = statsSuperAdminToken();
    registerUser('unrolled@example.test', 'Unrolled Org');
    app('auth')->forgetGuards();

    $row = collect(
        test()->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admin/organizations')
            ->json('data'),
    )->firstWhere('name', 'Unrolled Org');

    // No rollup row yet — null means "not measured", not a false zero.
    expect($row['metrics']['total_projects'])->toBeNull()
        ->and($row['metrics']['storage_mb'])->toBeNull();
});

it('sums the rollup on the dashboard, and reports null before any run', function () {
    $token = statsSuperAdminToken();
    $headers = ['Authorization' => "Bearer {$token}"];

    // Before any rollup exists, cross-tenant totals are null, not zero.
    $before = test()->withHeaders($headers)->getJson('/api/v1/admin/organizations/stats')->json('data');
    expect($before['total_projects'])->toBeNull();

    [, $a] = registerUser('a@sum.test', 'Sum A');
    [, $b] = registerUser('b@sum.test', 'Sum B');
    seedTenantData($a, customers: 0, projects: 3, tasks: 0, fileBytes: 0);
    seedTenantData($b, customers: 0, projects: 4, tasks: 0, fileBytes: 0);

    $service = app(RefreshOrganizationStats::class);
    $service->forTenant($a);
    $service->forTenant($b);
    app('auth')->forgetGuards();

    $after = test()->withHeaders($headers)->getJson('/api/v1/admin/organizations/stats')->json('data');

    // 3 + 4 across two separate tenant databases, summed from one central query.
    expect($after['total_projects'])->toBe(7);
});

it('refreshes every organization in one sweep', function () {
    registerUser('sweep1@example.test', 'Sweep One');
    registerUser('sweep2@example.test', 'Sweep Two');

    $processed = app(RefreshOrganizationStats::class)->refreshAll(sync: true);

    // Every non-trashed tenant got a stats row.
    expect($processed)->toBeGreaterThanOrEqual(2)
        ->and(OrganizationStat::count())->toBe($processed);
});
