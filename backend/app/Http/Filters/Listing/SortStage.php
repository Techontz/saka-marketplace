<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Services\Search\SearchService;
use Closure;

/**
 * Sorting, from a strict whitelist.
 *
 * Never interpolates client input into ORDER BY. `distance` is only honoured
 * when a geo filter actually selected `distance_km`, and `relevance` only when
 * the active search driver can rank — otherwise both silently fall back to the
 * default rather than emitting invalid SQL.
 *
 * Featured listings float to the top of every ordering except distance: that is
 * the promotion product (v1.1) whose columns already exist.
 */
class SortStage
{
    private const ALLOWED = ['newest', 'oldest', 'price_asc', 'price_desc', 'popularity', 'distance', 'relevance'];

    public function __construct(private readonly SearchService $search) {}

    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $sort = in_array($query->filters->sort, self::ALLOWED, true)
            ? $query->filters->sort
            : 'newest';

        if ($sort === 'distance' && ! $query->filters->hasGeo()) {
            $sort = 'newest';
        }

        if ($sort === 'relevance' && ! $this->search->supportsRelevanceRanking()) {
            $sort = 'newest';
        }

        if ($sort !== 'distance') {
            $query->builder->orderByDesc('listings.is_featured');
        }

        match ($sort) {
            'oldest' => $query->builder->orderBy('listings.published_at'),
            'price_asc' => $query->builder->orderByRaw('listings.price IS NULL, listings.price ASC'),
            'price_desc' => $query->builder->orderByRaw('listings.price IS NULL, listings.price DESC'),
            'popularity' => $query->builder->orderByDesc('listings.popularity_score'),
            'distance' => $query->builder->orderBy('distance_km'),
            default => $query->builder->orderByDesc('listings.published_at'),
        };

        // Deterministic tiebreak: without it, pagination can repeat or skip rows
        // when many listings share a timestamp.
        $query->builder->orderByDesc('listings.id');

        return $next($query);
    }
}
