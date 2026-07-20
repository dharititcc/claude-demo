<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A contact phone number for the organization.
 *
 * The Super Admin organization list needs a phone per org, but the number lived
 * only on individual users and on tenant customers — never on the organization
 * itself. It is a real column (not the stancl `data` JSON) so the admin list can
 * search and sort on it; remember to also list it in Tenant::getCustomColumns(),
 * or the VirtualColumn trait funnels it into JSON where those queries never
 * match — the same failure mode that once re-created a Stripe customer per
 * request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
