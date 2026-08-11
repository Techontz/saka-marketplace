<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Closure;

/**
 * Delegates free-text to the SearchService, never to a concrete engine.
 * Swapping MySQL FULLTEXT for Meilisearch changes nothing here.
 */
class KeywordFilter
{
    public function __construct(private readonly SearchService $search) {}

    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        if ($query->filters->q === null) {
            return $next($query);
        }

        $query->builder = $this->search->apply(
            $query->builder,
            SearchQuery::fromRequest($query->filters->q),
        );

        return $next($query);
    }
}
