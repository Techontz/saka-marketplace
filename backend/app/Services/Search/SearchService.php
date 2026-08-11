<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Listing;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only thing application code talks to.
 *
 * Controllers depend on this, never on a driver. Swapping the engine is a
 * config change (`saka.search.driver`) plus a new SearchDriver implementation.
 */
class SearchService
{
    public function __construct(private readonly SearchDriver $driver) {}

    public function apply(Builder $builder, SearchQuery $query): Builder
    {
        return $this->driver->apply($builder, $query);
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    public function supportsRelevanceRanking(): bool
    {
        return $this->driver->supportsRelevanceRanking();
    }

    public function index(Listing $listing): void
    {
        $this->driver->index($listing->id);
    }

    public function remove(Listing $listing): void
    {
        $this->driver->remove($listing->id);
    }

    /**
     * Builds the flattened projection stored on `listings.search_document`.
     *
     * Populated from MVP even though the MySQL driver does not read it: doing
     * it now means introducing Meilisearch is a reindex, not a backfill of a
     * column that was never written.
     *
     * @return array<string, mixed>
     */
    public function buildDocument(Listing $listing): array
    {
        $listing->loadMissing(['category', 'region', 'district', 'ward', 'attributeValues.attribute']);

        $attributes = [];
        foreach ($listing->attributeValues as $value) {
            $code = $value->attribute?->code;
            if ($code !== null) {
                $attributes[$code] = $value->value();
            }
        }

        return [
            'title' => $listing->title,
            'description' => $listing->description,
            'category' => $listing->category?->name,
            'category_path' => $listing->category?->path,
            'purpose' => $listing->purpose?->value,
            'condition' => $listing->condition?->value,
            'price' => $listing->price,
            'currency' => $listing->currency,
            'region' => $listing->region?->name,
            'district' => $listing->district?->name,
            'ward' => $listing->ward?->name,
            'latitude' => $listing->latitude !== null ? (float) $listing->latitude : null,
            'longitude' => $listing->longitude !== null ? (float) $listing->longitude : null,
            'is_verified' => $listing->is_verified,
            'is_featured' => $listing->is_featured,
            'attributes' => $attributes,
            'published_at' => $listing->published_at?->toAtomString(),
        ];
    }
}
