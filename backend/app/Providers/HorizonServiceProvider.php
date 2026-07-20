<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Who may open the Horizon dashboard outside local development.
     *
     * Gated on the platform-admin flag rather than a hardcoded email list: the
     * dashboard exposes every queued job's payload across all organizations, so
     * it is platform staff only. An email list would drift and tempt someone to
     * add an address rather than grant the role.
     *
     * Note `?User $user` — an unauthenticated visitor arrives as null, and this
     * must deny them rather than error.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null) => (bool) $user?->is_super_admin);
    }
}
