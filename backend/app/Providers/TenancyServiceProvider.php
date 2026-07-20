<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\ForgetCachedPermissions;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    /**
     * @return array<class-string, array<int, mixed>>
     */
    public function events(): array
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    // Provision the tenant's own database, apply the tenant
                    // migrations, then seed its roles & permissions.
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,
                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // run synchronously so the org is usable immediately after signup
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],

            // DELIBERATELY EMPTY. stancl maps Eloquent's `deleted` event to
            // TenantDeleted, and `deleted` fires on a SOFT delete too — so the
            // stock wiring (TenantDeleted => DeleteDatabase) means calling
            // $tenant->delete() would irreversibly drop the organization's
            // physical database. A "soft delete" that destroys the data is not a
            // soft delete.
            //
            // Dropping a tenant database is therefore decoupled from the model
            // lifecycle entirely: it happens ONLY in the explicit `tenants:purge`
            // command, after a retention window, on rows that were soft-deleted
            // long ago. Nothing else force-deletes a tenant. See PurgeTenants.
            Events\TenantDeleted::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
                ForgetCachedPermissions::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
                ForgetCachedPermissions::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->assertCacheStoreSupportsTagging();

        $this->bootEvents();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();
    }

    /**
     * CacheTenancyBootstrapper tags every cache entry with the tenant id — that
     * tagging is what stops one organization reading another's cached values.
     *
     * The `file` and `database` stores silently lack tag support, so they only
     * fail once something actually caches inside tenant context, surfacing as an
     * opaque "This cache store does not support tagging" 500 from whichever
     * controller happened to call Cache::remember(). Failing at boot instead
     * turns a confusing runtime error into an actionable one.
     */
    protected function assertCacheStoreSupportsTagging(): void
    {
        $bootstrappers = config('tenancy.bootstrappers', []);

        if (! in_array(CacheTenancyBootstrapper::class, $bootstrappers, true)) {
            return;
        }

        $store = config('cache.default');
        $driver = config("cache.stores.{$store}.driver");

        // Drivers implementing Illuminate\Contracts\Cache\Store tagging.
        $taggable = ['redis', 'memcached', 'array', 'dynamodb'];

        if (in_array($driver, $taggable, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Tenancy requires a cache store that supports tagging, but CACHE_STORE=%s uses the "%s" driver. '.
            'Use redis, memcached, or array (dev only). Alternatively remove CacheTenancyBootstrapper from '.
            'config/tenancy.php — but then cache entries are NOT isolated between organizations.',
            $store,
            $driver ?? 'unknown',
        ));
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes(): void
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
