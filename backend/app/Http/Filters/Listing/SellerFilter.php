<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use Closure;

/** Scopes to one seller — used by the seller dashboard and storefronts. */
class SellerFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        if ($query->filters->sellerId !== null) {
            $query->builder->where('listings.user_id', $query->filters->sellerId);
        }

        return $next($query);
    }
}
