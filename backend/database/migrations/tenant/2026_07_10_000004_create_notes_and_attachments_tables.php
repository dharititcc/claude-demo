<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notes and attachments are polymorphic for the same reason as tags: the
     * Projects and Tasks modules will reuse them verbatim.
     *
     * `user_id` points at the central users table and therefore has no FK.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');
            $table->unsignedBigInteger('user_id'); // central users.id (author)
            $table->text('body');
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->unsignedBigInteger('user_id'); // central users.id (uploader)

            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('notes');
    }
};
