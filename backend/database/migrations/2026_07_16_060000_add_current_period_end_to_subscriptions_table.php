<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache the current period end locally.
     *
     * Cashier does not store it, so the only way to show "renews on…" would be
     * `asStripeSubscription()` — a live Stripe API call on every billing page
     * load. That is slow, and it makes our billing page fail whenever Stripe is
     * degraded, for the sake of rendering one date.
     *
     * Kept in step by the `customer.subscription.*` webhooks, which Stripe
     * already sends on every renewal and change.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_end')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('current_period_end');
        });
    }
};
