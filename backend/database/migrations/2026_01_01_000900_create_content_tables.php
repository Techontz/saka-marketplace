<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editorial + directory content.
     *
     * `public_places` is a SEPARATE entity from listings. The frontend's Public
     * Places section currently 404s on every card because it looks slugs up in
     * the listings array; these tables are what make that section real.
     */
    public function up(): void
    {
        Schema::create('public_place_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 30)->nullable();
            $table->foreignId('image_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->unsignedInteger('place_count')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('public_places', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('public_place_category_id')
                ->constrained('public_place_categories')->restrictOnDelete();

            $table->string('name', 191);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();

            $table->foreignId('image_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_line', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('website', 191)->nullable();
            $table->json('opening_hours')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['public_place_category_id', 'is_active'], 'places_category_idx');
            $table->index(['latitude', 'longitude'], 'places_geo_idx');
        });

        // The FAQ accordion already rendered on the homepage.
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 500);
            $table->text('answer');
            $table->string('group', 50)->default('general');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group', 'is_active', 'position']);
        });

        // Terms & Privacy — both currently point at /about in the frontend.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 191);
            $table->longText('body')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->string('description', 255)->nullable();
            // Only is_public rows are exposed by GET /settings/public.
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['group', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('public_places');
        Schema::dropIfExists('public_place_categories');
    }
};
