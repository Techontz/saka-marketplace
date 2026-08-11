<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Catalog\Enums\AttributeInputType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Taxonomy + the EAV attribute system.
     *
     * This is the layer that makes SAKA genuinely multi-vertical (Milestone 4
     * decision 4). A Vehicle has no `beds`; a Job has no `sqft`. Rather than a
     * wide nullable table or one table per vertical, category-specific facets
     * live in `attributes` and are bound to categories via `category_attribute`.
     * Adding a vertical is seed data, not a migration.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')->restrictOnDelete();

            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 30)->nullable();      // emoji, e.g. 🏠
            $table->text('description')->nullable();

            $table->foreignId('image_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            // Materialised path ("1/14") + depth for O(1) subtree queries
            // without recursive CTEs on the hot browse path.
            $table->string('path', 255)->default('');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedSmallInteger('position')->default(0);

            $table->unsignedInteger('listing_count')->default(0);
            $table->boolean('is_active')->default(true);

            // Only leaf categories may hold listings.
            $table->boolean('is_leaf')->default(true);

            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->timestamps();

            $table->unique(['parent_id', 'name']);
            $table->index(['parent_id', 'position']);
            $table->index('path');
            $table->index(['is_active', 'depth']);
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();        // beds, mileage, ram
            $table->string('name', 120);
            $table->enum('input_type', AttributeInputType::values());
            $table->enum('data_type', AttributeDataType::values());
            $table->string('unit', 20)->nullable();      // sqft, km, GB

            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);

            // Numeric guard rails, applied by the dynamic validator.
            $table->decimal('min_value', 15, 4)->nullable();
            $table->decimal('max_value', 15, 4)->nullable();

            $table->timestamps();

            $table->index('is_filterable');
        });

        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value', 120);
            $table->string('label', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'value']);
            $table->index(['attribute_id', 'position']);
        });

        Schema::create('category_attribute', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['category_id', 'attribute_id']);
            $table->index('attribute_id');
        });

        // Sidecar taxonomies. Populate the Amenities / Facilities tabs that the
        // frontend already renders (and which are permanently empty today).
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 60)->nullable();
            $table->foreignId('category_id')->nullable()
                ->constrained('categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('icon', 60)->nullable();
            $table->foreignId('category_id')->nullable()
                ->constrained('categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('category_attribute');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('categories');
    }
};
