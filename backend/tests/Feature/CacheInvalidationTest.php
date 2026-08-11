<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Faq;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Proves cache invalidation actually removes keys.
 *
 * This file exists because it did not. `CacheKeys::forgetPattern()` built its
 * SCAN pattern from `config('cache.prefix')` alone, but a cached key carries
 * BOTH the cache-store prefix and the Redis connection prefix — so the pattern
 * matched nothing and every wildcard invalidation (per-category attributes,
 * recommendations) silently deleted zero keys, forever. Nothing failed; the
 * data was just stale until its TTL expired.
 *
 * The lesson these tests encode: an invalidation test that asserts "the method
 * was called" would have passed against the broken implementation. They assert
 * the key is GONE.
 *
 * Runs against real Redis — the array store cannot enumerate keys, so it cannot
 * reproduce the bug.
 */
class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * phpunit.xml pins CACHE_STORE=array for speed and isolation, but the array
     * store cannot enumerate keys — under it `forgetPattern()` returns early and
     * every assertion here would pass against a completely broken implementation.
     * So this class, and only this class, runs on real Redis (test DB 15).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'redis']);
        Cache::purge('redis');

        if (! Cache::getStore() instanceof RedisStore) {
            $this->markTestSkipped('Redis cache store unavailable.');
        }

        // Seeding runs through the same observers and warms/clears keys; start
        // from a known-empty cache.
        Cache::flush();
    }

    // -------------------------------------------------------- the primitive

    public function test_wildcard_delete_removes_matching_keys_and_spares_the_rest(): void
    {
        Cache::put(CacheKeys::categoryAttributes(11), 'a', 600);
        Cache::put(CacheKeys::categoryAttributes(22), 'b', 600);
        Cache::put(CacheKeys::recommendations(7), 'c', 600);
        Cache::put(CacheKeys::TRENDING, 'keep', 600);

        CacheKeys::forgetPattern('category:*:attributes');

        $this->assertFalse(Cache::has(CacheKeys::categoryAttributes(11)));
        $this->assertFalse(Cache::has(CacheKeys::categoryAttributes(22)));
        $this->assertTrue(Cache::has(CacheKeys::recommendations(7)), 'A non-matching key was deleted.');
        $this->assertTrue(Cache::has(CacheKeys::TRENDING), 'A non-matching key was deleted.');
    }

    public function test_wildcard_delete_is_a_no_op_when_nothing_matches(): void
    {
        Cache::put(CacheKeys::TRENDING, 'keep', 600);

        CacheKeys::forgetPattern('nothing:matches:*');

        $this->assertTrue(Cache::has(CacheKeys::TRENDING));
    }

    // ------------------------------------------------------------- taxonomy

    public function test_writing_a_category_invalidates_the_taxonomy_caches(): void
    {
        $this->warmTaxonomy();

        Category::query()->firstOrFail()->update(['name' => 'Renamed']);

        $this->assertTaxonomyCold();
    }

    public function test_writing_an_attribute_invalidates_the_taxonomy_caches(): void
    {
        $this->warmTaxonomy();

        Attribute::query()->firstOrFail()->update(['name' => 'Renamed']);

        $this->assertTaxonomyCold();
    }

    public function test_writing_an_amenity_invalidates_the_taxonomy_caches(): void
    {
        $this->warmTaxonomy();

        Amenity::query()->firstOrFail()->update(['name' => 'Renamed']);

        $this->assertTaxonomyCold();
    }

    public function test_deleting_a_category_invalidates_its_attribute_cache(): void
    {
        // A leaf with no listings, so the delete is allowed.
        $category = Category::query()->where('is_leaf', true)->firstOrFail();
        Cache::put(CacheKeys::categoryAttributes($category->id), ['stale'], 600);

        $category->delete();

        $this->assertFalse(
            Cache::has(CacheKeys::categoryAttributes($category->id)),
            "A deleted category's attribute cache must not survive — its id is no longer discoverable, "
            .'so an enumerated flush could never reach it again.',
        );
    }

    // -------------------------------------------------------------- content

    public function test_writing_an_faq_invalidates_the_content_caches(): void
    {
        Cache::put(CacheKeys::FAQS, ['stale'], 600);

        Faq::query()->firstOrFail()->update(['answer' => 'Updated.']);

        $this->assertFalse(Cache::has(CacheKeys::FAQS));
    }

    public function test_writing_a_setting_invalidates_the_public_settings_cache(): void
    {
        Cache::put(CacheKeys::PUBLIC_SETTINGS, ['stale'], 600);

        Setting::query()->where('key', 'site.name')->firstOrFail()->update(['value' => 'SAKA TZ']);

        $this->assertFalse(Cache::has(CacheKeys::PUBLIC_SETTINGS));
    }

    // ------------------------------------------------------------ discovery

    public function test_publishing_a_listing_invalidates_discovery_and_the_seller_dashboard(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create();

        $this->warmDiscovery($seller->id);

        $listing->forceFill(['status' => ListingStatus::Published])->save();

        $this->assertFalse(Cache::has(CacheKeys::TRENDING));
        $this->assertFalse(Cache::has(CacheKeys::FEATURED));
        $this->assertFalse(Cache::has(CacheKeys::recommendations($seller->id)));
        $this->assertFalse(Cache::has(CacheKeys::recommendations(null)), 'The guest recommendation cache was left stale.');
        $this->assertFalse(Cache::has(CacheKeys::sellerDashboard($seller->id)));
    }

    public function test_featuring_a_listing_invalidates_discovery(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($seller)->published()->create();

        $this->warmDiscovery($seller->id);

        // is_featured is not fillable — featuring goes through the moderation
        // service, which forceFills. Mirror that here.
        $listing->forceFill(['is_featured' => true, 'featured_until' => now()->addWeek()])->save();

        $this->assertFalse(Cache::has(CacheKeys::FEATURED));
    }

    /**
     * The counter flush writes view counts on the hottest listings constantly.
     * If that dropped the trending cache, trending would effectively be
     * uncached under exactly the load it exists to absorb.
     */
    public function test_a_view_count_write_does_not_drop_the_discovery_cache(): void
    {
        $seller = User::factory()->seller()->create();
        // Draft on purpose: a published listing legitimately flushes discovery
        // via the status branch, which would mask the behaviour under test.
        $listing = Listing::factory()->ownedBy($seller)->status(ListingStatus::Draft)->create();

        $this->warmDiscovery($seller->id);

        $listing->forceFill(['view_count' => 500])->save();

        $this->assertTrue(
            Cache::has(CacheKeys::TRENDING),
            'A view-count write dropped the trending cache. Discovery must only be flushed when '
            .'status, is_featured, featured_until or popularity_score changed.',
        );

        // The owner's dashboard DOES show view counts, so that one must go.
        $this->assertFalse(Cache::has(CacheKeys::sellerDashboard($seller->id)));
    }

    // -------------------------------------------------------------- helpers

    private function warmTaxonomy(): void
    {
        Cache::put(CacheKeys::CATEGORY_TREE, ['stale'], 600);
        Cache::put(CacheKeys::AMENITIES, ['stale'], 600);
        Cache::put(CacheKeys::FACILITIES, ['stale'], 600);
        Cache::put(CacheKeys::categoryAttributes(1), ['stale'], 600);
        Cache::put(CacheKeys::categoryAttributes(2), ['stale'], 600);
    }

    private function assertTaxonomyCold(): void
    {
        $this->assertFalse(Cache::has(CacheKeys::CATEGORY_TREE));
        $this->assertFalse(Cache::has(CacheKeys::AMENITIES));
        $this->assertFalse(Cache::has(CacheKeys::FACILITIES));
        $this->assertFalse(Cache::has(CacheKeys::categoryAttributes(1)));
        $this->assertFalse(Cache::has(CacheKeys::categoryAttributes(2)));
    }

    private function warmDiscovery(int $sellerId): void
    {
        Cache::put(CacheKeys::TRENDING, ['stale'], 600);
        Cache::put(CacheKeys::FEATURED, ['stale'], 600);
        Cache::put(CacheKeys::recommendations($sellerId), ['stale'], 600);
        Cache::put(CacheKeys::recommendations(null), ['stale'], 600);
        Cache::put(CacheKeys::sellerDashboard($sellerId), ['stale'], 600);
    }
}
