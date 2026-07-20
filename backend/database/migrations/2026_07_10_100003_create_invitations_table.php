<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pending invitations to join an organization.
     *
     * Central, not per-tenant: an invitee may not have an account yet, and the
     * accept flow has to resolve the token before any tenant context exists.
     *
     * The token is stored hashed. A leaked database backup would otherwise hand
     * an attacker working invitation links.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('email')->index();
            $table->string('role'); // App\Enums\Role value
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // One live invitation per address per organization; re-inviting
            // replaces the previous row rather than stacking duplicates.
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
