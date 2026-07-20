<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\HandleStripeWebhook;
use App\Models\PersonalAccessToken;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePasswordPolicy();
        $this->configureAuthorization();
        $this->configureModels();
        $this->configureRateLimiting();

        // Stripe tells us about changes made outside the app (renewals, dunning,
        // dashboard edits); without this, local plan limits drift out of step.
        Event::listen(WebhookReceived::class, HandleStripeWebhook::class);

        // Resolve API tokens against the central database, never against
        // whichever tenant connection happens to be active. See the model.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureBilling();
    }

    /**
     * Cashier is pointed at the Tenant (the organization is what gets billed)
     * and at our central-pinned subscription models.
     */
    private function configureBilling(): void
    {
        Cashier::useCustomerModel(Tenant::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        // Cashier's migrations are published into database/migrations and edited
        // there: the stock ones target `users` and assume an integer key, while
        // our billable is a UUID-keyed Tenant. (Cashier 16 has no
        // ignoreMigrations(); publishing is what takes them out of play.)

        // Let Stripe compute tax rather than deriving it ourselves — rates are
        // jurisdictional and change without notice.
        Cashier::calculateTaxes();
    }

    /**
     * Default API budget. Authenticated callers are limited per-user so that
     * many users behind one NAT/office IP don't throttle each other.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Organization-wide password policy. Production additionally checks the
     * password against the HaveIBeenPwned breach corpus.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            return $this->app->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });
    }

    private function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability) {
            // Platform staff bypass every organization-scoped check. Super Admin
            // is deliberately not a Spatie role: it spans all tenants, while
            // Spatie roles live inside a single tenant's database.
            if ($user->is_super_admin) {
                return true;
            }

            // Tenancy-aware replacement for Spatie's permission gate hook, which
            // we disable in config/permission.php.
            //
            // Spatie's version resolves EVERY ability against the permissions
            // table unconditionally. That table exists only in tenant databases,
            // so in central context — the /horizon and /telescope dashboards, or
            // any policy on a central route — it queries a table that isn't there
            // and the request dies with a QueryException rather than a clean
            // "denied".
            //
            // Outside an organization there are no organization permissions to
            // grant, so return null and let the ability fall through to its own
            // gate or policy.
            if (! tenancy()->initialized) {
                return null;
            }

            // Inside a tenant: check the org's permissions. checkPermissionTo()
            // swallows PermissionDoesNotExist, so an ability that isn't a Spatie
            // permission (a policy name, say) returns false — mapped to null so
            // it falls through instead of denying outright.
            return $user->checkPermissionTo($ability) ?: null;
        });
    }

    private function configureModels(): void
    {
        // Fail loudly on N+1 and on assigning attributes that don't exist.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
