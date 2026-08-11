<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Engagement\CustomerNotifier;
use App\Services\Seller\SellerCounterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listing creation and mutation.
 *
 * Owns the transaction boundary: a listing, its EAV values and its taxonomy
 * pivots are written together or not at all. A half-written listing with no
 * required attributes would be invisible to every filter.
 */
class ListingService
{
    public function __construct(
        private readonly ListingIndexer $indexer,
        private readonly CustomerNotifier $notifier,
        private readonly SellerCounterService $counters,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $seller, array $data): Listing
    {
        $category = $this->resolveLeafCategory((int) $data['category_id']);

        $created = DB::transaction(function () use ($seller, $category, $data): Listing {
            $this->ensureSellerRole($seller);

            $listing = new Listing($this->fillable($data));
            $listing->user_id = $seller->getKey();
            $listing->category_id = $category->id;
            $listing->slug = $this->uniqueSlug((string) $data['title']);
            $listing->status = ListingStatus::Draft;
            $listing->currency = $data['currency'] ?? config('saka.default_currency');
            $listing->save();

            $this->syncAttributes($listing, $category, (array) ($data['attributes'] ?? []));
            $this->syncTaxonomies($listing, $data);

            $this->indexer->index($listing);

            return $listing->fresh();
        });

        // A new listing changes `total_listings` even before it is published.
        $this->counters->recount((int) $created->user_id);

        return $created;
    }

    /** @param array<string, mixed> $data */
    public function update(Listing $listing, array $data): Listing
    {
        $category = isset($data['category_id'])
            ? $this->resolveLeafCategory((int) $data['category_id'])
            : $listing->category;

        // Captured before the write: everyone who saved this listing is told
        // when the price moves, and afterwards the old value is gone.
        $priceBefore = $listing->price;
        $wasPublished = $listing->isPublished();

        $updated = DB::transaction(function () use ($listing, $category, $data): Listing {
            $listing->fill($this->fillable($data));
            $listing->category_id = $category->id;

            // The slug is part of a public URL; changing it silently would break
            // inbound links and any bookmark. Only regenerate while unpublished.
            if (isset($data['title']) && $listing->published_at === null) {
                $listing->slug = $this->uniqueSlug((string) $data['title'], $listing->id);
            }

            $listing->save();

            if (array_key_exists('attributes', $data)) {
                $this->syncAttributes($listing, $category, (array) $data['attributes']);
            }

            $this->syncTaxonomies($listing, $data);

            // Editing a published listing sends it back for review; otherwise a
            // seller could publish innocuous copy and swap it afterwards.
            if ($listing->status === ListingStatus::Published
                && config('saka.listings.require_moderation')
                && $this->touchesReviewableContent($data)) {
                $listing->forceFill(['status' => ListingStatus::PendingReview])->save();
            }

            $this->indexer->index($listing);

            return $listing->fresh();
        });

        /*
         * Watchers are told about a price change only on a listing that was
         * already public. A price edited while the listing sat in draft is not
         * news to anyone, and could not have been saved by them anyway.
         */
        if ($wasPublished) {
            $this->notifier->favoritePriceChanged($updated, $priceBefore, $updated->price);
        }

        return $updated;
    }

    public function delete(Listing $listing): void
    {
        DB::transaction(function () use ($listing): void {
            $this->indexer->remove($listing);
            $listing->delete(); // soft delete — history and inquiries survive
        });

        $this->counters->recount((int) $listing->user_id);
    }

    /**
     * Writes the EAV rows for a listing.
     *
     * Required attributes are enforced from category_attribute, so a new
     * vertical gets validation without a line of new code.
     *
     * @param  array<string, mixed>  $values  attribute code => value
     */
    public function syncAttributes(Listing $listing, Category $category, array $values): void
    {
        $definitions = $category->resolvedAttributes()->keyBy('code');

        foreach ($definitions as $code => $attribute) {
            $isRequired = (bool) ($attribute->getAttribute('is_required') ?? false);
            $provided = $values[$code] ?? null;

            if ($isRequired && ($provided === null || $provided === '' || $provided === [])) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    "The attribute [{$code}] is required for {$category->name}.",
                    ['attribute' => $code],
                );
            }
        }

        $listing->attributeValues()->delete();

        foreach ($values as $code => $value) {
            $attribute = $definitions->get($code);

            if ($attribute === null || $value === null || $value === '' || $value === []) {
                continue;
            }

            foreach ($this->normaliseValues($attribute, $value) as $row) {
                // The value column (value_string / value_int / ...) is chosen
                // at runtime from the attribute's data type, so this payload is
                // dynamic by design and cannot be a literal fillable array.
                (new ListingAttributeValue)
                    ->forceFill(array_merge($row, [
                        'listing_id' => $listing->id,
                        'attribute_id' => $attribute->id,
                    ]))
                    ->save();
            }
        }
    }

    /**
     * One row per value: a multiselect stores several, everything else one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseValues(Attribute $attribute, mixed $value): array
    {
        $column = $attribute->data_type->column();

        if ($attribute->input_type->expectsOptions()) {
            $incoming = is_array($value) ? $value : [$value];
            $options = $attribute->options()->whereIn('value', $incoming)->get();

            if ($options->isEmpty()) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    "Invalid option for attribute [{$attribute->code}].",
                    ['attribute' => $attribute->code],
                );
            }

            return $options->map(fn (AttributeOption $option) => [
                'attribute_option_id' => $option->id,
                'value_string' => $option->value,
            ])->all();
        }

        return [[$column => $this->cast($attribute, $value)]];
    }

    private function cast(Attribute $attribute, mixed $value): mixed
    {
        return match ($attribute->data_type) {
            AttributeDataType::Integer => (int) $value,
            AttributeDataType::Decimal => (float) $value,
            AttributeDataType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            AttributeDataType::Date => $value,
            default => (string) $value,
        };
    }

    /** @param array<string, mixed> $data */
    private function syncTaxonomies(Listing $listing, array $data): void
    {
        if (array_key_exists('amenities', $data)) {
            $listing->amenities()->sync((array) $data['amenities']);
        }

        if (array_key_exists('facilities', $data)) {
            $listing->facilities()->sync((array) $data['facilities']);
        }
    }

    private function resolveLeafCategory(int $categoryId): Category
    {
        $category = Category::query()->find($categoryId);

        if ($category === null || ! $category->is_active) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'That category is not available.');
        }

        // Only leaves hold listings — otherwise "Property" and "Apartments"
        // would compete for the same inventory and every count would be wrong.
        if (! $category->is_leaf) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'Choose a specific subcategory rather than a top-level category.',
                ['category' => $category->slug],
            );
        }

        return $category;
    }

    /** A seller profile is created lazily on first listing. */
    private function ensureSellerRole(User $seller): void
    {
        if (! $seller->hasRole(RoleSlug::Seller->value)) {
            $seller->assignRole(RoleSlug::Seller->value);
        }

        if ($seller->sellerProfile === null) {
            $display = $seller->fullName() !== '' ? $seller->fullName() : 'Seller';

            SellerProfile::create([
                'user_id' => $seller->getKey(),
                'display_name' => $display,
                'slug' => $this->uniqueSellerSlug($display),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title', 'description', 'purpose', 'price', 'currency', 'price_unit',
            'is_negotiable', 'condition', 'region_id', 'district_id', 'ward_id',
            'address_line', 'postal_code', 'latitude', 'longitude', 'available_from',
        ]));
    }

    /** @param array<string, mixed> $data */
    private function touchesReviewableContent(array $data): bool
    {
        return array_intersect(array_keys($data), ['title', 'description', 'price', 'category_id']) !== [];
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 180, ''));
        $base = $base !== '' ? $base : 'listing';

        // A short random suffix rather than an incrementing counter: the counter
        // approach leaks how many similar listings exist and races under load.
        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
            $exists = Listing::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();
        } while ($exists);

        return $slug;
    }

    private function uniqueSellerSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'seller';

        do {
            $slug = $base.'-'.Str::lower(Str::random(5));
        } while (SellerProfile::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
