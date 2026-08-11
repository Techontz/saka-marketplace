<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Listing;

use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\District;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The full filter pipeline over HTTP — including the dynamic EAV filters that
 * make the marketplace genuinely multi-vertical.
 */
class ListingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function withAttribute(Listing $listing, string $code, mixed $value): Listing
    {
        $attribute = Attribute::where('code', $code)->firstOrFail();
        $row = ['listing_id' => $listing->id, 'attribute_id' => $attribute->id];

        if ($attribute->input_type->expectsOptions()) {
            $option = $attribute->options()->where('value', $value)->firstOrFail();
            $row['attribute_option_id'] = $option->id;
            $row['value_string'] = $option->value;
        } else {
            $row[$attribute->data_type->column()] = $value;
        }

        ListingAttributeValue::create($row);

        return $listing;
    }

    #[Test]
    public function category_filter_includes_the_whole_subtree(): void
    {
        Listing::factory()->published()->inCategory('property-apartments')->create();
        Listing::factory()->published()->inCategory('property-houses')->create();
        Listing::factory()->published()->inCategory('vehicles-cars')->create();

        // Browsing "Property" must pick up both leaves and no vehicles.
        $this->getJson('/api/v1/listings?category=property')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/listings?subcategory=property-apartments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?category=vehicles')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_unknown_category_returns_nothing_rather_than_everything(): void
    {
        Listing::factory()->count(3)->published()->create();

        // A typo must not silently behave like "no filter".
        $this->getJson('/api/v1/listings?category=does-not-exist')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function price_range_filters_and_excludes_unpriced_listings(): void
    {
        Listing::factory()->published()->priced(1_000_000)->create();
        Listing::factory()->published()->priced(50_000_000)->create();
        Listing::factory()->published()->create(['price' => null]);

        $this->getJson('/api/v1/listings?min_price=500000&max_price=2000000')
            ->assertOk()->assertJsonCount(1, 'data');

        // "Contact for price" cannot honestly claim to be under a ceiling.
        $this->getJson('/api/v1/listings?max_price=99999999999')->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function max_price_must_not_be_below_min_price(): void
    {
        $this->getJson('/api/v1/listings?min_price=100&max_price=50')
            ->assertStatus(422)->assertJsonValidationErrors('max_price');
    }

    #[Test]
    public function location_filters_narrow_by_region_and_district(): void
    {
        $dar = Region::where('slug', 'dar-es-salaam')->firstOrFail();
        $arusha = Region::where('slug', 'arusha')->firstOrFail();
        $kinondoni = District::where('slug', 'kinondoni')->firstOrFail();

        Listing::factory()->published()->create(['region_id' => $dar->id, 'district_id' => $kinondoni->id]);
        Listing::factory()->published()->create(['region_id' => $arusha->id, 'district_id' => District::where('region_id', $arusha->id)->value('id')]);

        $this->getJson('/api/v1/listings?region=dar-es-salaam')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?district=kinondoni')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?region=arusha')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function purpose_condition_and_verified_filters_work(): void
    {
        Listing::factory()->published()->verified()->create([
            'purpose' => ListingPurpose::Rent, 'condition' => ListingCondition::New,
        ]);
        Listing::factory()->published()->create([
            'purpose' => ListingPurpose::Sale, 'condition' => ListingCondition::Used,
        ]);

        $this->getJson('/api/v1/listings?purpose=rent')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?condition=new')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?verified=1')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function amenity_filters_are_and_combined_not_or_combined(): void
    {
        $pool = Amenity::where('slug', 'swimming-pool')->firstOrFail();
        $gym = Amenity::where('slug', 'gym')->firstOrFail();

        $both = Listing::factory()->published()->create();
        $both->amenities()->sync([$pool->id, $gym->id]);

        $poolOnly = Listing::factory()->published()->create();
        $poolOnly->amenities()->sync([$pool->id]);

        // "pool AND gym" means both, not either.
        $this->getJson('/api/v1/listings?amenities[]=swimming-pool&amenities[]=gym')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/listings?amenities[]=swimming-pool')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function eav_attribute_filters_work_across_different_verticals(): void
    {
        $apartment = Listing::factory()->published()->inCategory('property-apartments')->create();
        $this->withAttribute($apartment, 'beds', 3);
        $this->withAttribute($apartment, 'bathrooms', 2);

        $studio = Listing::factory()->published()->inCategory('property-apartments')->create();
        $this->withAttribute($studio, 'beds', 0);

        $diesel = Listing::factory()->published()->inCategory('vehicles-cars')->create();
        $this->withAttribute($diesel, 'fuel_type', 'diesel');
        $this->withAttribute($diesel, 'mileage', 80_000);

        $petrol = Listing::factory()->published()->inCategory('vehicles-cars')->create();
        $this->withAttribute($petrol, 'fuel_type', 'petrol');

        // Numeric range on a Property.
        $this->getJson('/api/v1/listings?attributes[beds][min]=2')->assertOk()->assertJsonCount(1, 'data');

        // Option match on a Vehicle — same code path, different vertical.
        $this->getJson('/api/v1/listings?attributes[fuel_type]=diesel')->assertOk()->assertJsonCount(1, 'data');

        // Two attributes AND together.
        $this->getJson('/api/v1/listings?attributes[fuel_type]=diesel&attributes[mileage][max]=100000')
            ->assertOk()->assertJsonCount(1, 'data');

        // A range that matches nothing.
        $this->getJson('/api/v1/listings?attributes[beds][min]=10')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_unknown_attribute_code_is_ignored_rather_than_fatal(): void
    {
        Listing::factory()->count(2)->published()->create();

        // A stale bookmark should degrade, not 500.
        $this->getJson('/api/v1/listings?attributes[not_a_real_code]=x')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function radius_search_returns_only_nearby_listings_and_exposes_distance(): void
    {
        // Masaki, Dar es Salaam
        Listing::factory()->published()->at(-6.7452, 39.2783)->create(['title' => 'Near Masaki listing here']);
        // Arusha, ~400km away
        Listing::factory()->published()->at(-3.3869, 36.6830)->create(['title' => 'Far Arusha listing here']);

        $response = $this->getJson('/api/v1/listings?lat=-6.7452&lng=39.2783&radius=25&sort=distance')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Near Masaki listing here', $response->json('data.0.title'));
        $this->assertNotNull($response->json('data.0.distance_km'));
    }

    #[Test]
    public function radius_requires_coordinates_and_is_capped(): void
    {
        $this->getJson('/api/v1/listings?radius=10')->assertStatus(422);
        // Uncapped radius would defeat the bounding-box prefilter.
        $this->getJson('/api/v1/listings?lat=-6.7&lng=39.2&radius=100000')->assertStatus(422);
    }

    #[Test]
    public function sorting_is_whitelisted_and_falls_back_safely(): void
    {
        Listing::factory()->published()->priced(5_000)->create();
        Listing::factory()->published()->priced(1_000)->create();

        $asc = $this->getJson('/api/v1/listings?sort=price_asc')->assertOk()->json('data');
        $this->assertSame(1_000, $asc[0]['price']['amount']);

        $desc = $this->getJson('/api/v1/listings?sort=price_desc')->assertOk()->json('data');
        $this->assertSame(5_000, $desc[0]['price']['amount']);

        // Never interpolate client input into ORDER BY.
        $this->getJson('/api/v1/listings?sort='.urlencode('id; DROP TABLE listings'))->assertStatus(422);

        // `distance` without geo silently falls back rather than erroring.
        $this->getJson('/api/v1/listings?sort=distance')->assertOk();
    }

    #[Test]
    public function featured_listings_float_to_the_top(): void
    {
        Listing::factory()->published()->create(['title' => 'An ordinary listing here']);
        Listing::factory()->published()->featured()->create(['title' => 'A promoted listing here']);

        $data = $this->getJson('/api/v1/listings')->assertOk()->json('data');

        $this->assertSame('A promoted listing here', $data[0]['title']);
    }

    #[Test]
    public function filters_combine(): void
    {
        $dar = Region::where('slug', 'dar-es-salaam')->firstOrFail();

        $match = Listing::factory()->published()->inCategory('property-apartments')
            ->priced(2_000_000)->verified()
            ->create(['region_id' => $dar->id, 'purpose' => ListingPurpose::Rent, 'title' => 'Perfect Masaki match here']);
        $this->withAttribute($match, 'beds', 3);

        Listing::factory()->published()->inCategory('property-apartments')->priced(90_000_000)->create();
        Listing::factory()->published()->inCategory('vehicles-cars')->create();

        $response = $this->getJson(
            '/api/v1/listings?category=property&region=dar-es-salaam&purpose=rent'
            .'&min_price=1000000&max_price=5000000&verified=1&attributes[beds][min]=2'
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Perfect Masaki match here', $response->json('data.0.title'));
    }
}
