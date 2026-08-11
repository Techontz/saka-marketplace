<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use Closure;

class PurposeFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        if ($query->filters->purpose !== null) {
            $query->builder->where('listings.purpose', $query->filters->purpose->value);
        }

        return $next($query);
    }
}
