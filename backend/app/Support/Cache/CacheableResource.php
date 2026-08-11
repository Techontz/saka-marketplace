<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Renders an API Resource to a plain, fully-nested array so it can be cached.
 *
 * WHY THIS IS NOT JUST `->resolve()`
 *
 * `resolve()` renders one level. A resource whose `toArray()` embeds another
 * resource — `CategoryResource` nesting `self::collection($this->children)`,
 * which is most of this API's tree-shaped payloads — comes back as an array
 * whose values are still Resource OBJECTS.
 *
 * Cache that and the failure is genuinely nasty:
 *
 *   - the cold request works, because `Cache::remember()` hands back the
 *     closure's value without a round trip;
 *   - the warm request returns HTTP **200** with a body in which every nested
 *     collection has become
 *     `{"__PHP_Incomplete_Class_Name":"...AnonymousResourceCollection", ...}`.
 *
 * So the category browser loses every subcategory the moment the cache warms,
 * and nothing anywhere reports an error. A status-code health check sees 200. A
 * cold-cache test sees correct data. Only a warm read of the actual body shows
 * it, which is what `CachedEndpointsTest` now does.
 *
 * Going through JSON is the fix precisely because it is total: it forces the
 * whole graph, at every depth, through each resource's own serialisation, and
 * what comes out is arrays and scalars with nothing left to unserialise. It
 * runs once per cache WRITE, not per read.
 */
final class CacheableResource
{
    /**
     * @return array<mixed>
     */
    public static function render(JsonResource|ResourceCollection $resource): array
    {
        /** @var array<mixed> $decoded */
        $decoded = json_decode($resource->toJson(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
