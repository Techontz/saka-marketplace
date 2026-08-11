<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use Closure;

/**
 * Price range, in MINOR UNITS.
 *
 * Listings with a NULL price ("contact for price") are excluded whenever a
 * bound is supplied — an unpriced listing cannot honestly claim to be under a
 * ceiling.
 */
class PriceFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $min = $query->filters->minPrice;
        $max = $query->filters->maxPrice;

        if ($min === null && $max === null) {
            return $next($query);
        }

        $query->builder->whereNotNull('listings.price');

        if ($min !== null) {
            $query->builder->where('listings.price', '>=', $min);
        }

        if ($max !== null) {
            $query->builder->where('listings.price', '<=', $max);
        }

        return $next($query);
    }
}
