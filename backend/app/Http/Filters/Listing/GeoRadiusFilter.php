<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Models\Listing;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Radius search.
 *
 * A bounding box is applied FIRST so the (latitude, longitude) index can be
 * used, and only the surviving rows pay for the Haversine term. Without the
 * prefilter the distance expression forces a full scan.
 *
 * `distance_km` is selected so the sort stage and the Resource can both read it
 * without recomputing.
 */
class GeoRadiusFilter
{
    private const EARTH_RADIUS_KM = 6371;

    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $filters = $query->filters;

        if (! $filters->hasGeo()) {
            return $next($query);
        }

        $lat = $filters->latitude;
        $lng = $filters->longitude;
        $radius = $filters->radiusKm;

        /** @var Builder<Listing> $builder */
        $builder = $query->builder;

        $builder
            ->whereNotNull('listings.latitude')
            ->whereNotNull('listings.longitude')
            ->withinBoundingBox($lat, $lng, $radius)
            ->selectRaw(
                'listings.*, ('.self::EARTH_RADIUS_KM.' * acos(
                    least(1.0, greatest(-1.0,
                        cos(radians(?)) * cos(radians(listings.latitude))
                        * cos(radians(listings.longitude) - radians(?))
                        + sin(radians(?)) * sin(radians(listings.latitude))
                    ))
                )) as distance_km',
                [$lat, $lng, $lat],
            )
            ->havingRaw('distance_km <= ?', [$radius]);

        $query->builder = $builder;

        return $next($query);
    }
}
