<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Domain\Listing\Enums\ListingStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How many listings are actually in each category, right now.
 *
 * WHY THIS EXISTS RATHER THAN JUST READING `categories.listing_count`
 * ------------------------------------------------------------------
 * That column is a denormalised cache written by `saka:taxonomy:recount`, which
 * runs hourly. Nothing else writes it — publishing a listing does not touch it.
 * So the number it holds is correct at most once an hour, and on any
 * environment where the scheduler is not running (every developer machine, and
 * any deployment where cron was not wired up) it is whatever it was when the
 * database was last seeded. That is how every subcategory came to read
 * "0 Listings" while the tables underneath held two hundred.
 *
 * The column is kept — the admin dashboard and the search-suggestion ordering
 * both sort by it, and sorting tolerates being an hour old. What a customer
 * READS is computed here instead, so the figure on the page matches the number
 * of results they get when they tap it.
 *
 * ONE QUERY, NEVER N+1
 * --------------------
 * A single aggregate returns every category's count at once, including roots,
 * whose totals must include their descendants because listings only ever attach
 * to leaves. The subtree test is a prefix match on the materialised `path`
 * column rather than a recursive walk:
 *
 *     own.path = ancestor.path                 -- the leaf's own listings
 *     OR own.path LIKE CONCAT(ancestor.path, '/%')  -- everything beneath it
 *
 * Both halves are needed. The first alone misses descendants; the second alone
 * misses a leaf's own listings AND lets path "1" swallow path "12", which is
 * why the separator is inside the pattern.
 */
class CategoryListingCounts
{
    /**
     * Short by design.
     *
     * Long enough that a burst of homepage requests runs the aggregate once,
     * short enough that a newly published listing shows up in the count while
     * the seller is still looking at the page. The query is a single grouped
     * scan over an indexed column, so this is a stampede guard, not a
     * correctness crutch.
     */
    private const TTL_SECONDS = 60;

    /**
     * Counts keyed by category SLUG.
     *
     * Slug rather than id because that is what the API resource exposes, and
     * keying on something the response does not contain would mean carrying ids
     * around purely to join them back up.
     *
     * @return array<string, int>
     */
    public function bySlug(): array
    {
        return Cache::remember(
            'catalog:category-listing-counts',
            now()->addSeconds(self::TTL_SECONDS),
            fn (): array => $this->compute(),
        );
    }

    /** @return array<string, int> */
    private function compute(): array
    {
        $statuses = array_map(
            fn (ListingStatus $status): string => $status->value,
            ListingStatus::publiclyVisible(),
        );

        $rows = DB::table('listings as l')
            ->join('categories as own', 'own.id', '=', 'l.category_id')
            ->join('categories as ancestor', function ($join): void {
                $join->on('own.path', '=', 'ancestor.path')
                    ->orOn('own.path', 'like', DB::raw("CONCAT(ancestor.path, '/%')"));
            })
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $statuses)
            ->where('ancestor.is_active', true)
            ->groupBy('ancestor.slug')
            ->select('ancestor.slug')
            ->selectRaw('COUNT(*) AS total')
            ->pluck('total', 'slug')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return $rows;
    }

    /**
     * Overwrite `listing_count` throughout an already-rendered category tree.
     *
     * The tree's STRUCTURE is cached for a day because it genuinely almost
     * never changes; its counts are not, because they change constantly. This
     * walks the cached array and replaces each count in place, which is what
     * lets those two live at completely different freshnesses without rendering
     * the tree twice.
     *
     * A category absent from the aggregate has no listings at all — SQL returns
     * no row for a count of zero — so it falls to 0 rather than keeping the
     * stale column value it was cached with.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @param  array<string, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    public function apply(array $tree, array $counts): array
    {
        foreach ($tree as $index => $node) {
            if (isset($node['slug'])) {
                $tree[$index]['listing_count'] = $counts[$node['slug']] ?? 0;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $tree[$index]['children'] = $this->apply($node['children'], $counts);
            }
        }

        return $tree;
    }
}
