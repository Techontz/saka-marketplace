<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Models\District;
use App\Models\Region;
use App\Models\Ward;
use Closure;

/**
 * Region > District > Ward. The narrowest supplied level wins; the broader ones
 * are redundant once a narrower one is known.
 *
 * `place` is the free-text form of the same idea, for a UI with one "where?"
 * box rather than three selects. It matches a name prefix at any level, so
 * "Masaki" (a ward), "Ilala" (a district) and "Dar" (a region) all work without
 * the user knowing which is which.
 */
class LocationFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $filters = $query->filters;

        if ($filters->wardSlug !== null) {
            $ward = Ward::query()->where('slug', $filters->wardSlug)->first();
            $ward === null
                ? $query->builder->whereRaw('1 = 0')
                : $query->builder->where('listings.ward_id', $ward->id);

            return $next($query);
        }

        if ($filters->districtSlug !== null) {
            $district = District::query()->where('slug', $filters->districtSlug)->first();
            $district === null
                ? $query->builder->whereRaw('1 = 0')
                : $query->builder->where('listings.district_id', $district->id);

            return $next($query);
        }

        if ($filters->regionSlug !== null) {
            $region = Region::query()->where('slug', $filters->regionSlug)->first();
            $region === null
                ? $query->builder->whereRaw('1 = 0')
                : $query->builder->where('listings.region_id', $region->id);

            return $next($query);
        }

        if ($filters->place !== null) {
            $this->applyPlaceSearch($query, $filters->place);
        }

        return $next($query);
    }

    /**
     * Prefix match, not `%term%`.
     *
     * A leading wildcard cannot use the index on `name`, which turns every
     * keystroke in a location box into a full scan of the location tables. A
     * prefix still matches the way people type a place name.
     */
    private function applyPlaceSearch(ListingQuery $query, string $place): void
    {
        $term = str_replace(['%', '_'], ['\\%', '\\_'], $place).'%';

        $wardIds = Ward::query()->where('name', 'like', $term)->pluck('id');
        $districtIds = District::query()->where('name', 'like', $term)->pluck('id');
        $regionIds = Region::query()->where('name', 'like', $term)->pluck('id');

        if ($wardIds->isEmpty() && $districtIds->isEmpty() && $regionIds->isEmpty()) {
            // Also allow matching the free-text address a seller typed, which
            // is where neighbourhoods that are not wards ("Posta") live.
            $query->builder->where('listings.address_line', 'like', $term);

            return;
        }

        $query->builder->where(function ($builder) use ($wardIds, $districtIds, $regionIds, $term): void {
            $builder->where('listings.address_line', 'like', $term);

            if ($wardIds->isNotEmpty()) {
                $builder->orWhereIn('listings.ward_id', $wardIds);
            }

            if ($districtIds->isNotEmpty()) {
                $builder->orWhereIn('listings.district_id', $districtIds);
            }

            if ($regionIds->isNotEmpty()) {
                $builder->orWhereIn('listings.region_id', $regionIds);
            }
        });
    }
}
