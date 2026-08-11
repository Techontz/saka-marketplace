<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Models\Amenity;
use App\Models\Facility;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Amenities and facilities, AND-combined.
 *
 * A user asking for "pool AND gym" wants both, not either — so each slug adds
 * its own EXISTS rather than one whereIn, which would be an OR.
 */
class AmenityFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        foreach ($this->resolve(Amenity::class, $query->filters->amenities) as $amenityId) {
            $query->builder->whereExists(
                fn (QueryBuilder $q) => $q->selectRaw('1')
                    ->from('listing_amenity')
                    ->whereColumn('listing_amenity.listing_id', 'listings.id')
                    ->where('listing_amenity.amenity_id', $amenityId),
            );
        }

        foreach ($this->resolve(Facility::class, $query->filters->facilities) as $facilityId) {
            $query->builder->whereExists(
                fn (QueryBuilder $q) => $q->selectRaw('1')
                    ->from('listing_facility')
                    ->whereColumn('listing_facility.listing_id', 'listings.id')
                    ->where('listing_facility.facility_id', $facilityId),
            );
        }

        return $next($query);
    }

    /**
     * @param  class-string  $model
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    private function resolve(string $model, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $model::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }
}
