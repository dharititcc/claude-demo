<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the TOTP time step of a user's last successful two-factor code.
 *
 * RFC 6238 §5.2 requires that a code be accepted at most once: a TOTP stays
 * valid for its whole time step, so anyone who observes one — over the user's
 * shoulder, or from a logged request body — can replay it within that window.
 * Storing the accepted step lets verification demand a strictly newer one.
 *
 * Additive rather than folded into the create_users_table migration, which has
 * already run on existing databases; editing it would need a destructive
 * migrate:fresh to take effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Unsigned big int: this is a Unix timestamp divided by the 30s step,
            // never negative, and sized to outlive the 2038 problem.
            $table->unsignedBigInteger('two_factor_last_used_window')
                ->nullable()
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_used_window');
        });
    }
};
