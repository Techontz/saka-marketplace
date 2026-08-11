<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\Listing;
use App\Services\Search\SearchService;

/**
 * Keeps `listings.search_document` and the search engine in step.
 *
 * The projection is written even though the MySQL driver never reads it —
 * populating it from MVP means adopting Meilisearch is a reindex rather than a
 * backfill of a column that was never maintained.
 */
class ListingIndexer
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Listing $listing): void
    {
        $listing->forceFill([
            'search_document' => $this->search->buildDocument($listing),
        ])->saveQuietly();

        $this->search->index($listing);
    }

    public function remove(Listing $listing): void
    {
        $this->search->remove($listing);
    }
}
