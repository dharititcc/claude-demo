<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks, with Kanban ordering, subtasks, and time tracking.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            /**
             * Subtasks are self-referential rather than a separate table: they
             * are tasks in every respect (assignee, due date, comments), and a
             * parallel table would duplicate all of it.
             *
             * cascadeOnDelete: deleting a parent removes its subtasks, which
             * would otherwise be orphaned and invisible.
             */
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('todo');      // todo|in_progress|review|done
            $table->string('priority')->default('medium');  // low|medium|high|urgent

            $table->unsignedBigInteger('assignee_id')->nullable(); // central users.id
            $table->unsignedBigInteger('created_by')->nullable();  // central users.id

            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();

            /**
             * Manual ordering within a Kanban column.
             *
             * A float, not an integer: dropping a card between two others then
             * only needs the midpoint of its neighbours, instead of renumbering
             * every row below it on every drag.
             */
            $table->double('position')->default(0);

            // Denormalised from time_entries. Recomputed on write rather than
            // summed on every board render, which would be a query per card.
            $table->unsignedInteger('tracked_seconds')->default(0);
            $table->unsignedInteger('estimated_minutes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The board reads by project+status ordered by position — this index
            // is what keeps that a single sorted range scan.
            $table->index(['project_id', 'status', 'position']);
            $table->index('assignee_id');
            $table->index('parent_id');
            $table->index(['status', 'due_on']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 7)->default('#64748b');
            $table->timestamps();
        });

        Schema::create('label_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['label_id', 'task_id']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // central users.id

            $table->text('description')->nullable();
            $table->timestamp('started_at');

            // NULL means a timer is still running. A partial unique index would
            // be ideal to enforce one running timer per user, but MySQL has no
            // partial indexes — the service enforces it instead.
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('seconds')->default(0);

            $table->boolean('billable')->default(true);
            $table->timestamps();

            $table->index(['task_id', 'user_id']);
            $table->index(['user_id', 'ended_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable'); // tasks, projects — and later, anything
            $table->unsignedBigInteger('user_id'); // central users.id
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('label_task');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('tasks');
    }
};
