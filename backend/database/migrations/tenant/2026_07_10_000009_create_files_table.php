<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The file manager: a folder tree and the files within it.
     *
     * Distinct from the polymorphic `attachments` table (files hanging off a
     * customer/task). These are standalone documents a user organizes into
     * folders — a Dropbox-like space, not a record attachment.
     */
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Self-referential tree. cascadeOnDelete so removing a folder removes
            // its subtree rather than orphaning it.
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();

            /**
             * Materialised path of ancestor ids ("/1/4/"), maintained on write.
             * Lets "everything under this folder" be a single prefix query
             * instead of a recursive walk.
             */
            $table->string('path')->default('/');

            $table->unsignedBigInteger('created_by')->nullable(); // central users.id
            $table->timestamps();

            $table->index('parent_id');
            $table->index('path');
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes

            $table->unsignedBigInteger('created_by')->nullable(); // central users.id
            $table->timestamps();
            $table->softDeletes();

            $table->index('folder_id');
        });

        Schema::create('file_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();

            /**
             * Random token in the public share URL. Stored hashed: a leaked
             * backup should not hand over working share links.
             */
            $table->string('token_hash', 64)->unique();

            // NULL = never expires. Optional password gate for sensitive files.
            $table->timestamp('expires_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('max_downloads')->nullable(); // NULL = unlimited

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_shares');
        Schema::dropIfExists('files');
        Schema::dropIfExists('folders');
    }
};
