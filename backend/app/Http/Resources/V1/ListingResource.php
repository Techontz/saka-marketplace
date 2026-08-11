<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 *
 * @property-read float|null $distance_km computed select alias, geo filters only
 *
 * One resource, two shapes. The list shape stays deliberately small — a 20-item
 * page should not carry every amenity and EAV value — while `->detailed()` adds
 * everything the detail page needs.
 *
 * Every field is whitelisted. A model accessor added later must never start
 * leaking into responses on its own.
 */
class ListingResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): self
    {
        $this->detailed = $detailed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,

            'price' => $this->price !== null ? [
                'amount' => (int) $this->price,
                'currency' => $this->currency,
                'unit' => $this->price_unit?->value,
                'is_negotiable' => (bool) $this->is_negotiable,
            ] : null,

            'purpose' => $this->purpose?->value,
            'condition' => $this->condition?->value,
            'status' => $this->status->value,

            'is_verified' => (bool) $this->is_verified,
            'is_featured' => (bool) $this->is_featured,

            'category' => $this->whenLoaded('category', fn () => [
                'slug' => $this->category->slug,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                // Null for a listing attached directly to a root category.
                'parent' => $this->category->relationLoaded('parent') && $this->category->parent !== null
                    ? [
                        'slug' => $this->category->parent->slug,
                        'name' => $this->category->parent->name,
                        'icon' => $this->category->parent->icon,
                    ]
                    : null,
            ]),

            'location' => [
                // Slugs alongside names: a name is for reading, a slug is what
                // every location endpoint and every write is addressed by.
                'region' => $this->whenLoaded('region', fn () => $this->region?->name),
                'region_slug' => $this->whenLoaded('region', fn () => $this->region?->slug),
                'district' => $this->whenLoaded('district', fn () => $this->district?->name),
                'district_slug' => $this->whenLoaded('district', fn () => $this->district?->slug),
                'ward' => $this->whenLoaded('ward', fn () => $this->ward?->name),
                'ward_slug' => $this->whenLoaded('ward', fn () => $this->ward?->slug),
                'address_line' => $this->address_line,
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            ],

            // Only present when a geo filter computed it.
            'distance_km' => $this->when(
                isset($this->distance_km),
                fn () => round((float) $this->distance_km, 2),
            ),

            'primary_image' => $this->whenLoaded(
                'primaryMedia',
                fn () => $this->primaryMedia ? new MediaResource($this->primaryMedia) : null,
            ),

            'stats' => [
                'views' => (int) $this->view_count,
                'favorites' => (int) $this->favorite_count,
                'inquiries' => (int) $this->inquiry_count,
            ],

            // A flat code => value map of the listing's EAV values, present on
            // LIST responses too. A card renders beds/bathrooms/area, so
            // without this every client would have to fetch each listing's
            // detail to draw a results page.
            //
            // Read from the `search_document` projection, which already lives
            // on the row — so this costs no extra query and no join, unlike
            // eager-loading attributeValues for every card on the page.
            'attributes' => $this->attributeMap(),

            'published_at' => $this->published_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];

        if (! $this->detailed) {
            return $data;
        }

        return array_merge($data, [
            'description' => $this->description,
            'postal_code' => $this->postal_code,
            'available_from' => $this->available_from?->toDateString(),
            'expires_at' => $this->expires_at?->toAtomString(),

            'images' => MediaResource::collection($this->whenLoaded('media')),

            'attributes' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues
                ->filter(fn ($value) => $value->attribute !== null)
                ->map(fn ($value) => [
                    'code' => $value->attribute->code,
                    'name' => $value->attribute->name,
                    'unit' => $value->attribute->unit,
                    'value' => $value->value(),
                    'label' => $value->option?->label,
                ])
                ->values()
                ->all()),

            /*
             * The surveyed parcel outline, for land listings.
             *
             * Public and unauthenticated on purpose: the extent of a plot is
             * the single most important thing about it, and hiding it behind a
             * login would make the listing less useful than the WhatsApp
             * message it competes with. It reveals nothing the address does not.
             */
            'boundary' => $this->whenLoaded(
                'boundary',
                fn () => $this->boundary !== null ? new ListingBoundaryResource($this->boundary) : null,
            ),

            // Whether the seller COULD draw one, so a client can show the right
            // empty state instead of guessing from the category slug.
            'supports_boundary' => $this->whenLoaded('category', fn () => $this->supportsBoundary()),

            'amenities' => $this->whenLoaded('amenities', fn () => $this->amenities
                ->map(fn ($a) => ['slug' => $a->slug, 'name' => $a->name, 'icon' => $a->icon])->all()),

            'facilities' => $this->whenLoaded('facilities', fn () => $this->facilities
                ->map(fn ($f) => ['slug' => $f->slug, 'name' => $f->name, 'icon' => $f->icon])->all()),

            'seller' => $this->whenLoaded(
                'user',
                fn () => new SellerSummaryResource($this->user, includeContact: $this->isPublished()),
            ),

            // Owner-only fields: a rejection reason must never be public.
            'rejection_reason' => $this->when(
                $request->user()?->getKey() === $this->user_id,
                fn () => $this->rejection_reason,
            ),
        ]);
    }

    /**
     * Flat `code => value` map of this listing's EAV values.
     *
     * Prefers already-loaded relations (detail responses have them, and they
     * are authoritative), and otherwise falls back to the `search_document`
     * projection maintained on write. The fallback is what makes this cheap on
     * list responses; the preference is what keeps it correct if a listing is
     * mutated in-request before the projection is rebuilt.
     *
     * @return array<string, mixed>
     */
    private function attributeMap(): array
    {
        if ($this->relationLoaded('attributeValues')) {
            $map = [];

            foreach ($this->attributeValues as $value) {
                $code = $value->attribute?->code;

                if ($code !== null) {
                    $map[$code] = $value->value();
                }
            }

            return $map;
        }

        $document = $this->search_document;

        return is_array($document['attributes'] ?? null) ? $document['attributes'] : [];
    }
}
