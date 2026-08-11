<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Land parcel boundaries.
 *
 * A separate table rather than columns on `listings`, for the same reason the
 * EAV split exists: a boundary belongs to ONE vertical (land), and widening the
 * aggregate root for a feature that applies to a fraction of rows is what makes
 * a listings table eventually unusable. It is 1:1, so the join is a key lookup.
 *
 * WHY JSON RATHER THAN A NATIVE POLYGON COLUMN
 * --------------------------------------------
 * MySQL 8 has a real POLYGON type, and it was considered. Three things ruled
 * it out for this table:
 *
 *   1. The listings table already documents the same decision for coordinates
 *      ("MVP uses DECIMAL lat/lng"), because SPATIAL INDEX requires NOT NULL
 *      and geometry here is optional. A parcel is optional too.
 *   2. MySQL's ST_Area on an SRID-4326 polygon returns square DEGREES, not
 *      square metres, so the number a customer sees would have to be computed
 *      in PHP anyway. Storing the source geometry next to the derived metrics
 *      keeps one representation, not two that can disagree.
 *   3. The test suite runs on SQLite, which has no geometry type at all.
 *
 * What matters for correctness is that the DERIVED VALUES are computed on the
 * server (see LandBoundaryService) and never taken from the client — a seller
 * must not be able to claim a two-acre plot by posting `area_sqm`.
 *
 * The bounding box columns are indexed so "land parcels intersecting this
 * viewport" stays a range scan if that query is ever added; they are maintained
 * by the service, never by the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_boundaries', function (Blueprint $table) {
            $table->id();

            // Unique: one parcel outline per listing. A multi-parcel sale is
            // modelled as a MultiPolygon inside `rings`, not as two rows.
            $table->foreignId('listing_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * GeoJSON-style coordinate rings: [[[lng, lat], ...], ...].
             *
             * Index 0 is the outer ring; any further rings are holes (an
             * excluded right-of-way, a neighbour's enclave). Longitude first,
             * because that is the GeoJSON order and exporting to any GIS tool
             * should not need a transform.
             */
            $table->json('rings');

            // Derived, server-computed, never client-supplied.
            $table->decimal('area_sqm', 14, 2)->default(0);
            $table->decimal('perimeter_m', 12, 2)->default(0);
            $table->decimal('centroid_latitude', 10, 7)->nullable();
            $table->decimal('centroid_longitude', 10, 7)->nullable();

            $table->decimal('min_latitude', 10, 7)->nullable();
            $table->decimal('max_latitude', 10, 7)->nullable();
            $table->decimal('min_longitude', 10, 7)->nullable();
            $table->decimal('max_longitude', 10, 7)->nullable();

            // A survey/plot reference from the local land office. Free text —
            // formats differ by district and validating them would reject real
            // ones.
            $table->string('survey_reference', 120)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['min_latitude', 'max_latitude'], 'listing_boundaries_lat_range_index');
            $table->index(['min_longitude', 'max_longitude'], 'listing_boundaries_lng_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_boundaries');
    }
};
