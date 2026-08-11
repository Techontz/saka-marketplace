<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The seam that keeps Meilisearch out of the controllers.
 *
 * MVP ships MySqlFullTextDriver. When Meilisearch lands (v1.1) it implements
 * this same interface and is swapped by a single config value — no controller,
 * route, Resource or response shape changes.
 *
 * `apply()` returns a Builder rather than a result set so search composes with
 * the rest of the filter pipeline (category, price, location, EAV facets)
 * instead of competing with it.
 */
interface SearchDriver
{
    /**
     * Narrow the given builder by the query's free-text term.
     * Must be a no-op when the term is empty.
     */
    public function apply(Builder $builder, SearchQuery $query): Builder;

    /**
     * Keep the engine's copy of a record in step. A no-op for MySQL FULLTEXT
     * (the index is maintained by the database itself); the Meilisearch driver
     * will push the document.
     */
    public function index(int $listingId): void;

    public function remove(int $listingId): void;

    /** Identifier for logs, health checks and the admin panel. */
    public function name(): string;

    /** Whether this driver can rank by relevance; drives the sort whitelist. */
    public function supportsRelevanceRanking(): bool;
}
