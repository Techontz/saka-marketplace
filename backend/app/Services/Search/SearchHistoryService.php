<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Listing;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recording searches, and the three features that fall out of it.
 *
 * Your recent searches, what everyone is searching for, and the ranking behind
 * suggestions are the same rows read three ways — which is why there is one
 * table rather than three.
 */
class SearchHistoryService
{
    /**
     * Queries too short or too generic to be worth remembering.
     *
     * Without this the popular list fills with single letters from people still
     * typing, because the frontend searches as you type.
     */
    private const MIN_LENGTH = 2;

    /** @param  array<string, mixed>  $filters */
    public function record(?User $user, ?string $sessionId, string $rawQuery, array $filters, int $resultsCount): void
    {
        $normalised = SearchQuery::normalise($rawQuery);

        if (mb_strlen($normalised) < self::MIN_LENGTH) {
            return;
        }

        // Repeating the same search — paging through results, or refining a
        // filter — should not stack up ten identical history rows. Within the
        // debounce window the previous row is updated instead.
        $recent = SearchQuery::query()
            ->where('query', $normalised)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->getKey()))
            ->when($user === null, fn ($q) => $q->whereNull('user_id')->where('session_id', $sessionId))
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('created_at')
            ->first();

        if ($recent !== null) {
            $recent->forceFill([
                'filters' => $filters ?: null,
                'results_count' => $resultsCount,
                'created_at' => now(),
            ])->save();

            return;
        }

        SearchQuery::create([
            'user_id' => $user?->getKey(),
            'session_id' => $sessionId,
            'query' => $normalised,
            'raw_query' => mb_substr(trim($rawQuery), 0, 200),
            'filters' => $filters ?: null,
            'results_count' => $resultsCount,
            'created_at' => now(),
        ]);
    }

    /**
     * This customer's own recent searches, most recent first, deduplicated.
     *
     * @return Collection<int, array{query: string, results_count: int, searched_at: mixed}>
     */
    public function historyFor(User $user, int $limit = 20): Collection
    {
        return SearchQuery::query()
            ->where('user_id', $user->getKey())
            ->selectRaw('`query`, MAX(raw_query) as raw_query, MAX(created_at) as searched_at, MAX(results_count) as results_count')
            ->groupBy('query')
            ->orderByDesc('searched_at')
            ->limit($limit)
            ->get()
            ->map(fn (SearchQuery $row): array => [
                'query' => $row->raw_query,
                'results_count' => (int) $row->results_count,
                'searched_at' => $row->getAttribute('searched_at'),
            ]);
    }

    public function clearHistoryFor(User $user): int
    {
        return SearchQuery::query()->where('user_id', $user->getKey())->delete();
    }

    /**
     * What everyone is searching for.
     *
     * Restricted to searches that FOUND something: suggesting a query that
     * returns an empty page is worse than suggesting nothing. Cached because
     * this is a group-by over a growing table rendered on the homepage.
     *
     * @return array<int, array{query: string, searches: int}>
     */
    public function popular(int $limit = 8, int $days = 30): array
    {
        return Cache::remember(
            "search:popular:{$limit}:{$days}",
            now()->addHour(),
            fn (): array => SearchQuery::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->where('results_count', '>', 0)
                ->selectRaw('`query`, MAX(raw_query) as raw_query, COUNT(*) as searches')
                ->groupBy('query')
                ->orderByDesc('searches')
                ->limit($limit)
                ->get()
                ->map(fn (SearchQuery $row): array => [
                    'query' => $row->raw_query,
                    'searches' => (int) $row->getAttribute('searches'),
                ])
                ->all(),
        );
    }

    /**
     * Listings this customer looked at, most recent first.
     *
     * Read from `listing_views`, which already records who viewed what — a
     * second "recently viewed" table would be the same data written twice and
     * would disagree with the view counters the moment one of them failed.
     *
     * @return Collection<int, Listing>
     */
    public function recentlyViewed(User $user, int $limit = 12): Collection
    {
        $ids = DB::table('listing_views')
            ->where('user_id', $user->getKey())
            ->selectRaw('listing_id, MAX(viewed_at) as last_viewed')
            ->groupBy('listing_id')
            ->orderByDesc('last_viewed')
            ->limit($limit)
            ->pluck('last_viewed', 'listing_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Listing::query()
            ->publiclyVisible()
            ->whereIn('id', $ids->keys())
            ->with(['category:id,name,slug,icon,parent_id', 'region:id,name,slug', 'district:id,name,slug', 'primaryMedia'])
            ->get()
            // Ordered in PHP: whereIn returns rows in index order, and a
            // "recently viewed" strip that is not in view order is just a list.
            ->sortByDesc(fn (Listing $listing): string => (string) $ids[$listing->getKey()])
            ->values();
    }
}
