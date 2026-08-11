<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One polymorphic table for every upload in the system.
     *
     * `disk` is recorded per row so a migration from the local disk to S3 is a
     * backfill job plus an env change — files written before and after the
     * switch both keep resolving.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('mediable_type', 120)->nullable();
            $table->unsignedBigInteger('mediable_id')->nullable();
            $table->string('collection', 50)->default('gallery');

            $table->string('disk', 30)->default('public');
            $table->string('path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 255)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);

            // { "thumb": {"path": "...", "width": 200}, ... } — filled by a
            // queued job, so the row exists before processing completes.
            $table->json('variants')->nullable();
            $table->string('processing_status', 20)->default('pending');

            // sha256 of the original, for deduplication.
            $table->char('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'collection', 'position'], 'media_morph_idx');
            $table->index('checksum');
            $table->index('processing_status');
        });

        // Deferred FK from users -> media (circular with media.uploaded_by).
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
        });

        Schema::dropIfExists('media');
    }
};
