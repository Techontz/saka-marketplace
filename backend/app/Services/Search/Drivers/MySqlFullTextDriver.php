<?php

declare(strict_types=1);

namespace App\Services\Search\Drivers;

use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * MVP search: the FULLTEXT(title, description) index on `listings`.
 *
 * Honest limits, recorded so the upgrade trigger is explicit rather than a
 * surprise: no typo tolerance, no synonyms, weak relevance ranking, and facet
 * counts get expensive somewhere past ~100k listings. Below that it is correct,
 * free, and one less moving part to operate.
 */
class MySqlFullTextDriver implements SearchDriver
{
    public function apply(Builder $builder, SearchQuery $query): Builder
    {
        if ($query->isEmpty()) {
            return $builder;
        }

        $expression = $query->toBooleanMode();

        if ($expression === '') {
            return $builder;
        }

        return $builder->whereRaw(
            'MATCH (listings.title, listings.description) AGAINST (? IN BOOLEAN MODE)',
            [$expression],
        );
    }

    /** No-op: MySQL maintains the FULLTEXT index itself on write. */
    public function index(int $listingId): void {}

    public function remove(int $listingId): void {}

    public function name(): string
    {
        return 'mysql-fulltext';
    }

    public function supportsRelevanceRanking(): bool
    {
        // MATCH() does produce a score, but without synonyms or typo tolerance
        // it ranks poorly enough that we do not advertise `relevance` as a sort
        // option yet. The Meilisearch driver will return true.
        return false;
    }
}
