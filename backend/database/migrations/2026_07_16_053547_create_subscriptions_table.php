<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscriptions, keyed to the organization.
     *
     * Two deliberate departures from Cashier's published migration:
     *
     *  1. The column is `tenant_id`, not `user_id`. Cashier's `subscriptions()`
     *     relation derives its foreign key from the billable model via
     *     getForeignKey(), which for Tenant yields `tenant_id`.
     *  2. It is a string, not a foreignId. Cashier assumes an auto-incrementing
     *     integer billable; our tenant keys are UUIDs, which an unsigned bigint
     *     cannot hold.
     *
     * Central database: billing is a platform-level concern, and a tenant cannot
     * hold the record of its own subscription.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id'); // UUID — see note above
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'stripe_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
