<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lives in the tenant database, so every row is implicitly owned by one
     * organization — no `organization_id` column and no global scope needed.
     *
     * `owner_id` references a user in the *central* database, so it carries no
     * foreign key: the constraint cannot span databases. Referential integrity
     * for that column is enforced in the application layer.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('lead'); // lead|active|inactive|churned

            // Address
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->nullable();

            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->unsignedBigInteger('owner_id')->nullable(); // central users.id

            $table->timestamps();
            $table->softDeletes();

            // Indexes chosen for the list view: filtered by status, sorted by
            // created_at, and searched by name/email/company.
            $table->index('status');
            $table->index('owner_id');
            $table->index(['status', 'created_at']);
            $table->index('name');
            $table->index('email');
            $table->index('company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
