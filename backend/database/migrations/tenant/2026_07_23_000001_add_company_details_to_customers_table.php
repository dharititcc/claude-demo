<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company details for a customer.
 *
 * The existing address_* columns are the BILLING address and keep their names —
 * renaming them would break every existing row, resource and form for a purely
 * cosmetic gain. Shipping is added alongside as its own block.
 *
 * customer_number is nullable here on purpose: existing rows have none, and a
 * NOT NULL column would need a default that is meaningless. It is generated on
 * write (see CustomerService) and backfilled below, with a unique index so two
 * customers can never share one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Human-facing identifier, quoted on documents and in support.
            $table->string('customer_number', 32)->nullable()->after('id');

            $table->string('trading_name')->nullable()->after('company');
            $table->string('tax_number', 64)->nullable()->after('trading_name');
            $table->string('registration_number', 64)->nullable()->after('tax_number');
            $table->string('industry', 100)->nullable()->after('registration_number');
            $table->string('mobile', 50)->nullable()->after('phone');

            // Shipping address. Billing is the pre-existing address_* block.
            $table->string('shipping_address_line1')->nullable()->after('country');
            $table->string('shipping_address_line2')->nullable()->after('shipping_address_line1');
            $table->string('shipping_city')->nullable()->after('shipping_address_line2');
            $table->string('shipping_state')->nullable()->after('shipping_city');
            $table->string('shipping_postal_code', 20)->nullable()->after('shipping_state');
            $table->string('shipping_country', 2)->nullable()->after('shipping_postal_code');

            $table->string('timezone', 64)->nullable()->after('shipping_country');
            $table->string('currency', 3)->nullable()->after('timezone');

            // Path on the tenant disk, not a URL — the disk may be private.
            $table->string('logo_path')->nullable()->after('currency');

            $table->unique('customer_number');
            // Filtering the list by industry should not scan the table.
            $table->index('industry');
        });

        // Backfill existing rows so the column is immediately useful and the
        // unique index has something to protect. Done in PHP rather than SQL so
        // the format matches what CustomerService generates from now on.
        $number = 1;

        foreach (DB::table('customers')->orderBy('id')->pluck('id') as $id) {
            DB::table('customers')
                ->where('id', $id)
                ->update(['customer_number' => 'C-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT)]);
            $number++;
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['customer_number']);
            $table->dropIndex(['industry']);

            $table->dropColumn([
                'customer_number',
                'trading_name',
                'tax_number',
                'registration_number',
                'industry',
                'mobile',
                'shipping_address_line1',
                'shipping_address_line2',
                'shipping_city',
                'shipping_state',
                'shipping_postal_code',
                'shipping_country',
                'timezone',
                'currency',
                'logo_path',
            ]);
        });
    }
};
