<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use Closure;

class VerifiedFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        if ($query->filters->verifiedOnly) {
            $query->builder->where('listings.is_verified', true);
        }

        if ($query->filters->featuredOnly) {
            $query->builder->where('listings.is_featured', true)
                ->where(function ($q): void {
                    $q->whereNull('listings.featured_until')
                        ->orWhere('listings.featured_until', '>', now());
                });
        }

        return $next($query);
    }
}
