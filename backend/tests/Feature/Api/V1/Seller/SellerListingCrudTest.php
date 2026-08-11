<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Seller;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SellerListingCrudTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->seller()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();
        $district = District::where('slug', 'kinondoni')->firstOrFail();

        return array_merge([
            'title' => 'Spacious Masaki apartment with sea view',
            'description' => 'A generously proportioned apartment.',
            'category_id' => Category::where('slug', 'property-apartments')->value('id'),
            'price' => 450_000_000,
            'currency' => 'TZS',
            'region_id' => $region->id,
            'district_id' => $district->id,
            // Property requires beds/bathrooms/sqft — enforced from the DB.
            'attributes' => ['beds' => 3, 'bathrooms' => 2, 'sqft' => 1450],
        ], $overrides);
    }

    #[Test]
    public function a_seller_can_create_a_listing_as_a_draft(): void
    {
        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', ListingStatus::Draft->value);

        $this->assertDatabaseHas('listings', [
            'uuid' => $response->json('data.uuid'),
            'user_id' => $this->seller->id,
            'status' => ListingStatus::Draft->value,
        ]);

        // The EAV rows are written in the same transaction as the listing.
        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame(3, $listing->attributeValues()->count());
    }

    #[Test]
    public function creating_a_listing_grants_the_seller_role_and_a_profile(): void
    {
        $buyer = User::factory()->buyer()->withVerifiedPhone()->create();

        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload())
            ->assertCreated();

        // There is no separate "seller signup" — listing something makes you one.
        $this->assertTrue($buyer->fresh()->hasRole('seller'));
        $this->assertNotNull($buyer->fresh()->sellerProfile);
    }

    #[Test]
    public function required_category_attributes_are_enforced(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload(['attributes' => ['beds' => 3]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attributes');
    }

    #[Test]
    public function attributes_from_another_vertical_are_rejected(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload([
                'attributes' => ['beds' => 3, 'bathrooms' => 2, 'sqft' => 900, 'mileage' => 50_000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attributes');
    }

    #[Test]
    public function attribute_bounds_are_enforced(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload([
                'attributes' => ['beds' => 9999, 'bathrooms' => 2, 'sqft' => 900],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attributes');
    }

    #[Test]
    public function a_select_attribute_only_accepts_defined_options(): void
    {
        $vehicles = Category::where('slug', 'vehicles-cars')->value('id');

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload([
                'category_id' => $vehicles,
                'attributes' => ['make' => 'Toyota', 'year' => 2018, 'fuel_type' => 'plutonium'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attributes');
    }

    #[Test]
    public function a_top_level_category_cannot_hold_a_listing(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload([
                'category_id' => Category::where('slug', 'property')->value('id'),
                'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500],
            ]))
            ->assertStatus(422);
    }

    #[Test]
    public function the_location_hierarchy_must_be_consistent(): void
    {
        $arushaDistrict = District::whereHas('region', fn ($q) => $q->where('slug', 'arusha'))->value('id');

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload(['district_id' => $arushaDistrict]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('district_id');
    }

    #[Test]
    public function a_seller_can_update_their_own_listing(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->create();

        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", ['title' => 'A brand new descriptive title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'A brand new descriptive title');
    }

    #[Test]
    public function a_seller_cannot_touch_another_sellers_listing(): void
    {
        $other = User::factory()->seller()->create();
        $listing = Listing::factory()->ownedBy($other)->create();

        // 404, not 403: a 403 would confirm the uuid names a real listing.
        $this->actingAs($this->seller, 'sanctum')
            ->patchJson("/api/v1/seller/listings/{$listing->uuid}", ['title' => 'Trying to hijack this listing'])
            ->assertStatus(403);

        $this->actingAs($this->seller, 'sanctum')
            ->deleteJson("/api/v1/seller/listings/{$listing->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function client_supplied_protected_fields_are_ignored(): void
    {
        $response = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload([
                'status' => ListingStatus::Published->value,
                'is_verified' => true,
                'is_featured' => true,
                'view_count' => 99999,
            ]))
            ->assertCreated();

        $listing = Listing::where('uuid', $response->json('data.uuid'))->firstOrFail();

        // Mass-assignment protection at the model AND the request layer.
        $this->assertSame(ListingStatus::Draft, $listing->status);
        $this->assertFalse($listing->is_verified);
        $this->assertFalse($listing->is_featured);
        $this->assertSame(0, $listing->view_count);
    }

    #[Test]
    public function a_seller_can_soft_delete_their_listing(): void
    {
        $listing = Listing::factory()->ownedBy($this->seller)->create();

        $this->actingAs($this->seller, 'sanctum')
            ->deleteJson("/api/v1/seller/listings/{$listing->uuid}")
            ->assertOk();

        // Soft delete: inquiries and history survive.
        $this->assertSoftDeleted('listings', ['id' => $listing->id]);
    }

    #[Test]
    public function the_seller_surface_requires_authentication(): void
    {
        $this->getJson('/api/v1/seller/listings')->assertStatus(401);
        $this->postJson('/api/v1/seller/listings', [])->assertStatus(401);
    }

    #[Test]
    public function a_seller_only_sees_their_own_listings_in_the_index(): void
    {
        Listing::factory()->count(2)->ownedBy($this->seller)->create();
        Listing::factory()->count(3)->published()->create();

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/seller/listings')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
