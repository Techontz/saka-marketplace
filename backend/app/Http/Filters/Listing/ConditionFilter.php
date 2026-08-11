<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use Closure;

class ConditionFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        if ($query->filters->condition !== null) {
            $query->builder->where('listings.condition', $query->filters->condition->value);
        }

        return $next($query);
    }
}
