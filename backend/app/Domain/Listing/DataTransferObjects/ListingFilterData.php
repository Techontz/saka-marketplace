<?php

declare(strict_types=1);

namespace App\Domain\Listing\DataTransferObjects;

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;

/**
 * Validated, immutable filter input for the listing query.
 *
 * Built ONCE from a validated request; every pipeline stage reads it and none
 * of them touch the raw request. That is what keeps a filter from silently
 * depending on an unvalidated input.
 */
final readonly class ListingFilterData
{
    /**
     * @param  array<int, string>  $amenities  slugs
     * @param  array<int, string>  $facilities  slugs
     * @param  array<string, mixed>  $attributes  code => scalar | ['min'=>, 'max'=>] | list
     */
    public function __construct(
        public ?string $q = null,
        public ?string $categorySlug = null,
        public ?string $subcategorySlug = null,
        public ?string $regionSlug = null,
        public ?string $districtSlug = null,
        public ?string $wardSlug = null,
        /** Free-text place name, matched across ward/district/region. */
        public ?string $place = null,
        public ?int $minPrice = null,
        public ?int $maxPrice = null,
        public ?ListingPurpose $purpose = null,
        public ?ListingCondition $condition = null,
        public bool $verifiedOnly = false,
        public bool $featuredOnly = false,
        public array $amenities = [],
        public array $facilities = [],
        public array $attributes = [],
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $radiusKm = null,
        public string $sort = 'newest',
        public int $perPage = 20,
        public ?int $sellerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $perPage = (int) ($validated['per_page'] ?? config('saka.pagination.default_per_page'));
        $perPage = max(1, min($perPage, (int) config('saka.pagination.max_per_page')));

        return new self(
            q: self::str($validated['q'] ?? null),
            categorySlug: self::str($validated['category'] ?? null),
            subcategorySlug: self::str($validated['subcategory'] ?? null),
            regionSlug: self::str($validated['region'] ?? null),
            districtSlug: self::str($validated['district'] ?? null),
            wardSlug: self::str($validated['ward'] ?? null),
            place: self::str($validated['place'] ?? null),
            minPrice: isset($validated['min_price']) ? (int) $validated['min_price'] : null,
            maxPrice: isset($validated['max_price']) ? (int) $validated['max_price'] : null,
            purpose: isset($validated['purpose']) ? ListingPurpose::tryFrom((string) $validated['purpose']) : null,
            condition: isset($validated['condition']) ? ListingCondition::tryFrom((string) $validated['condition']) : null,
            verifiedOnly: filter_var($validated['verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            featuredOnly: filter_var($validated['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
            amenities: array_values(array_filter((array) ($validated['amenities'] ?? []))),
            facilities: array_values(array_filter((array) ($validated['facilities'] ?? []))),
            attributes: (array) ($validated['attributes'] ?? []),
            latitude: isset($validated['lat']) ? (float) $validated['lat'] : null,
            longitude: isset($validated['lng']) ? (float) $validated['lng'] : null,
            radiusKm: isset($validated['radius']) ? (float) $validated['radius'] : null,
            sort: (string) ($validated['sort'] ?? 'newest'),
            perPage: $perPage,
        );
    }

    public function forSeller(int $sellerId): self
    {
        return new self(
            $this->q, $this->categorySlug, $this->subcategorySlug,
            $this->regionSlug, $this->districtSlug, $this->wardSlug, $this->place,
            $this->minPrice, $this->maxPrice, $this->purpose, $this->condition,
            $this->verifiedOnly, $this->featuredOnly, $this->amenities,
            $this->facilities, $this->attributes, $this->latitude,
            $this->longitude, $this->radiusKm, $this->sort, $this->perPage,
            $sellerId,
        );
    }

    public function hasGeo(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && $this->radiusKm !== null;
    }

    /** Stable key for caching a result page. */
    public function cacheKey(): string
    {
        return 'listings:'.md5(serialize(get_object_vars($this)));
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
