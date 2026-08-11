<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\BusinessType;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Listing\Enums\ListingCondition;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Listing\Enums\PriceUnit;
use App\Domain\Media\Enums\MediaCollection;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Media;
use App\Models\PublicPlaceCategory;
use App\Models\Region;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Listing\ListingIndexer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Demo catalogue for local development and preview environments.
 *
 * The frontend shipped with a hardcoded `src/lib/listings.ts` containing 15
 * listings, 9 category images and 8 public-place images. Integrating the
 * frontend against a live API means that file goes away — but the UI must look
 * exactly the same, so the same records have to exist somewhere real. They
 * exist here.
 *
 * Every slug, title, price, image URL, verified flag and attribute value is
 * copied verbatim from that file. Nothing is invented and nothing is dropped.
 * This is the same approach ContentSeeder already takes for the FAQs and the
 * public-place directory.
 *
 * NOT part of DatabaseSeeder. Run it explicitly:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * It refuses to run in production, because publishing demo listings on a live
 * marketplace is not a recoverable mistake.
 */
class DemoSeeder extends Seeder
{
    /** Root category => the image the frontend rendered for it. */
    private const CATEGORY_IMAGES = [
        'Property' => '1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1200&q=80',
        'Vehicles' => '1492144534655-ae79c964c9d7?auto=format&fit=crop&w=1200&q=80',
        'Electronics' => '1511707171634-5f897ff02aa9?auto=format&fit=crop&w=1200&q=80',
        'Furniture' => '1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1200&q=80',
        'Fashion' => '1483985988355-763728e1935b?auto=format&fit=crop&w=1200&q=80',
        'Jobs' => '1521791136064-7986c2920216?auto=format&fit=crop&w=1200&q=80',
        'Services' => '1521791055366-0d553872125f?auto=format&fit=crop&w=1200&q=80',
        'Agriculture' => '1464226184884-fa280b87c399?auto=format&fit=crop&w=1200&q=80',
        'Pets' => '1517849845537-4d257902454a?auto=format&fit=crop&w=1200&q=80',
    ];

    /** Public-place category => image, also verbatim from the frontend. */
    private const PLACE_IMAGES = [
        'Hospitals' => '1519494026892-80bbd2d6fd0d?auto=format&fit=crop&w=1200&q=80',
        'Banks' => '1601597111158-2fceff292cdc?auto=format&fit=crop&w=1200&q=80',
        'Schools' => '1580582932707-520aed937b7b?auto=format&fit=crop&w=1200&q=80',
        'Pharmacies' => '1587854692152-cbe660dbde88?auto=format&fit=crop&w=1200&q=80',
        'Hotels' => '1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=80',
        'Restaurants' => '1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1200&q=80',
        'Petrol Stations' => '1545459720-aac8509eb02c?auto=format&fit=crop&w=1200&q=80',
        'Shopping Malls' => '1519567241046-7f570eee3ce6?auto=format&fit=crop&w=1200&q=80',
    ];

    /**
     * The neighbourhood strings the frontend rendered, mapped onto seeded wards
     * where one exists. "Posta" and "Ocean Road" are landmarks rather than
     * administrative wards, so they carry a district only — `address_line`
     * holds the neighbourhood either way, which is what the UI displays.
     */
    private const WARDS = [
        'Masaki' => ['Kinondoni', 'Masaki'],
        'Upanga' => ['Ilala', 'Upanga East'],
        'Kariakoo' => ['Ilala', 'Kariakoo'],
        'Mikocheni' => ['Kinondoni', 'Mikocheni'],
        'Kinondoni' => ['Kinondoni', 'Kinondoni'],
        'Posta' => ['Ilala', null],
        'Ocean Road' => ['Ilala', null],
    ];

    /**
     * Approximate coordinates per neighbourhood.
     *
     * The frontend carried no coordinates at all — its distance badge fell back
     * to a single Dar es Salaam centroid for every card, so every listing
     * reported the same distance. Real per-neighbourhood coordinates make the
     * radius filter and the distance badge mean something. This is the one
     * place demo data is richer than the mock, and it is additive: nothing that
     * was rendered before changes.
     */
    private const COORDINATES = [
        'Masaki' => [-6.7420, 39.2790],
        'Upanga' => [-6.8100, 39.2870],
        'Kariakoo' => [-6.8180, 39.2730],
        'Mikocheni' => [-6.7660, 39.2470],
        'Kinondoni' => [-6.7860, 39.2560],
        'Posta' => [-6.8160, 39.2900],
        'Ocean Road' => [-6.8060, 39.2950],
    ];

    /** @var array<int, array<string, mixed>> */
    private const LISTINGS = [
        [
            'slug' => 'masaki-studio-apartment-33-bq',
            'title' => 'Masaki Studio Apartment – 33-BQ – Available for Lease',
            'subcategory' => 'Apartments',
            'neighbourhood' => 'Masaki',
            'price' => 165000,
            'verified' => true,
            'photo' => '1560448204-e02f11c3d0e2?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 1198],
        ],
        [
            'slug' => 'masaki-studio-apartment-43-mb',
            'title' => 'Masaki Studio Apartment – 43-MB – Available for Lease',
            'subcategory' => 'Apartments',
            'neighbourhood' => 'Masaki',
            'price' => 322000,
            'verified' => false,
            'photo' => '1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 2, 'bathrooms' => 2, 'sqft' => 1259],
        ],
        [
            'slug' => 'masaki-studio-apartment-16-it',
            'title' => 'Masaki Studio Apartment – 16-IT – Available for Lease',
            'subcategory' => 'Apartments',
            'neighbourhood' => 'Masaki',
            'price' => 133000,
            'verified' => false,
            'photo' => '1493809842364-78817add7ffb?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 978],
        ],
        [
            'slug' => 'upanga-hillside-room-87-ee',
            'title' => 'Upanga Hillside Room – 87-EE – Available for Rent',
            'subcategory' => 'Rooms',
            'neighbourhood' => 'Upanga',
            'price' => 350000,
            'verified' => true,
            'photo' => '1567767292278-a4f21aa2d36e?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 1041],
        ],
        [
            'slug' => 'upanga-hillside-room-62-eu',
            'title' => 'Upanga Hillside Room – 62-EU – Available for Rent',
            'subcategory' => 'Rooms',
            'neighbourhood' => 'Upanga',
            'price' => 950000,
            'verified' => false,
            'photo' => '1556909114-f6e7ad7d3136?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 3, 'bathrooms' => 2, 'sqft' => 1629],
        ],
        [
            'slug' => 'upanga-hillside-room-34-wx',
            'title' => 'Upanga Hillside Room – 34-WX – Available for Rent',
            'subcategory' => 'Rooms',
            'neighbourhood' => 'Upanga',
            'price' => 310000,
            'verified' => false,
            'photo' => '1552321554-5fefe8c9ef14?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 1, 'bathrooms' => 1, 'sqft' => 369],
        ],
        [
            'slug' => 'kariakoo-commercial-floor-81-xg',
            'title' => 'Kariakoo Commercial Floor – 81-XG – Available for Sale',
            'subcategory' => 'Plots',
            'neighbourhood' => 'Kariakoo',
            'price' => 450000000,
            'verified' => false,
            'photo' => '1616486338812-3dadae4b4ace?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 1160],
        ],
        [
            'slug' => 'kariakoo-commercial-floor-86-iu',
            'title' => 'Kariakoo Commercial Floor – 86-IU – Available for Sale',
            'subcategory' => 'Plots',
            'neighbourhood' => 'Kariakoo',
            'price' => 175000000,
            'verified' => false,
            'photo' => '1616594039964-ae9021a400a0?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 504],
        ],
        [
            'slug' => 'kariakoo-commercial-floor-89-je',
            'title' => 'Kariakoo Commercial Floor – 89-JE – Available for Sale',
            'subcategory' => 'Plots',
            'neighbourhood' => 'Kariakoo',
            'price' => 980000000,
            'verified' => false,
            'photo' => '1552321554-5fefe8c9ef14?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 279],
        ],
        [
            'slug' => 'kariakoo-commercial-floor-64-qs',
            'title' => 'Kariakoo Commercial Floor – 64-QS – Available for Sale',
            'subcategory' => 'Plots',
            'neighbourhood' => 'Kariakoo',
            'price' => 1050000000,
            'verified' => false,
            'photo' => '1600585154340-be6161a56a0c?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 933],
        ],
        [
            'slug' => 'mikocheni-hillside-house-77-vk',
            'title' => 'Mikocheni Hillside House – 77-VK – Available for Rent',
            'subcategory' => 'Houses',
            'neighbourhood' => 'Mikocheni',
            'price' => 1100000,
            'verified' => false,
            'photo' => '1493809842364-78817add7ffb?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 4, 'bathrooms' => 3, 'sqft' => 1537, 'balconies' => 2, 'doors' => 0, 'parkings' => 0],
        ],
        [
            'slug' => 'kinondoni-city-rental-room-23-hf',
            'title' => 'Kinondoni City Rental Room – 23-HF – Available for Rent',
            'subcategory' => 'Rooms',
            'neighbourhood' => 'Kinondoni',
            'price' => 780000,
            'verified' => false,
            'photo' => '1556909114-f6e7ad7d3136?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 594, 'balconies' => 0, 'doors' => 0, 'parkings' => 1],
        ],
        [
            'slug' => 'kinondoni-city-rental-room-87-gc',
            'title' => 'Kinondoni City Rental Room – 87-GC – Available for Rent',
            'subcategory' => 'Rooms',
            'neighbourhood' => 'Kinondoni',
            'price' => 700000,
            'verified' => false,
            'photo' => '1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 1169, 'balconies' => 0, 'doors' => 0, 'parkings' => 1],
        ],
        [
            'slug' => 'posta-downtown-office-48-bb',
            'title' => 'Posta Downtown Office – 48-BB – Available for Rent',
            'subcategory' => 'Offices',
            'neighbourhood' => 'Posta',
            'price' => 600000,
            'verified' => false,
            'photo' => '1560448204-61dc36dc98c8?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 861, 'balconies' => 0, 'doors' => 0, 'parkings' => 0],
        ],
        [
            'slug' => 'ocean-road-residential-house-79-jl',
            'title' => 'Ocean Road Residential House – 79-JL – Available for Lease',
            'subcategory' => 'Houses',
            'neighbourhood' => 'Ocean Road',
            'price' => 375000,
            'verified' => false,
            'photo' => '1505691938895-1758d7feb511?auto=format&fit=crop&w=1200&q=80',
            'attributes' => ['beds' => 0, 'bathrooms' => 0, 'sqft' => 571, 'balconies' => 0, 'doors' => 0, 'parkings' => 0],
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder refuses to run in production.');

            return;
        }

        $this->attachCategoryImages();
        $this->attachPlaceImages();

        $seller = $this->demoSeller();
        $this->seedListings($seller);

        // listing_count is denormalised; nothing else writes it.
        Artisan::call('saka:taxonomy:recount');

        $this->command->info('  Seeded '.count(self::LISTINGS).' demo listings.');
    }

    private function attachCategoryImages(): void
    {
        foreach (self::CATEGORY_IMAGES as $name => $photo) {
            $category = Category::query()->whereNull('parent_id')->where('name', $name)->first();

            if ($category === null) {
                continue;
            }

            $media = $this->demoMedia($photo, Category::class, $category->id, $name, MediaCollection::CategoryImage);
            $category->forceFill(['image_media_id' => $media->id])->save();
        }

        $this->command->info('  Attached '.count(self::CATEGORY_IMAGES).' category images.');
    }

    private function attachPlaceImages(): void
    {
        foreach (self::PLACE_IMAGES as $name => $photo) {
            $category = PublicPlaceCategory::query()->where('name', $name)->first();

            if ($category === null) {
                continue;
            }

            $media = $this->demoMedia($photo, PublicPlaceCategory::class, $category->id, $name, MediaCollection::CategoryImage);
            $category->forceFill(['image_media_id' => $media->id])->save();
        }

        $this->command->info('  Attached '.count(self::PLACE_IMAGES).' public-place images.');
    }

    private function demoSeller(): User
    {
        /** @var User $seller */
        $seller = User::query()->where('email', 'seller@saka.co.tz')->first()
            ?? User::factory()->create([
                'email' => 'seller@saka.co.tz',
                'first_name' => 'Demo',
                'last_name' => 'Seller',
            ]);

        // Without the role the demo seller has no listing permissions and
        // cannot act on its own listings — the vendor portal would open to a
        // wall of 403s.
        if (! $seller->hasRole(RoleSlug::Seller->value)) {
            $seller->assignRole(RoleSlug::Seller->value);
        }

        // Publishing is gated on a verified phone; the demo data is published,
        // so the demo seller must look the part.
        if ($seller->phone_verified_at === null) {
            $seller->forceFill([
                'phone' => $seller->phone ?? '+255700000900',
                'phone_verified_at' => now(),
            ])->save();
        }

        $this->completeSellerProfile($seller);

        return $seller;
    }

    /**
     * Make the demo seller an actual BUSINESS, not just an account.
     *
     * A seller profile row exists from the moment a vendor opens the portal,
     * but the public directory only shows businesses that finished onboarding,
     * and the map only shows ones with coordinates. Seeded data had neither, so
     * /businesses was empty, "nearby businesses" returned nothing and every
     * business-related rail on the marketplace looked broken — while the code
     * behind them was fine.
     */
    private function completeSellerProfile(User $seller): void
    {
        $profile = SellerProfile::query()->firstOrNew(['user_id' => $seller->getKey()]);

        $region = Region::query()->where('slug', 'dar-es-salaam')->first();
        $district = $region === null
            ? null
            : District::query()->where('region_id', $region->id)->where('name', 'Kinondoni')->first();

        $profile->forceFill([
            'user_id' => $seller->getKey(),
            'display_name' => $profile->display_name ?: 'Kilimani Properties',
            'slug' => $profile->slug ?: 'kilimani-properties',
            'business_name' => 'Kilimani Properties Ltd',
            'business_type' => BusinessType::Landlord,
            'bio' => 'Managed apartments and commercial space across Dar es Salaam since 2014.',
            'region_id' => $region?->id,
            'district_id' => $district?->id,
            'street' => 'Haile Selassie Road, Masaki',
            // Masaki — inside the radius of the seeded listings, so "nearby
            // businesses" returns something from the demo map.
            'latitude' => -6.7424,
            'longitude' => 39.2790,
            'public_phone' => '+255700000900',
            'public_email' => 'hello@kilimani.example',
            'whatsapp' => '+255700000900',
            'website' => 'https://kilimani.example',
            'opening_hours' => [
                'mon' => [['open' => '08:00', 'close' => '17:00']],
                'tue' => [['open' => '08:00', 'close' => '17:00']],
                'wed' => [['open' => '08:00', 'close' => '17:00']],
                'thu' => [['open' => '08:00', 'close' => '17:00']],
                'fri' => [['open' => '08:00', 'close' => '17:00']],
                'sat' => [['open' => '09:00', 'close' => '13:00']],
                'sun' => [],
            ],
            'social_links' => ['instagram' => 'https://instagram.com/kilimani'],
            'is_verified' => true,
            'verified_at' => now(),
            'onboarding_completed_at' => now(),
        ])->save();

        $this->command->info('  Completed the demo business profile.');
    }

    private function seedListings(User $seller): void
    {
        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();
        $attributes = Attribute::query()->pluck('id', 'code');
        $indexer = app(ListingIndexer::class);

        foreach (self::LISTINGS as $row) {
            [$districtName, $wardName] = self::WARDS[$row['neighbourhood']];
            [$latitude, $longitude] = self::COORDINATES[$row['neighbourhood']];

            $district = District::query()
                ->where('region_id', $region->id)
                ->where('name', $districtName)
                ->first();

            $ward = $wardName === null || $district === null
                ? null
                : $district->wards()->where('name', $wardName)->first();

            // Listings attach to LEAF categories only, so the frontend's
            // "subcategory" is the category here and "Property" is its parent.
            $category = Category::query()
                ->where('name', $row['subcategory'])
                ->where('is_leaf', true)
                ->firstOrFail();

            $listing = Listing::query()->withTrashed()->firstOrNew(['slug' => $row['slug']]);

            $listing->forceFill([
                'uuid' => $listing->uuid ?? (string) Str::uuid7(),
                'slug' => $row['slug'],
                'user_id' => $seller->id,
                'category_id' => $category->id,
                'title' => $row['title'],
                'description' => $this->description($row),
                // Read off the title, which already says "Available for
                // Lease" / "for Rent" — so the real field agrees with the copy
                // the frontend has always rendered.
                'purpose' => $this->purpose($row['title']),
                'price' => $row['price'],
                'currency' => 'TZS',
                'price_unit' => PriceUnit::Monthly,
                'is_negotiable' => false,
                'condition' => ListingCondition::Used,
                'region_id' => $region->id,
                'district_id' => $district?->id,
                'ward_id' => $ward?->id,
                'address_line' => $row['neighbourhood'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => ListingStatus::Published,
                'is_verified' => $row['verified'],
                'published_at' => now()->subDays(7),
                'expires_at' => now()->addDays(60),
                'deleted_at' => null,
            ])->save();

            $this->syncAttributes($listing, $row['attributes'], $attributes);

            $media = $this->demoMedia($row['photo'], Listing::class, $listing->id, $row['title']);
            $media->forceFill(['is_primary' => true, 'position' => 0])->save();

            // Rebuilds `search_document`, which the list API now reads
            // attribute values from. SearchService::index() alone would not:
            // it delegates to the driver, and the MySQL driver is a no-op.
            $indexer->index($listing->fresh());
        }
    }

    /**
     * @param  array<string, int>  $values
     * @param  Collection<string, int>  $attributeIds
     */
    private function syncAttributes(Listing $listing, array $values, $attributeIds): void
    {
        $listing->attributeValues()->delete();

        foreach ($values as $code => $value) {
            $attributeId = $attributeIds[$code] ?? null;

            if ($attributeId === null) {
                continue;
            }

            (new ListingAttributeValue)->forceFill([
                'listing_id' => $listing->id,
                'attribute_id' => $attributeId,
                'value_integer' => $value,
            ])->save();
        }
    }

    private function purpose(string $title): ListingPurpose
    {
        return match (true) {
            str_contains($title, 'for Sale') => ListingPurpose::Sale,
            str_contains($title, 'for Rent') => ListingPurpose::Rent,
            default => ListingPurpose::Lease,
        };
    }

    /**
     * Composed from fields that already exist rather than written as marketing
     * copy — the mock data carried no descriptions, and inventing them would be
     * putting words in the client's mouth.
     *
     * @param  array<string, mixed>  $row
     */
    private function description(array $row): string
    {
        $attributes = $row['attributes'];
        $facts = [];

        if (($attributes['beds'] ?? 0) > 0) {
            $facts[] = $attributes['beds'].' bedroom'.($attributes['beds'] === 1 ? '' : 's');
        }

        if (($attributes['bathrooms'] ?? 0) > 0) {
            $facts[] = $attributes['bathrooms'].' bathroom'.($attributes['bathrooms'] === 1 ? '' : 's');
        }

        if (($attributes['sqft'] ?? 0) > 0) {
            $facts[] = number_format((int) $attributes['sqft']).' sqft';
        }

        $summary = $facts === [] ? '' : ' Features '.implode(', ', $facts).'.';

        return $row['title'].' in '.$row['neighbourhood'].', Dar es Salaam.'.$summary;
    }

    /**
     * A media row whose disk resolves to the remote image origin, so
     * Media::url() returns exactly the URL the frontend used to hardcode.
     */
    private function demoMedia(
        string $photo,
        string $type,
        int $id,
        ?string $alt = null,
        MediaCollection $collection = MediaCollection::Gallery,
    ): Media {
        return Media::updateOrCreate(
            ['mediable_type' => $type, 'mediable_id' => $id, 'path' => 'photo-'.$photo],
            [
                'collection' => $collection,
                'disk' => 'demo',
                'original_filename' => 'demo.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'size_bytes' => 0,
                'width' => 1200,
                'height' => 800,
                'alt_text' => $alt,
                'processing_status' => 'complete',
            ],
        );
    }
}
