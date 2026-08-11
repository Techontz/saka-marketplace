<?php

declare(strict_types=1);

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Listing\Enums\PriceUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The aggregate root.
     *
     * Columns here are the ones filtered or sorted GLOBALLY across every
     * vertical. Anything category-specific (beds, mileage, RAM) lives in
     * listing_attribute_values. That split is what keeps this table narrow
     * while still supporting nine unrelated verticals.
     *
     * GEO: MySQL requires SPATIAL INDEX columns to be NOT NULL, and coordinates
     * are optional here. So MVP uses DECIMAL lat/lng with a composite index and
     * a bounding-box prefilter before the Haversine term — which is what makes
     * the index usable. A generated POINT column + SPATIAL INDEX can be added
     * later once coordinates are mandatory.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 200)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();

            $table->enum('purpose', ListingPurpose::values())->nullable();

            // Minor units. NULL means "contact for price".
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('TZS');
            $table->enum('price_unit', PriceUnit::values())->nullable();
            $table->boolean('is_negotiable')->default(false);

            $table->enum('condition', ListingCondition::values())->nullable();

            $table->foreignId('region_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('address_line', 255)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->enum('status', ListingStatus::values())
                ->default(ListingStatus::Draft->value);
            $table->text('rejection_reason')->nullable();

            $table->boolean('is_verified')->default(false);

            // Promotion hooks. Columns exist from MVP so the v1.1 "Featured
            // Listings" feature is a service + UI, not a migration on a large
            // table.
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->unsignedSmallInteger('boost_score')->default(0);

            $table->date('available_from')->nullable();

            // Denormalised counters. Incremented in Redis, flushed periodically.
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('favorite_count')->default(0);
            $table->unsignedInteger('inquiry_count')->default(0);
            $table->decimal('popularity_score', 8, 4)->default(0);

            // Flattened projection for the search engine (MySQL FULLTEXT now,
            // Meilisearch at v1.1). Kept in sync by a queued job.
            $table->json('search_document')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // --- Indexes chosen against the frontend's actual filter surface ---
            $table->index(['status', 'published_at'], 'listings_browse_idx');
            $table->index(['category_id', 'status', 'published_at'], 'listings_category_idx');
            $table->index(['category_id', 'status', 'price'], 'listings_price_idx');
            $table->index(['region_id', 'district_id', 'status'], 'listings_location_idx');
            $table->index(['user_id', 'status'], 'listings_seller_idx');
            $table->index(['status', 'is_featured', 'published_at'], 'listings_featured_idx');
            $table->index(['status', 'popularity_score'], 'listings_trending_idx');
            $table->index(['latitude', 'longitude'], 'listings_geo_idx');
            $table->index('expires_at', 'listings_expiry_idx');
        });

        // Keyword search for MVP. Swapped for Meilisearch at v1.1 behind the
        // SearchDriverInterface — no controller changes required.
        DB::statement('ALTER TABLE `listings` ADD FULLTEXT `listings_fulltext_idx` (`title`, `description`)');

        DB::statement('ALTER TABLE `listings` ADD CONSTRAINT `listings_price_non_negative` CHECK (`price` IS NULL OR `price` >= 0)');
        DB::statement('ALTER TABLE `listings` ADD CONSTRAINT `listings_coords_paired` CHECK ((`latitude` IS NULL) = (`longitude` IS NULL))');
        DB::statement('ALTER TABLE `listings` ADD CONSTRAINT `listings_lat_range` CHECK (`latitude` IS NULL OR (`latitude` BETWEEN -90 AND 90))');
        DB::statement('ALTER TABLE `listings` ADD CONSTRAINT `listings_lng_range` CHECK (`longitude` IS NULL OR (`longitude` BETWEEN -180 AND 180))');

        /**
         * EAV leaf table.
         *
         * Typed value columns rather than one VARCHAR: this is what makes
         * `beds >= 2` an index range scan instead of a string cast.
         */
        Schema::create('listing_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_option_id')->nullable()
                ->constrained('attribute_options')->cascadeOnDelete();

            $table->string('value_string', 255)->nullable();
            $table->bigInteger('value_integer')->nullable();
            $table->decimal('value_decimal', 15, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();

            $table->timestamps();

            // A multiselect stores one row per chosen option, so the option id
            // participates in the uniqueness key.
            $table->unique(['listing_id', 'attribute_id', 'attribute_option_id'], 'lav_unique_idx');
            $table->index(['attribute_id', 'value_integer'], 'lav_int_idx');
            $table->index(['attribute_id', 'value_decimal'], 'lav_dec_idx');
            $table->index(['attribute_id', 'value_boolean'], 'lav_bool_idx');
            $table->index(['attribute_id', 'attribute_option_id', 'listing_id'], 'lav_facet_idx');
        });

        Schema::create('listing_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_id', 'created_at']);
        });

        // Pivots for the sidecar taxonomies.
        Schema::create('listing_amenity', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['listing_id', 'amenity_id']);
            $table->index('amenity_id');
        });

        Schema::create('listing_facility', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->primary(['listing_id', 'facility_id']);
            $table->index('facility_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_facility');
        Schema::dropIfExists('listing_amenity');
        Schema::dropIfExists('listing_status_histories');
        Schema::dropIfExists('listing_attribute_values');
        Schema::dropIfExists('listings');
    }
};
