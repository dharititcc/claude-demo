<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects live in the tenant database, so they are implicitly scoped to one
     * organization — no `organization_id`, and no global scope to forget.
     *
     * `owner_id` and the member pivot's `user_id` reference the *central* users
     * table, so they carry no foreign key: MySQL cannot enforce one across
     * databases. Membership integrity is enforced in the application layer.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();

            $table->string('status')->default('planning'); // planning|active|on_hold|completed|cancelled
            $table->string('color', 7)->default('#6366f1');

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable(); // central users.id

            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->decimal('budget', 12, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['status', 'due_on']);
            $table->index('customer_id');
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // central users.id — no FK possible
            $table->string('role')->default('member'); // member|lead
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
