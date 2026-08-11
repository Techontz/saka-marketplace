<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Listing\Enums\ListingStatus;
use App\Support\Cache\CacheKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the denormalised `listing_count` columns.
 *
 * `categories.listing_count`, `regions.listing_count` and
 * `districts.listing_count` are read by the public API (and rendered as
 * "N Listings" on the category browser), but nothing was writing them — they
 * sat at 0 for every row. This is the writer.
 *
 * Recomputed wholesale rather than incremented per write:
 *
 *  - a listing changes category, region, status and visibility over its life,
 *    so an incremental counter has four places to get out of step and no way
 *    to notice when it has;
 *  - the whole job is three set-based statements over an indexed column, which
 *    is cheap enough to simply repeat;
 *  - it is idempotent, so a missed run costs nothing and a double run is
 *    harmless.
 *
 * Counts only PUBLICLY VISIBLE listings, because that is what the number means
 * to someone reading it: how many listings they can actually open.
 *
 * Category counts roll UP the tree — "Property: 126" must include everything
 * in Apartments, Houses and the rest, since listings only ever attach to leaf
 * categories. The materialised `path` column makes that a prefix match rather
 * than a recursive walk.
 */
class RecountTaxonomy extends Command
{
    protected $signature = 'saka:taxonomy:recount';

    protected $description = 'Recompute listing_count on categories, regions and districts';

    public function handle(): int
    {
        $statuses = array_map(
            fn (ListingStatus $status): string => $status->value,
            ListingStatus::publiclyVisible(),
        );

        $categories = $this->recountCategories($statuses);
        $regions = $this->recountLocation('regions', 'region_id', $statuses);
        $districts = $this->recountLocation('districts', 'district_id', $statuses);

        // These numbers are rendered from the cached category tree.
        CacheKeys::flushTaxonomy();
        CacheKeys::flushLocations();

        $this->info("Recounted {$categories} categories, {$regions} regions, {$districts} districts.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function recountCategories(array $statuses): int
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        // Zero first, then set from the aggregate. An INNER JOIN cannot reset a
        // category that has just lost its last listing, so without this pass
        // the count would only ever ratchet upward.
        DB::statement('UPDATE categories SET listing_count = 0');

        /*
         * The join condition is the subtree test: a listing counts towards a
         * category when its own category's path equals that category's path, or
         * begins with it followed by the separator. `LIKE CONCAT(c.path, '/%')`
         * alone would miss a leaf's own listings; `LIKE CONCAT(c.path, '%')`
         * alone would let path "1" swallow path "12".
         */
        DB::statement(
            <<<SQL
            UPDATE categories AS c
            INNER JOIN (
                SELECT ancestor.id AS category_id, COUNT(*) AS total
                FROM listings AS l
                INNER JOIN categories AS own ON own.id = l.category_id
                INNER JOIN categories AS ancestor
                    ON own.path = ancestor.path
                    OR own.path LIKE CONCAT(ancestor.path, '/%')
                WHERE l.deleted_at IS NULL
                  AND l.status IN ({$placeholders})
                GROUP BY ancestor.id
            ) AS counted ON counted.category_id = c.id
            SET c.listing_count = counted.total
            SQL
            ,
            $statuses,
        );

        return (int) DB::table('categories')->count();
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function recountLocation(string $table, string $foreignKey, array $statuses): int
    {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        DB::statement("UPDATE {$table} SET listing_count = 0");

        DB::statement(
            <<<SQL
            UPDATE {$table} AS t
            INNER JOIN (
                SELECT {$foreignKey} AS location_id, COUNT(*) AS total
                FROM listings
                WHERE deleted_at IS NULL
                  AND status IN ({$placeholders})
                  AND {$foreignKey} IS NOT NULL
                GROUP BY {$foreignKey}
            ) AS counted ON counted.location_id = t.id
            SET t.listing_count = counted.total
            SQL
            ,
            $statuses,
        );

        return (int) DB::table($table)->count();
    }
}
