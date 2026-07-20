<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier's billing columns, on `tenants` rather than `users`.
     *
     * The organization is the billable entity, not the person: someone who
     * belongs to three organizations must not carry three payment methods, and a
     * subscription should not disappear when its purchaser leaves the company.
     *
     * `trial_ends_at` is intentionally absent — the tenants table already has it
     * (see create_tenants_table), and Cashier reads that same column.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four']);
        });
    }
};
