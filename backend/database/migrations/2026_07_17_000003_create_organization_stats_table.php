<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A denormalised per-organization rollup, living in the CENTRAL database.
 *
 * The Super Admin list and dashboard need per-org counts — projects, tasks,
 * storage — but those records live in each organization's own tenant database.
 * Reading them live would be one cross-database fan-out per organization per
 * page: fine for three orgs, fatal for three thousand. Instead a scheduled job
 * walks the tenants once, enters each database, and writes the totals here, so
 * every admin read is a single central query that sorts and filters like any
 * other column.
 *
 * `refreshed_at` is deliberately explicit and separate from `updated_at`: the
 * UI shows "as of HH:MM", and a caller must be able to tell fresh numbers from
 * a stale row whose tenant the last run could not reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_stats', function (Blueprint $table) {
            // One row per tenant. Cascade so a purged organization does not
            // leave an orphan stats row behind.
            $table->string('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedInteger('customers_count')->default(0);
            $table->unsignedInteger('projects_count')->default(0);
            $table->unsignedInteger('tasks_count')->default(0);
            $table->unsignedInteger('files_count')->default(0);

            // Bytes, summed across the file manager and record attachments.
            // Unsigned big int: a busy org can hold gigabytes, well past a
            // 32-bit ceiling.
            $table->unsignedBigInteger('storage_bytes')->default(0);

            // Most recent audit-log entry in the tenant — a real "last used"
            // signal, null for an org that has done nothing yet.
            $table->timestamp('last_activity_at')->nullable();

            // When these numbers were last computed. Not updated_at: a run that
            // recomputes identical numbers still advances freshness.
            $table->timestamp('refreshed_at')->nullable();

            $table->timestamps();

            // The dashboard sums these across all rows; the list sorts by them.
            $table->index('storage_bytes');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_stats');
    }
};
