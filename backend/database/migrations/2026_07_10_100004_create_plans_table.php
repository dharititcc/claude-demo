<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription plans, in the central database — plans are platform-wide, not
     * per-organization.
     *
     * Prices are held as Stripe price ids rather than amounts: Stripe is the
     * source of truth for money. Storing our own copy of the amount would
     * eventually disagree with what the customer is actually charged. The
     * `*_amount` columns exist for display only and are clearly labelled as such.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Stripe price identifiers — the billing source of truth.
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_annual_price_id')->nullable();

            // Display only. Never used to charge; Stripe decides the real amount.
            $table->unsignedInteger('monthly_amount')->default(0); // minor units
            $table->unsignedInteger('annual_amount')->default(0);  // minor units
            $table->string('currency', 3)->default('USD');

            $table->unsignedSmallInteger('trial_days')->default(14);

            /**
             * Hard usage limits. NULL means unlimited — distinct from 0, which
             * would mean "none allowed".
             */
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();

            $table->json('features')->nullable(); // marketing bullet points
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
