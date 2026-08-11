<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every cached endpoint must survive a WARM cache, not just a cold one.
 *
 * This file exists because none of them did.
 *
 * The controllers cached Eloquent collections. On the first request that is
 * harmless — `Cache::remember()` returns the closure's value directly, so the
 * models never round-trip. On every request after that, the value is read back
 * from Redis, and unserialising a model graph in a fresh process can yield
 * `__PHP_Incomplete_Class`. The result was a 500 on the SECOND and every
 * subsequent request to /categories, /amenities, /facilities,
 * /locations/regions, /public-places/categories and the category attribute
 * endpoint. In other words: the entire taxonomy API, permanently broken in
 * production the moment its cache warmed.
 *
 * It went unnoticed because both of the obvious safety nets look away:
 *
 *   - the first request always passes, so any manual check passes;
 *   - `phpunit.xml` pins CACHE_STORE=array, and the array store stores the
 *     value in a PHP array without ever serialising it. Every existing test
 *     therefore exercised the cold path twice and never the warm one.
 *
 * So these tests do two things the others do not: they hit each endpoint TWICE,
 * and they do it against a store that actually serialises.
 */
class CachedEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * Endpoints whose responses are cached.
     *
     * @return array<int, array{0: string}>
     */
    public static function cachedEndpoints(): array
    {
        return [
            ['/api/v1/categories'],
            ['/api/v1/categories/property/attributes'],
            ['/api/v1/amenities'],
            ['/api/v1/facilities'],
            ['/api/v1/locations/regions'],
            ['/api/v1/faqs'],
            ['/api/v1/public-places/categories'],
            ['/api/v1/settings/public'],
        ];
    }

    #[Test]
    #[DataProvider('cachedEndpoints')]
    public function a_cached_endpoint_survives_a_warm_cache(string $uri): void
    {
        $this->useSerializingCache();

        $cold = $this->getJson($uri);
        $cold->assertOk();

        // The read that used to explode.
        $warm = $this->getJson($uri);
        $warm->assertOk();

        $this->assertSame(
            $cold->json('data'),
            $warm->json('data'),
            "[{$uri}] returned different data from the cache than it computed. ".
            'A cached response must be identical to the one it replaced.',
        );
    }

    #[Test]
    public function nothing_cached_is_an_eloquent_object(): void
    {
        $this->useSerializingCache();

        $this->getJson('/api/v1/categories')->assertOk();

        $cached = Cache::get('categories:tree');

        $this->assertIsArray(
            $cached,
            'The category tree is cached as an object. Cache the rendered array instead — '.
            'a serialised model graph comes back as __PHP_Incomplete_Class in another process.',
        );

        // A rendered resource is arrays all the way down; a model graph is not.
        $this->assertIsArray($cached[0] ?? null);
        $this->assertArrayHasKey('slug', $cached[0]);
    }

    /**
     * Swap the test-suite `array` store for one that serialises.
     *
     * `database` is used rather than `redis` so the test does not depend on a
     * Redis instance being present, and it reproduces the bug identically:
     * anything stored goes through serialize()/unserialize().
     */
    private function useSerializingCache(): void
    {
        config(['cache.default' => 'database']);
        Cache::purge('database');
        Cache::flush();
    }
}
