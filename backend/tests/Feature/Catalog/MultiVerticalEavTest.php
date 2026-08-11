<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Region;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The load-bearing test for Milestone 4 decision 4 (stay multi-vertical).
 *
 * If the EAV design is wrong, this is where it shows: a Vehicle must be able to
 * carry `mileage` and `fuel_type` while a Property carries `beds` and `sqft`,
 * with no nullable columns on `listings` and no per-vertical tables — and both
 * must remain filterable in a single query.
 */
class MultiVerticalEavTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function makeSeller(): User
    {
        return User::where('email', 'seller@saka.test')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function publishListing(Category $category, string $title, int $price, array $attributes): Listing
    {
        $seller = $this->makeSeller();
        $region = Region::where('slug', 'dar-es-salaam')->firstOrFail();
        $district = District::where('slug', 'kinondoni')->firstOrFail();
        $ward = Ward::where('slug', 'masaki')->firstOrFail();

        $listing = new Listing([
            'user_id' => $seller->id,
            'category_id' => $category->id,
            'title' => $title,
            'description' => 'Test fixture.',
            'purpose' => ListingPurpose::Sale,
            'price' => $price,
            'currency' => 'TZS',
            'region_id' => $region->id,
            'district_id' => $district->id,
            'ward_id' => $ward->id,
            'latitude' => -6.7452,
            'longitude' => 39.2783,
        ]);
        $listing->slug = Str::slug($title).'-'.Str::random(6);
        $listing->status = ListingStatus::Published;
        $listing->published_at = now();
        $listing->save();

        foreach ($attributes as $code => $value) {
            $attribute = Attribute::where('code', $code)->firstOrFail();
            $row = ['listing_id' => $listing->id, 'attribute_id' => $attribute->id];

            if ($attribute->input_type->expectsOptions()) {
                $option = $attribute->options()->where('value', Str::slug((string) $value))->firstOrFail();
                $row['attribute_option_id'] = $option->id;
                $row['value_string'] = $option->value;
            } else {
                $row[$attribute->valueColumn()] = $value;
            }

            ListingAttributeValue::create($row);
        }

        return $listing;
    }

    #[Test]
    public function attributes_are_inherited_from_the_parent_category(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();
        $cars = Category::where('slug', 'vehicles-cars')->firstOrFail();

        $apartmentCodes = $apartments->resolvedAttributes()->pluck('code');
        $carCodes = $cars->resolvedAttributes()->pluck('code');

        // Bound to the "Property" root, resolved on the "Apartments" leaf.
        $this->assertContains('beds', $apartmentCodes);
        $this->assertContains('sqft', $apartmentCodes);

        // ...and must NOT leak across verticals.
        $this->assertNotContains('mileage', $apartmentCodes);
        $this->assertContains('mileage', $carCodes);
        $this->assertNotContains('beds', $carCodes);
    }

    #[Test]
    public function listings_from_different_verticals_coexist_and_filter_independently(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();
        $cars = Category::where('slug', 'vehicles-cars')->firstOrFail();

        $this->publishListing($apartments, 'Masaki 3-Bed Apartment', 420_000_000, [
            'beds' => 3, 'bathrooms' => 2, 'sqft' => 1450, 'furnishing' => 'Furnished',
        ]);
        $this->publishListing($apartments, 'Masaki Studio', 165_000_000, [
            'beds' => 0, 'bathrooms' => 1, 'sqft' => 600,
        ]);
        $this->publishListing($cars, 'Toyota Land Cruiser V8', 95_000_000, [
            'make' => 'Toyota', 'year' => 2018, 'mileage' => 82_000, 'fuel_type' => 'Diesel',
        ]);
        $this->publishListing($cars, 'Suzuki Alto', 9_500_000, [
            'make' => 'Suzuki', 'year' => 2014, 'mileage' => 140_000, 'fuel_type' => 'Petrol',
        ]);

        $this->assertSame(4, Listing::count());

        // Numeric range filter on an integer EAV value.
        $twoPlusBeds = Listing::publiclyVisible()
            ->whereHas('attributeValues', fn ($q) => $q
                ->whereHas('attribute', fn ($a) => $a->where('code', 'beds'))
                ->where('value_integer', '>=', 2))
            ->pluck('title');

        $this->assertCount(1, $twoPlusBeds);
        $this->assertSame('Masaki 3-Bed Apartment', $twoPlusBeds->first());

        // Option filter on a select EAV value, in the same table, different vertical.
        $diesel = Listing::publiclyVisible()
            ->whereHas('attributeValues', fn ($q) => $q
                ->whereHas('attribute', fn ($a) => $a->where('code', 'fuel_type'))
                ->where('value_string', 'diesel'))
            ->pluck('title');

        $this->assertCount(1, $diesel);
        $this->assertSame('Toyota Land Cruiser V8', $diesel->first());
    }

    #[Test]
    public function category_subtree_scope_includes_descendants(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();
        $houses = Category::where('slug', 'property-houses')->firstOrFail();
        $cars = Category::where('slug', 'vehicles-cars')->firstOrFail();

        $this->publishListing($apartments, 'An Apartment', 1_000, ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500]);
        $this->publishListing($houses, 'A House', 2_000, ['beds' => 4, 'bathrooms' => 3, 'sqft' => 2000]);
        $this->publishListing($cars, 'A Car', 3_000, ['make' => 'Toyota', 'year' => 2020]);

        $property = Category::where('slug', 'property')->firstOrFail();

        // Browsing "Property" must pick up both of its leaf subcategories,
        // and none of Vehicles.
        $titles = Listing::publiclyVisible()->inCategory($property)->pluck('title');

        $this->assertCount(2, $titles);
        $this->assertContains('An Apartment', $titles);
        $this->assertContains('A House', $titles);
        $this->assertNotContains('A Car', $titles);
    }

    #[Test]
    public function unpublished_listings_are_invisible_to_the_public_scope(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();
        $listing = $this->publishListing($apartments, 'Draft Apartment', 1_000, [
            'beds' => 1, 'bathrooms' => 1, 'sqft' => 500,
        ]);

        $this->assertCount(1, Listing::publiclyVisible()->get());

        $listing->forceFill([
            'status' => ListingStatus::PendingReview,
            'published_at' => null,
        ])->save();

        $this->assertCount(0, Listing::publiclyVisible()->get());
    }

    #[Test]
    public function bounding_box_scope_narrows_by_distance(): void
    {
        $apartments = Category::where('slug', 'property-apartments')->firstOrFail();
        $near = $this->publishListing($apartments, 'Near Masaki', 1_000, ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500]);
        $far = $this->publishListing($apartments, 'Far Away', 1_000, ['beds' => 1, 'bathrooms' => 1, 'sqft' => 500]);

        // Move the second listing to Arusha, ~400km away.
        $far->forceFill(['latitude' => -3.3869, 'longitude' => 36.6830])->save();
        $near->forceFill(['latitude' => -6.7452, 'longitude' => 39.2783])->save();

        $within = Listing::publiclyVisible()
            ->withinBoundingBox(-6.7452, 39.2783, 10)
            ->pluck('title');

        $this->assertContains('Near Masaki', $within);
        $this->assertNotContains('Far Away', $within);
    }
}
