<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A central audit trail of Super Admin actions.
 *
 * The per-organization activity log lives inside each tenant database and
 * records what members do *within* their org. It cannot record a platform
 * admin suspending, editing, or purging an organization — those acts happen in
 * central context, across orgs, and often when no tenant is even booted. This
 * table is that missing record: who did what to which organization, and when.
 *
 * Append-only by design. There is no updated_at because an audit entry is never
 * edited; rewriting the record of an action defeats the point of having one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activities', function (Blueprint $table) {
            $table->id();

            // The acting super admin. Nullable + nullOnDelete so a scheduled or
            // system action (e.g. the purge job) can be recorded with no actor,
            // and so removing a staff account never erases the history of what
            // they did.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            // e.g. 'organization.suspended', 'organization.purged'. A dotted verb
            // rather than an enum column so new action types need no migration.
            $table->string('action');

            // The subject. Kept as a loose type+id pair rather than a foreign key:
            // a purge hard-deletes the organization, and the audit record of that
            // purge must outlive the row it refers to.
            $table->string('target_type')->default('organization');
            $table->string('target_id')->nullable();
            $table->string('target_label')->nullable(); // name at the time, for a readable log after purge

            $table->text('description')->nullable();
            $table->json('properties')->nullable(); // changed fields, reason, etc.
            $table->string('ip_address', 45)->nullable();

            // created_at only — see the class note on append-only.
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activities');
    }
};
