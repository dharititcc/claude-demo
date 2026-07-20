<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a personal access token as an impersonation session.
 *
 * When a Super Admin impersonates a user, we issue a normal Sanctum token on the
 * *target* user — so every downstream permission check naturally evaluates as
 * that user — but tag it here with who is really behind it and which single
 * organization it is confined to. That tag drives three things: the audit trail,
 * the "you are impersonating" banner, and the org-scope check in the tenant
 * middleware that stops an impersonation token wandering into the target's other
 * organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // The real actor. Null for an ordinary token. nullOnDelete so
            // removing a staff account never leaves a dangling reference — the
            // token is worthless without its short expiry anyway.
            $table->foreignId('impersonator_id')->nullable()->after('tokenable_id')
                ->constrained('users')->nullOnDelete();

            // The one organization this impersonation is allowed to touch, even
            // if the target user belongs to several.
            $table->string('impersonated_tenant_id')->nullable()->after('impersonator_id');
            $table->foreign('impersonated_tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['impersonator_id']);
            $table->dropForeign(['impersonated_tenant_id']);
            $table->dropColumn(['impersonator_id', 'impersonated_tenant_id']);
        });
    }
};
