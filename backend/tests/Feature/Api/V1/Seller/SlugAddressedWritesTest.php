<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Models\Amenity;
use App\Models\Category;
use App\Models\District;
use App\Models\Facility;
use App\Models\Listing;
use App\Models\Region;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A client may write using only what the API publishes.
 *
 * The read side is slug-addressed throughout: /categories, /locations/*,
 * /amenities and /facilities return slugs and never expose numeric ids. Writes
 * were specified in terms of foreign keys, which left a client that had only
 * ever read from this API unable to name a category or a region at all.
 *
 * These tests pin the bridge from both ends: slugs work, ids still work, and a
 * bad slug is reported against the field the client actually sent.
 */
class SlugAddressedWritesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->seller()->withVerifiedPhone()->create();
    }

    /** @return array<string, mixed> */
    private function slugPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Spacious Masaki apartment with sea view',
            'category_slug' => 'property-apartments',
            'region_slug' => 'dar-es-salaam',
            'district_slug' => 'kinondoni',
            'price' => 450_000_000,
            'attributes' => ['beds' => 3, 'bathrooms' => 2, 'sqft' => 1450],
        ], $overrides);
    }

    // ------------------------------------------------------------- listings

    #[Test]
    public function a_listing_can_be_created_from_nothing_but_published_slugs(): void
    {
        // Everything here came out of a GET. No id is guessed anywhere.
        $categorySlug = $this->getJson('/api/v1/categories')->assertOk()
            ->json('data.0.children.0.slug');

        $regionSlug = $this->getJson('/api/v1/locations/regions')->assertOk()
            ->json('data.0.slug');

        $districtSlug = $this->getJson("/api/v1/locations/regions/{$regionSlug}/districts")
            ->assertOk()->json('data.0.slug');

        $category = Category::where('slug', $categorySlug)->firstOrFail();

        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', [
                'title' => 'A listing addressed entirely by slug',
                'category_slug' => $categorySlug,
                'region_slug' => $regionSlug,
                'district_slug' => $districtSlug,
                // Whatever this category demands, satisfied generically.
                'attributes' => $this->requiredAttributesFor($category),
            ])
            ->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($category->id, $listing->category_id);
        $this->assertSame(
            Region::where('slug', $regionSlug)->value('id'),
            $listing->region_id,
        );
        $this->assertSame(
            District::where('slug', $districtSlug)->value('id'),
            $listing->district_id,
        );
    }

    #[Test]
    public function amenities_and_facilities_accept_slugs(): void
    {
        $amenitySlugs = collect($this->getJson('/api/v1/amenities')->json('data'))
            ->take(2)->pluck('slug')->all();

        $facilitySlug = $this->getJson('/api/v1/facilities')->json('data.0.slug');

        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->slugPayload([
                'amenities' => $amenitySlugs,
                'facilities' => [$facilitySlug],
            ]))
            ->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertEqualsCanonicalizing(
            Amenity::whereIn('slug', $amenitySlugs)->pluck('id')->all(),
            $listing->amenities()->pluck('amenities.id')->all(),
        );

        $this->assertSame(
            [Facility::where('slug', $facilitySlug)->value('id')],
            $listing->facilities()->pluck('facilities.id')->all(),
        );
    }

    #[Test]
    public function ids_and_slugs_may_be_mixed_in_one_payload(): void
    {
        // A client migrating from ids to slugs will send both for a while.
        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->slugPayload([
                'region_slug' => null,
                'region_id' => Region::where('slug', 'dar-es-salaam')->value('id'),
                'amenities' => [
                    Amenity::first()->id,
                    Amenity::orderBy('id')->skip(1)->first()->slug,
                ],
            ]))
            ->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertCount(2, $listing->amenities);
    }

    #[Test]
    public function an_id_wins_when_both_are_sent_for_the_same_field(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();

        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->slugPayload([
                'category_id' => $apartments->id,
                'category_slug' => 'property-houses',
            ]))
            ->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame($apartments->id, $listing->category_id);
    }

    #[Test]
    public function an_unknown_slug_is_reported_against_the_field_that_was_sent(): void
    {
        // Not "category_id is required" — the client never sent a category_id.
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->slugPayload([
                'category_slug' => 'no-such-category',
            ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category_slug']]);
    }

    #[Test]
    public function a_listing_can_be_updated_by_slug_without_touching_its_other_fields(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->create();
        $originalTitle = $listing->title;

        $mbeya = Region::where('slug', 'mbeya')->firstOrFail();
        $district = District::where('region_id', $mbeya->id)->firstOrFail();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", [
                'region_slug' => $mbeya->slug,
                'district_slug' => $district->slug,
            ])
            ->assertOk();

        $listing->refresh();

        $this->assertSame($mbeya->id, $listing->region_id);
        $this->assertSame($district->id, $listing->district_id);
        // PATCH stays PATCH: relaxing the presence rules must not have made the
        // untouched fields optional-and-cleared.
        $this->assertSame($originalTitle, $listing->title);
    }

    #[Test]
    public function a_half_coordinate_is_still_rejected_on_update(): void
    {
        // The update request relaxes `required` rules; it must NOT relax the
        // pairing rules, or a listing ends up with a longitude and no latitude.
        $listing = Listing::factory()->ownedBy($this->seller)->create();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", [
                'longitude' => 39.2,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_listing_publishes_the_slugs_its_own_editor_needs(): void
    {
        // Round trip: what a read returns must be writable back without a
        // lookup table on the client.
        $listing = Listing::factory()->ownedBy($this->seller)->published()->create();

        $data = $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk()->json('data');

        $this->assertSame($listing->region->slug, $data['location']['region_slug']);
        $this->assertSame($listing->district->slug, $data['location']['district_slug']);
        $this->assertNotNull($data['category']['slug']);
    }

    // ------------------------------------------------------- vendor profile

    #[Test]
    public function the_vendor_profile_accepts_a_location_by_slug(): void
    {
        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();
        $district = District::where('region_id', $region->id)->firstOrFail();
        $ward = Ward::where('district_id', $district->id)->first();

        $response = $this->actingAs($this->seller, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', array_filter([
                'display_name' => 'Kinondoni Motors',
                'region_slug' => $region->slug,
                'district_slug' => $district->slug,
                'ward_slug' => $ward?->slug,
            ]))
            ->assertOk();

        $this->assertSame($region->id, $this->seller->sellerProfile()->first()->region_id);

        // And it hands the slugs back, so the editor can preselect what it just
        // saved without a second round of lookups.
        $this->assertSame($region->slug, $response->json('data.location.region_slug'));
        $this->assertSame($district->slug, $response->json('data.location.district_slug'));
    }

    #[Test]
    public function a_slug_never_reaches_the_profile_columns(): void
    {
        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'display_name' => 'Slug Test Traders',
                'region_slug' => $region->slug,
            ])
            ->assertOk();

        // `region_slug` is an input, not a column: filling it would throw.
        $this->assertDatabaseHas('seller_profiles', [
            'user_id' => $this->seller->id,
            'region_id' => $region->id,
        ]);
    }

    #[Test]
    public function an_explicit_null_slug_clears_the_location(): void
    {
        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', [
                'display_name' => 'Movers Ltd',
                'region_slug' => $region->slug,
            ])->assertOk();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson('/api/v1/seller/vendor-profile', ['region_slug' => null])
            ->assertOk();

        $this->assertNull($this->seller->sellerProfile()->first()->region_id);
    }

    /**
     * Whatever the category requires, filled with something that validates.
     *
     * @return array<string, mixed>
     */
    private function requiredAttributesFor(Category $category): array
    {
        $values = [];

        // resolvedAttributes(), not attributes(): a leaf inherits the field set
        // of its ancestors, and that is what the write rule validates against.
        foreach ($category->resolvedAttributes() as $attribute) {
            if (! (bool) ($attribute->getAttribute('is_required') ?? false)) {
                continue;
            }

            $values[$attribute->code] = match ($attribute->input_type->value) {
                'number' => 2,
                'boolean' => true,
                'select', 'multiselect' => $attribute->options()->value('value'),
                default => 'Unspecified',
            };
        }

        return $values;
    }
}
