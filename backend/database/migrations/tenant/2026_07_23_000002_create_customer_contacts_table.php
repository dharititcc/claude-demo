<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People at a customer company.
 *
 * A customer holds unlimited contacts, at most one of which is primary. The
 * "at most one" rule is enforced in CustomerContactService rather than by a
 * unique index: a partial/filtered unique index (is_primary WHERE true) is not
 * portable to MySQL 8, and a plain unique on (customer_id, is_primary) would
 * also forbid a second NON-primary contact, which is the common case.
 *
 * Soft deletes, matching customers — a contact removed by accident is a person
 * whose history (notes, who they were) is worth recovering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('job_title', 100)->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('active'); // active|inactive

            $table->unsignedBigInteger('created_by')->nullable(); // central users.id

            $table->timestamps();
            $table->softDeletes();

            // The list view: contacts of one customer, primary first.
            $table->index(['customer_id', 'is_primary']);
            $table->index(['customer_id', 'status']);
            // Searching contacts across customers.
            $table->index('email');
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
