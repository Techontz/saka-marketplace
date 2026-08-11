<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogAndContentTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function the_category_tree_is_returned_with_children(): void
    {
        $response = $this->getJson('/api/v1/categories')->assertOk();

        /*
         * Asserted by CONTENT, not by count.
         *
         * This used to pin the catalogue at nine verticals, which meant seeding
         * a tenth — Specialists — failed a test that has nothing to do with
         * specialists. The catalogue is meant to grow; what matters is that the
         * tree comes back ordered, iconed and nested.
         */
        $verticals = $response->json('data');
        $slugs = array_column($verticals, 'slug');

        $this->assertContains('property', $slugs);
        $this->assertContains('specialists', $slugs);

        $this->assertSame('Property', $response->json('data.0.name'));
        $this->assertSame('🏠', $response->json('data.0.icon'));
        $this->assertCount(10, $response->json('data.0.children'));
    }

    #[Test]
    public function category_attributes_are_inherited_from_the_parent(): void
    {
        $apartments = $this->getJson('/api/v1/categories/property-apartments/attributes')
            ->assertOk()->json('data');
        $cars = $this->getJson('/api/v1/categories/vehicles-cars/attributes')
            ->assertOk()->json('data');

        $apartmentCodes = array_column($apartments, 'code');
        $carCodes = array_column($cars, 'code');

        // Bound to the "Property" root, resolved on the "Apartments" leaf...
        $this->assertContains('beds', $apartmentCodes);
        // ...and never leaking across verticals.
        $this->assertNotContains('mileage', $apartmentCodes);
        $this->assertContains('mileage', $carCodes);
        $this->assertNotContains('beds', $carCodes);
    }

    #[Test]
    public function attribute_metadata_is_rich_enough_to_build_a_filter_ui(): void
    {
        $attributes = $this->getJson('/api/v1/categories/vehicles-cars/attributes')->assertOk()->json('data');
        $fuel = collect($attributes)->firstWhere('code', 'fuel_type');

        // This is what lets a new vertical ship with no frontend release.
        $this->assertSame('select', $fuel['input_type']);
        $this->assertNotEmpty($fuel['options']);
        $this->assertContains('diesel', array_column($fuel['options'], 'value'));

        $beds = collect($this->getJson('/api/v1/categories/property-apartments/attributes')->json('data'))
            ->firstWhere('code', 'beds');
        $this->assertSame('number', $beds['input_type']);
        $this->assertTrue($beds['is_required']);
        $this->assertEquals(50, $beds['max_value']);
    }

    #[Test]
    public function the_location_hierarchy_is_navigable(): void
    {
        $regions = $this->getJson('/api/v1/locations/regions')->assertOk()->json('data');
        $this->assertCount(31, $regions);

        $districts = $this->getJson('/api/v1/locations/regions/dar-es-salaam/districts')->assertOk()->json('data');
        $this->assertCount(5, $districts);

        $wards = $this->getJson('/api/v1/locations/districts/kinondoni/wards')->assertOk()->json('data');
        $this->assertNotEmpty($wards);
        $this->assertContains('Masaki', array_column($wards, 'name'));
    }

    #[Test]
    public function amenities_and_facilities_are_exposed(): void
    {
        $this->getJson('/api/v1/amenities')->assertOk()->assertJsonCount(15, 'data');
        $this->getJson('/api/v1/facilities')->assertOk()->assertJsonCount(10, 'data');
    }

    #[Test]
    public function public_places_are_a_separate_entity_from_listings(): void
    {
        $categories = $this->getJson('/api/v1/public-places/categories')->assertOk()->json('data');
        $this->assertCount(8, $categories);

        $places = $this->getJson('/api/v1/public-places')->assertOk()->json('data');
        $this->assertNotEmpty($places);

        // The frontend's version 404s because it resolves these against the
        // listings array; here they resolve against their own table.
        $this->getJson('/api/v1/public-places/muhimbili-national-hospital')
            ->assertOk()
            ->assertJsonPath('data.name', 'Muhimbili National Hospital')
            ->assertJsonPath('data.category.name', 'Hospitals');
    }

    #[Test]
    public function public_places_can_be_filtered_by_category(): void
    {
        $banks = $this->getJson('/api/v1/public-places?category=banks')->assertOk()->json('data');

        $this->assertCount(6, $banks);
        foreach ($banks as $bank) {
            $this->assertSame('Banks', $bank['category']['name']);
        }
    }

    #[Test]
    public function faqs_and_public_settings_are_served(): void
    {
        $this->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(5, 'data');

        $settings = $this->getJson('/api/v1/settings/public')->assertOk()->json('data');
        $this->assertSame('SAKA', $settings['site.name']);
        // Private settings must never leak.
        $this->assertArrayNotHasKey('listings.require_moderation', $settings);
    }

    #[Test]
    public function unpublished_legal_pages_return_404(): void
    {
        // Terms and Privacy are seeded UNPUBLISHED pending real legal copy.
        $this->getJson('/api/v1/pages/terms-and-conditions')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function an_unknown_category_returns_a_standard_404(): void
    {
        $this->getJson('/api/v1/categories/not-a-category')
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
