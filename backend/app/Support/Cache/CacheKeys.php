<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;
use Predis\ClientInterface;
use Predis\Command\Processor\KeyPrefixProcessor;

/**
 * Every cache key in the application, in one place.
 *
 * Population and invalidation must agree on the key. When each side writes its
 * own string they drift, and the failure is silent — stale data with nothing in
 * the logs. Both sides now call the same method.
 */
final class CacheKeys
{
    // ------------------------------------------------------------- taxonomy
    public const CATEGORY_TREE = 'categories:tree';

    public const AMENITIES = 'taxonomy:amenities';

    public const FACILITIES = 'taxonomy:facilities';

    public const REGIONS = 'locations:regions';

    // -------------------------------------------------------------- content
    public const FAQS = 'cms:faqs';

    public const PUBLIC_SETTINGS = 'settings:public';

    public const PLACE_CATEGORIES = 'public_places:categories';

    // ---------------------------------------------------------- discovery
    public const TRENDING = 'listings:trending';

    public const FEATURED = 'listings:featured';

    public const METRICS = 'metrics:snapshot';

    public static function categoryAttributes(int $categoryId): string
    {
        return "category:{$categoryId}:attributes";
    }

    public static function districtsOfRegion(int $regionId): string
    {
        return "locations:region:{$regionId}:districts";
    }

    public static function wardsOfDistrict(int $districtId): string
    {
        return "locations:district:{$districtId}:wards";
    }

    public static function sellerDashboard(int $userId): string
    {
        return "seller:{$userId}:dashboard";
    }

    public static function recommendations(?int $userId): string
    {
        return 'listings:recommended:'.($userId ?? 'guest');
    }

    /**
     * Everything derived from the category/attribute taxonomy.
     *
     * Per-category attribute keys are wildcarded rather than enumerated: after
     * a category is deleted its id is no longer discoverable, so an enumerated
     * flush would leave that key behind forever.
     */
    public static function flushTaxonomy(): void
    {
        Cache::forget(self::CATEGORY_TREE);
        Cache::forget(self::AMENITIES);
        Cache::forget(self::FACILITIES);
        self::forgetPattern('category:*:attributes');
    }

    public static function flushLocations(): void
    {
        Cache::forget(self::REGIONS);
        self::forgetPattern('locations:*');
    }

    public static function flushContent(): void
    {
        Cache::forget(self::FAQS);
        Cache::forget(self::PUBLIC_SETTINGS);
        Cache::forget(self::PLACE_CATEGORIES);
    }

    /** Discovery surfaces, which every listing publish/unpublish changes. */
    public static function flushDiscovery(): void
    {
        Cache::forget(self::TRENDING);
        Cache::forget(self::FEATURED);
        self::forgetPattern('listings:recommended:*');
    }

    /**
     * Wildcard delete.
     *
     * Uses SCAN, never KEYS: KEYS blocks the whole Redis instance for the
     * duration of the scan, which on a warm cache is a production incident.
     * Falls back to a no-op on stores that cannot enumerate (array/file), where
     * the short TTLs are the safety net instead.
     *
     * The prefix handling is the subtle part, and an earlier version got it
     * wrong badly enough that this method silently deleted NOTHING.
     *
     * A cached key carries TWO prefixes:
     *   - the cache store's   (`cache.prefix`, e.g. `saka-cache-`)
     *   - the redis connection's (`database.redis.options.prefix`, e.g.
     *     `saka-database-`), which the client applies transparently.
     *
     * SCAN is the exception to "transparently": its MATCH pattern is sent raw
     * and its results come back raw. So the pattern needs BOTH prefixes, while
     * the keys handed to del() need the connection prefix stripped again —
     * otherwise the client re-applies it and you delete `saka-database-
     * saka-database-…`, which does not exist.
     */
    public static function forgetPattern(string $pattern): void
    {
        $store = Cache::getStore();

        if (! method_exists($store, 'connection')) {
            return;
        }

        try {
            $redis = $store->connection();
            $connectionPrefix = self::connectionPrefix($redis);
            $match = $connectionPrefix.$store->getPrefix().$pattern;
            $cursor = null;

            do {
                [$cursor, $keys] = $redis->scan($cursor ?? 0, ['match' => $match, 'count' => 500]);

                if (! empty($keys)) {
                    $redis->del(array_map(
                        static fn (string $key): string => $connectionPrefix === ''
                            ? $key
                            : substr($key, strlen($connectionPrefix)),
                        $keys,
                    ));
                }
            } while ((int) $cursor !== 0);
        } catch (\Throwable) {
            // Cache invalidation must never take a request down; the TTL will
            // clear it shortly.
        }
    }

    /** Predis and phpredis expose the connection prefix differently. */
    private static function connectionPrefix(mixed $connection): string
    {
        $client = method_exists($connection, 'client') ? $connection->client() : null;

        if ($client instanceof ClientInterface) {
            // Predis exposes the prefix as a command PROCESSOR, not a string.
            // Casting it happens to work through __toString(), but asking the
            // processor for its prefix is the typed, non-accidental route.
            $processor = $client->getOptions()->prefix;

            return $processor instanceof KeyPrefixProcessor ? $processor->getPrefix() : '';
        }

        if ($client instanceof \Redis || $client instanceof \RedisCluster) {
            return (string) $client->getOption(\Redis::OPT_PREFIX);
        }

        return (string) config('database.redis.options.prefix', '');
    }
}
