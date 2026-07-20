<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database notifications and outbound webhooks, in the tenant database.
     *
     * Notifications are org-scoped: a user in three organizations has a separate
     * inbox in each, and putting them in the tenant DB gives that for free. The
     * `notifiable_id` is a central users.id (no cross-DB FK).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // UUID primary key — Laravel's DatabaseNotification default.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id'); // central users.id
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('read_at');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('url');

            /**
             * Events this endpoint subscribes to (e.g. customer.created). '*'
             * means all. Stored as JSON so one endpoint can pick several.
             */
            $table->json('events');

            /**
             * Shared secret used to sign each delivery (HMAC-SHA256 over the
             * body, in an X-Signature header). The receiver verifies it so a
             * third party cannot forge our webhooks.
             */
            $table->string('secret');

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable(); // central users.id

            // Circuit breaker: after enough consecutive failures the endpoint is
            // paused rather than retried forever against a dead receiver.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();

            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);

            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('notifications');
    }
};
