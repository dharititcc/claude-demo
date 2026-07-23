<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach file-manager documents to a customer, with categories and versions.
 *
 * Extends the existing `files` table rather than introducing a second document
 * store: uploads already enforce the storage quota, block dangerous extensions,
 * write to a tenant-suffixed disk and support sharing. A parallel table would
 * have had to re-earn all of that.
 *
 * Versioning is a chain, not a flag. `replaces_id` points at the file this one
 * supersedes, so the current version is simply the one nothing else replaces.
 * A denormalised `is_current` boolean would be one more thing that can go stale
 * — the same reason invoice "overdue" is derived rather than stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // nullOnDelete, not cascade: a document outliving its customer is
            // better than one silently disappearing from the file manager. It
            // simply stops being filed under anybody.
            $table->foreignId('customer_id')->nullable()->after('folder_id')->constrained()->nullOnDelete();

            $table->string('category', 60)->nullable()->after('name');

            $table->unsignedInteger('version')->default(1)->after('size');
            $table->foreignId('replaces_id')->nullable()->after('version')
                ->constrained('files')->nullOnDelete();

            // The documents tab: this customer's files, newest first.
            $table->index(['customer_id', 'created_at']);
            // Filtering a customer's documents by category.
            $table->index(['customer_id', 'category']);
            // "Is this file superseded?" — the NOT EXISTS behind scopeCurrent().
            $table->index('replaces_id');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['replaces_id']);
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['customer_id', 'category']);
            $table->dropIndex(['replaces_id']);
            $table->dropColumn(['customer_id', 'category', 'version', 'replaces_id']);
        });
    }
};
