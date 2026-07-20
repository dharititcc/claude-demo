<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendar events, in the tenant database.
     *
     * Recurrence is stored as a rule (RFC 5545 RRULE) plus an anchor, not as
     * pre-generated rows. A weekly standup expanded to individual rows would be
     * thousands of records with no end, and editing "all future occurrences"
     * would mean rewriting them. The rule is expanded on read for the requested
     * window instead; only *exceptions* to a series get their own row.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('type')->default('event'); // event|meeting|reminder|deadline
            $table->string('color', 7)->default('#6366f1');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);

            // ─── Recurrence ───
            // NULL frequency = a one-off event.
            $table->string('recurrence_frequency')->nullable(); // daily|weekly|monthly|yearly
            $table->unsignedSmallInteger('recurrence_interval')->default(1); // every N units
            $table->json('recurrence_by_day')->nullable();      // ['MO','WE'] for weekly
            $table->timestamp('recurrence_until')->nullable();  // series end (NULL = forever)
            $table->unsignedSmallInteger('recurrence_count')->nullable(); // or after N occurrences

            /**
             * A materialised occurrence that departs from its series — moved,
             * edited, or cancelled. `parent_id` links it to the series; the
             * `original_starts_at` identifies which occurrence it replaces so the
             * expansion can skip the generated one.
             */
            $table->foreignId('parent_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->timestamp('original_starts_at')->nullable();
            $table->boolean('is_cancelled')->default(false);

            // Optional links to other modules.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable(); // central users.id

            $table->timestamps();
            $table->softDeletes();

            // The calendar reads a date window; this index keeps that a range scan.
            $table->index(['starts_at', 'ends_at']);
            $table->index('recurrence_frequency');
            $table->index('parent_id');
            $table->index('project_id');
        });

        Schema::create('event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // central users.id
            $table->string('response')->default('pending'); // pending|accepted|declined|tentative
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Minutes before the event to notify. Multiple rows = multiple nudges.
            $table->unsignedInteger('minutes_before')->default(15);
            $table->string('channel')->default('database'); // database|email

            // Set when the reminder has fired, so the scheduler does not send it
            // twice. NULL = still pending.
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('event_attendees');
        Schema::dropIfExists('events');
    }
};
