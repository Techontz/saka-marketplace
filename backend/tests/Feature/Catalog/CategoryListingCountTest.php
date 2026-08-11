<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Listing;
use App\Services\Catalog\CategoryListingCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The subcategory counts on the homepage.
 *
 * Every subcategory read "0 Listings" while the catalogue held two hundred.
 * The cause was two staleness windows stacked on each other: a `listing_count`
 * column only an hourly command ever wrote, cached inside a category tree that
 * was itself cached for a day. These pin the fix in place.
 */
class CategoryListingCountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * A published listing in a known leaf category.
     *
     * DatabaseSeeder seeds the taxonomy but no listings, so a test that only
     * asserted "the numbers agree" would pass on a catalogue of zeroes — which
     * is exactly the bug. Everything here counts something real.
     */
    private function publishListingIn(string $slug): Category
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();

        Listing::factory()->published()->create(['category_id' => $category->id]);

        return $category;
    }

    #[Test]
    public function the_endpoint_reports_counts_the_listings_table_agrees_with(): void
    {
        $this->publishListingIn('property-apartments');
        $this->publishListingIn('property-apartments');
        $this->publishListingIn('vehicles-cars');

        $response = $this->getJson('/api/v1/categories')->assertOk();

        $checked = 0;

        foreach ($response->json('data') as $root) {
            foreach ($root['children'] ?? [] as $child) {
                $expected = DB::table('listings as l')
                    ->join('categories as c', 'c.id', '=', 'l.category_id')
                    ->where('c.slug', $child['slug'])
                    ->whereNull('l.deleted_at')
                    ->where('l.status', 'published')
                    ->count();

                $this->assertSame(
                    $expected,
                    $child['listing_count'],
                    "Count for {$child['slug']} disagrees with the listings table.",
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No subcategories were checked.');
    }

    #[Test]
    public function a_root_count_includes_its_descendants(): void
    {
        $this->publishListingIn('property-apartments');
        $this->publishListingIn('property-houses');

        $response = $this->getJson('/api/v1/categories')->assertOk();

        foreach ($response->json('data') as $root) {
            $childSum = array_sum(array_column($root['children'] ?? [], 'listing_count'));

            // Listings attach to leaves, so a root's total is its children's.
            $this->assertGreaterThanOrEqual(
                $childSum,
                $root['listing_count'],
                "Root {$root['slug']} reports fewer listings than its children hold.",
            );
        }
    }

    #[Test]
    public function counts_do_not_come_from_the_stale_denormalised_column(): void
    {
        $this->publishListingIn('property-apartments');

        // The exact production failure: the column is wrong because nothing
        // recent has written it.
        DB::table('categories')->update(['listing_count' => 0]);
        Cache::flush();

        $response = $this->getJson('/api/v1/categories')->assertOk();

        $total = 0;

        foreach ($response->json('data') as $root) {
            $total += $root['listing_count'];
        }

        $this->assertGreaterThan(
            0,
            $total,
            'The endpoint is still reading the denormalised column.',
        );
    }

    #[Test]
    public function every_count_is_one_query_regardless_of_tree_size(): void
    {
        Cache::flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(CategoryListingCounts::class)->bySlug();

        // One aggregate covers every category at every depth. A per-category
        // count would be ~86 here and would grow with the taxonomy.
        $this->assertSame(1, $queries, 'The count aggregate is not a single query.');
    }

    #[Test]
    public function a_category_with_no_listings_reports_zero_not_a_stale_number(): void
    {
        DB::table('categories')->update(['listing_count' => 999]);
        Cache::flush();

        $response = $this->getJson('/api/v1/categories')->assertOk();

        foreach ($response->json('data') as $root) {
            foreach ($root['children'] ?? [] as $child) {
                // Nothing is published, so every count must be a real zero
                // rather than the number sitting in the column.
                $this->assertSame(
                    0,
                    $child['listing_count'],
                    "{$child['slug']} is echoing the column rather than counting.",
                );
            }
        }
    }
}
