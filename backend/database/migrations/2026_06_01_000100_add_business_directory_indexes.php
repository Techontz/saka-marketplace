<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the public business directory needs.
 *
 * Milestone 13 gave businesses their own public pages, a "near me" radius
 * search and a directory listing — but no supporting indexes, so both queries
 * were full table scans. `listings` and `public_places` already had a geo
 * index; `seller_profiles` was simply missed.
 *
 *  - `seller_geo_idx` backs the bounding-box prefilter that every radius search
 *    runs before it pays for the Haversine term. Without it the prefilter reads
 *    every row and the whole optimisation is undone.
 *
 *  - `seller_public_idx` backs the "is this a real business?" test — onboarding
 *    complete, ordered by verified and rating — which runs on the directory,
 *    on search suggestions, and on every similar-business rail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            if (! $this->hasIndex('seller_profiles', 'seller_geo_idx')) {
                $table->index(['latitude', 'longitude'], 'seller_geo_idx');
            }

            if (! $this->hasIndex('seller_profiles', 'seller_public_idx')) {
                $table->index(
                    ['onboarding_completed_at', 'is_verified', 'rating_avg'],
                    'seller_public_idx',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            foreach (['seller_geo_idx', 'seller_public_idx'] as $index) {
                if ($this->hasIndex('seller_profiles', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::select(
            'select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $index],
        ) !== [];
    }
};
