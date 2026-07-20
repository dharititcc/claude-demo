<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `tenants` table doubles as the Organizations table: each tenant is
     * one organization, with its own dynamically-provisioned database.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary(); // UUID

            // ─── Organization profile ───
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->string('language', 5)->default('en');
            $table->string('status')->default('trial'); // trial|active|suspended|cancelled

            // ─── Billing (populated in Phase 4) ───
            $table->foreignId('plan_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // stancl/tenancy virtual-column store for anything not mapped above
            $table->json('data')->nullable();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
