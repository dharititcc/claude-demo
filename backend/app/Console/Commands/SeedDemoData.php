<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Creates a signed-in-ready demo environment: two organizations with separate
 * databases, users at different role levels, and customer data — enough to see
 * tenant isolation and RBAC working in the UI.
 */
class SeedDemoData extends Command
{
    protected $signature = 'app:demo {--fresh : Drop and recreate the demo organizations}';

    protected $description = 'Seed demo organizations, users, and customers for local development';

    public function handle(OrganizationService $organizations): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is for local development only.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->removeExisting();
        }

        $password = 'Demo!Passw0rd#2026';

        // ─── Organization 1: Acme ───
        $owner = $this->makeUser('owner@acme.test', 'Alex Owner', $password);
        $acme = $this->makeOrg($organizations, $owner, 'Acme Inc');

        // A second member with a lower role, to demonstrate permissions in the UI.
        $viewer = $this->makeUser('viewer@acme.test', 'Vic Viewer', $password);
        $organizations->addMember($acme, $viewer, Role::Viewer);

        $acme->run(function () {
            $tags = collect(['vip', 'enterprise', 'renewal'])
                ->map(fn (string $name) => Tag::firstOrCreate(['slug' => $name], ['name' => $name]));

            Customer::factory()->count(24)->create()->each(function (Customer $customer) use ($tags) {
                $customer->tags()->sync($tags->random(random_int(0, 2))->pluck('id'));
            });
        });

        // ─── Organization 2: Globex (proves isolation) ───
        $globexOwner = $this->makeUser('owner@globex.test', 'Gale Globex', $password);
        $globex = $this->makeOrg($organizations, $globexOwner, 'Globex Corp');

        $globex->run(fn () => Customer::factory()->count(7)->create(['company' => 'Globex Client']));

        // The Acme owner also belongs to Globex, but only as a manager — this is
        // what makes per-organization roles visible in the org switcher.
        $organizations->addMember($globex, $owner, Role::Manager);

        $this->newLine();
        $this->info('Demo data ready.');
        $this->table(
            ['Email', 'Password', 'Access'],
            [
                ['owner@acme.test', $password, 'Owner of Acme · Manager in Globex'],
                ['viewer@acme.test', $password, 'Viewer in Acme (read-only)'],
                ['owner@globex.test', $password, 'Owner of Globex'],
            ],
        );

        $this->newLine();
        $this->line('  Acme customers:   24');
        $this->line('  Globex customers: 7');
        $this->line('  Sign in at the SPA and switch organizations to see the data change.');

        return self::SUCCESS;
    }

    private function makeUser(string $email, string $name, string $password): User
    {
        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $name,
            'status' => 'active',
        ]);
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function makeOrg(OrganizationService $organizations, User $owner, string $name): Tenant
    {
        $existing = Tenant::where('name', $name)->first();

        if ($existing !== null) {
            $this->line("  Reusing existing organization: {$name}");

            return $existing;
        }

        $this->line("  Creating organization: {$name} (provisioning database…)");

        return $organizations->create($owner, ['name' => $name]);
    }

    private function removeExisting(): void
    {
        $this->line('  Removing existing demo organizations…');

        // Drop the tenant database explicitly. Since Phase 3 decoupled database
        // destruction from the model lifecycle (a soft delete must never drop a
        // database), forceDelete no longer does this — so --fresh would orphan
        // the old databases without it. This mirrors the tenants:purge command.
        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::withTrashed()
            ->whereIn('name', ['Acme Inc', 'Globex Corp'])
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $tenant->database()->manager()->deleteDatabase($tenant);
            } catch (Throwable $e) {
                // The database may already be gone; that is fine for a reset.
                report($e);
            }

            $tenant->forceDelete();
        }

        User::whereIn('email', ['owner@acme.test', 'viewer@acme.test', 'owner@globex.test'])->forceDelete();
    }
}
