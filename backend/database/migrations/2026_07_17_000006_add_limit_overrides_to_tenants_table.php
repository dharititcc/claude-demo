<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization limit overrides.
 *
 * A plan sets the same ceilings for everyone on it, but sales and support
 * routinely need to bend them for one customer — "give this account 50 extra
 * projects" — without inventing a bespoke plan. This holds those exceptions.
 *
 * Shape: a JSON object keyed by limit (users, customers, storage_mb). A key that
 * is PRESENT overrides the plan; its value is either an integer ceiling or null
 * for "unlimited". A key that is ABSENT means no override — fall back to the
 * plan. That three-way distinction (a number, explicit unlimited, or unset) is
 * why this is a map rather than three nullable columns, where null could not
 * tell "unlimited" apart from "not set".
 *
 * Listed in Tenant::getCustomColumns(), or stancl's VirtualColumn would funnel
 * it into the `data` blob — the same trap documented there for the other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('limit_overrides')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('limit_overrides');
        });
    }
};
