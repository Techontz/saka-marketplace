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
use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\District;
use App\Models\Facility;
use App\Models\Favorite;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Media;
use App\Models\PublicPlace;
use App\Models\PublicPlaceCategory;
use App\Models\Region;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Listing\ListingIndexer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A marketplace with enough in it to be worth looking at.
 *
 * DemoSeeder reproduces the 15 listings the old hardcoded frontend shipped
 * with, and deliberately invents nothing — that is the right contract for it
 * and it is left alone. But 15 listings from one seller, each with a single
 * photo, cannot demonstrate the things the product is actually built around:
 * filtering needs spread, the map needs listings that are not all in Masaki,
 * the gallery needs more than one image, and "nearby businesses" needs
 * businesses that are not all the same company.
 *
 * This seeder is additive and idempotent. Every record is keyed on its slug
 * and re-run with updateOrCreate, so running it twice changes nothing and it
 * never collides with DemoSeeder's rows.
 *
 *     php artisan db:seed --class=RichDemoSeeder
 *
 * Coordinates are real. Each neighbourhood below is where it says it is, which
 * is what makes radius search, "search this area" and the distance badge
 * return answers you can sanity-check against a real map.
 */
class RichDemoSeeder extends Seeder
{
    /** neighbourhood => [district, ward|null, lat, lng] */
    private const PLACES = [
        'Masaki' => ['Kinondoni', 'Masaki', -6.7420, 39.2790],
        'Oyster Bay' => ['Kinondoni', null, -6.7745, 39.2830],
        'Mikocheni' => ['Kinondoni', 'Mikocheni', -6.7660, 39.2470],
        'Mbezi Beach' => ['Kinondoni', null, -6.7010, 39.2200],
        'Msasani' => ['Kinondoni', null, -6.7570, 39.2750],
        'Upanga' => ['Ilala', 'Upanga East', -6.8100, 39.2870],
        'Kariakoo' => ['Ilala', 'Kariakoo', -6.8180, 39.2730],
        'Ilala' => ['Ilala', null, -6.8235, 39.2695],
        'Ubungo' => ['Ubungo', null, -6.7890, 39.2100],
        'Kimara' => ['Ubungo', null, -6.7760, 39.1550],
        'Temeke' => ['Temeke', null, -6.8560, 39.2740],
        'Mbagala' => ['Temeke', null, -6.9130, 39.2620],
        'Kigamboni' => ['Kigamboni', null, -6.8290, 39.3070],
    ];

    /**
     * Unsplash photo ids, grouped by what they show.
     *
     * Kept as bare ids so the query string is applied once, in {@see photo()}.
     * A listing takes a slice of the group its category maps to, which is what
     * gives every gallery several DIFFERENT photos of the right kind of thing
     * rather than the same picture repeated.
     *
     * @var array<string, list<string>>
     */
    private const PHOTOS = [
        'apartment' => [
            '1522708323590-d24dbb6b0267', '1493809842364-78817add7ffb', '1560448204-e02f11c3d0e2',
            '1502672260266-1c1ef2d93688', '1484154218962-a197022b5858', '1522708323590-d24dbb6b0267',
        ],
        'house' => [
            '1568605114967-8130f3a36994', '1570129477492-45c003edd2be', '1512917774080-9991f1c4c750',
            '1600585154340-be6161a56a0c', '1583608205776-bfd35f0d9f83', '1600607687939-ce8a6c25118c',
        ],
        'villa' => [
            '1613490493576-7fde63acd811', '1600596542815-ffad4c1539a9', '1512917774080-9991f1c4c750',
            '1580587771525-78b9dba3b914', '1600566753086-00f18fb6b3ea', '1600047509807-ba8f99d2cdde',
        ],
        'office' => [
            '1497366754035-f200968a6e72', '1497366811353-6870744d04b2', '1524758631624-e2822e304c36',
            '1567958451986-2de427a4a0be', '1604328698692-f76ea9498e76', '1568992687947-868a62a9f521',
        ],
        'land' => [
            '1500382017468-9049fed747ef', '1464226184884-fa280b87c399', '1416879595882-3373a0480b5b',
            '1523348837708-15d4a09cfac2', '1500076656116-558758c991c1', '1592982537447-7440770cbfc9',
        ],
        'warehouse' => [
            '1553413077-190dd305871c', '1580674285054-bed31e145f59', '1586528116311-ad8dd3c8310d',
            '1601598851547-4302969d0614', '1578575437130-527eed3abbec', '1595246140625-573b715d11dc',
        ],
        'shop' => [
            '1441986300917-64674bd600d8', '1556742049-0cfed4f6a45d', '1604719312566-8912e9227c6a',
            '1578916171728-46686eac8d58', '1567401893414-76b7b1e5a7a5', '1580913428023-02c695666d61',
        ],
        'hotel' => [
            '1566073771259-6a8506099945', '1571003123894-1f0594d2b5d9', '1582719478250-c89cae4dc85b',
            '1590490360182-c33d57733427', '1611892440504-42a792e24d32', '1445019980597-93fa8acb246c',
        ],
        'room' => [
            '1567767292278-a4f21aa2d36e', '1556909114-f6e7ad7d3136', '1552321554-5fefe8c9ef14',
            '1505693416388-ac5ce068fe85', '1540518614846-7eded433c457', '1611892440504-42a792e24d32',
        ],
    ];

    /** Business logos and covers, so no seeded business renders a blank card. */
    private const BUSINESS_PHOTOS = [
        'logo' => [
            '1560179707-f14e90ef3623', '1541354329998-f4d9a9f9297f', '1611162617474-5b21e879e113',
            '1572044162444-ad60f128bdea', '1620288627223-53302f4e8c74', '1614680376573-df3480f0c6ff',
        ],
        'cover' => [
            '1486406146926-c627a92ad1ab', '1497366754035-f200968a6e72', '1504307651254-35680f356dfd',
            '1541888946425-d81bb19240f5', '1497366811353-6870744d04b2', '1524758631624-e2822e304c36',
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('RichDemoSeeder refuses to run in production.');

            return;
        }

        $this->command->info('Seeding a fuller demo marketplace…');

        $this->seedPlaceCategories();
        $businesses = $this->seedBusinesses();
        $this->seedListings($businesses);
        $this->seedPublicPlaces();
        $this->seedEngagement();

        $this->command->info('Done.');
    }

    // ---------------------------------------------------------- businesses

    /**
     * The trades a property marketplace actually needs beside the landlords:
     * agencies, builders, architects, and the shops people use when they move.
     *
     * @return list<User>
     */
    private function seedBusinesses(): array
    {
        $rows = [
            [
                'email' => 'agency@saka.demo', 'first' => 'Neema', 'last' => 'Kileo',
                'slug' => 'zanzi-estates', 'name' => 'Zanzi Estates',
                'type' => BusinessType::Landlord, 'place' => 'Masaki',
                'bio' => 'Residential and commercial letting agency covering the Msasani peninsula. Managing 400+ units since 2011.',
                'phone' => '+255754110001', 'email_public' => 'hello@zanziestates.co.tz',
                'website' => 'https://zanziestates.co.tz', 'verified' => true,
            ],
            [
                'email' => 'contractor@saka.demo', 'first' => 'Baraka', 'last' => 'Mrema',
                'slug' => 'mrema-construction', 'name' => 'Mrema Construction',
                'type' => BusinessType::ServiceProvider, 'place' => 'Ubungo',
                'bio' => 'Design-and-build contractor. Residential blocks, warehouses and commercial fit-outs across Dar es Salaam.',
                'phone' => '+255754110002', 'email_public' => 'projects@mremaconstruction.co.tz',
                'website' => 'https://mremaconstruction.co.tz', 'verified' => true,
            ],
            [
                'email' => 'architect@saka.demo', 'first' => 'Asha', 'last' => 'Ndulane',
                'slug' => 'studio-nia-architects', 'name' => 'Studio Nia Architects',
                'type' => BusinessType::ServiceProvider, 'place' => 'Oyster Bay',
                'bio' => 'Architecture and interiors practice working in coastal East Africa. Passive cooling, local stone, honest budgets.',
                'phone' => '+255754110003', 'email_public' => 'studio@nia.co.tz',
                'website' => 'https://nia.co.tz', 'verified' => false,
            ],
            [
                'email' => 'hardware@saka.demo', 'first' => 'Salim', 'last' => 'Juma',
                'slug' => 'kariakoo-hardware', 'name' => 'Kariakoo Hardware',
                'type' => BusinessType::Shop, 'place' => 'Kariakoo',
                'bio' => 'Cement, steel, plumbing and electrical. Trade counter open six days, delivery across the city.',
                'phone' => '+255754110004', 'email_public' => 'sales@kariakoohardware.co.tz',
                'website' => null, 'verified' => true,
            ],
            [
                'email' => 'furniture@saka.demo', 'first' => 'Grace', 'last' => 'Mollel',
                'slug' => 'mollel-furniture', 'name' => 'Mollel Furniture',
                'type' => BusinessType::Shop, 'place' => 'Mikocheni',
                'bio' => 'Hardwood furniture made in Mikocheni. Beds, dining sets and office desks, built to order.',
                'phone' => '+255754110005', 'email_public' => 'orders@mollelfurniture.co.tz',
                'website' => 'https://mollelfurniture.co.tz', 'verified' => false,
            ],
            [
                'email' => 'movers@saka.demo', 'first' => 'Peter', 'last' => 'Shirima',
                'slug' => 'safari-movers', 'name' => 'Safari Movers',
                'type' => BusinessType::ServiceProvider, 'place' => 'Temeke',
                'bio' => 'House and office moving, packing and short-term storage. Insured, with a fixed quote before we lift anything.',
                'phone' => '+255754110006', 'email_public' => 'book@safarimovers.co.tz',
                'website' => 'https://safarimovers.co.tz', 'verified' => true,
            ],
        ];

        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();
        $users = [];

        foreach ($rows as $index => $row) {
            /** @var User $user */
            $user = User::query()->firstOrNew(['email' => $row['email']]);

            if (! $user->exists) {
                $user->forceFill([
                    'uuid' => (string) Str::uuid7(),
                    'first_name' => $row['first'],
                    'last_name' => $row['last'],
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]);
            }

            $user->forceFill([
                'phone' => $row['phone'],
                'phone_verified_at' => now(),
                'status' => 'active',
            ])->save();

            if (! $user->hasRole(RoleSlug::Seller->value)) {
                $user->assignRole(RoleSlug::Seller->value);
            }

            [$districtName, , $lat, $lng] = self::PLACES[$row['place']];
            $district = District::query()->where('region_id', $region->id)->where('name', $districtName)->first();

            $profile = SellerProfile::query()->firstOrNew(['user_id' => $user->getKey()]);

            $profile->forceFill([
                'user_id' => $user->getKey(),
                'display_name' => $row['name'],
                'slug' => $row['slug'],
                'business_name' => $row['name'].' Ltd',
                'business_type' => $row['type'],
                'bio' => $row['bio'],
                'region_id' => $region->id,
                'district_id' => $district?->id,
                'street' => $row['place'].', Dar es Salaam',
                'latitude' => $lat,
                'longitude' => $lng,
                'public_phone' => $row['phone'],
                'public_email' => $row['email_public'],
                'whatsapp' => $row['phone'],
                'website' => $row['website'],
                'opening_hours' => [
                    'mon' => [['open' => '08:00', 'close' => '17:30']],
                    'tue' => [['open' => '08:00', 'close' => '17:30']],
                    'wed' => [['open' => '08:00', 'close' => '17:30']],
                    'thu' => [['open' => '08:00', 'close' => '17:30']],
                    'fri' => [['open' => '08:00', 'close' => '17:30']],
                    'sat' => [['open' => '09:00', 'close' => '14:00']],
                    'sun' => [],
                ],
                'social_links' => ['instagram' => 'https://instagram.com/'.str_replace('-', '', $row['slug'])],
                'is_verified' => $row['verified'],
                'verified_at' => $row['verified'] ? now() : null,
                'onboarding_completed_at' => now(),
            ])->save();

            /*
             * Logo and cover, so the redesigned business header never falls
             * back to an initial-letter block on seeded data.
             *
             * There is no `Cover` media collection — the schema points at the
             * image through `cover_media_id` on the profile, exactly as
             * VendorProfileController::uploadBranding() does. Both rows are
             * stored under the Logo collection and told apart by which foreign
             * key references them.
             */
            $logo = $this->media(
                self::BUSINESS_PHOTOS['logo'][$index % count(self::BUSINESS_PHOTOS['logo'])],
                SellerProfile::class,
                $profile->id,
                $row['name'].' logo',
                MediaCollection::Logo,
                0,
                true,
            );

            $cover = $this->media(
                self::BUSINESS_PHOTOS['cover'][$index % count(self::BUSINESS_PHOTOS['cover'])],
                SellerProfile::class,
                $profile->id,
                $row['name'].' cover',
                MediaCollection::Logo,
                1,
                false,
            );

            $profile->forceFill([
                'logo_media_id' => $logo->id,
                'cover_media_id' => $cover->id,
            ])->save();

            $users[] = $user;
        }

        $this->command->info('  '.count($users).' businesses, each with a logo and a cover.');

        return $users;
    }

    // ------------------------------------------------------------ listings

    /** @param list<User> $businesses */
    private function seedListings(array $businesses): array
    {
        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();
        $attributeIds = Attribute::query()->pluck('id', 'code');
        $amenityIds = Amenity::query()->pluck('id', 'slug');
        $facilityIds = Facility::query()->pluck('id', 'slug');
        $indexer = app(ListingIndexer::class);

        $rows = $this->listingRows();
        $created = 0;

        /*
         * Property listings belong to LANDLORDS.
         *
         * Round-robin across every seeded business put a villa under an
         * architecture practice and a warehouse under a furniture shop, which
         * makes the business pages read as nonsense. Only trades that actually
         * let property are eligible; the rest exist to populate the directory.
         */
        $landlords = collect($businesses)
            ->filter(fn (User $user) => SellerProfile::query()
                ->where('user_id', $user->getKey())
                ->where('business_type', BusinessType::Landlord)
                ->exists())
            ->values()
            ->all();

        if ($landlords === []) {
            $landlords = $businesses;
        }

        // The original demo landlord takes a share too, so its business page is
        // not left with only DemoSeeder's fifteen.
        $demoLandlord = User::query()->where('email', 'seller@saka.co.tz')->first();

        if ($demoLandlord !== null) {
            $landlords[] = $demoLandlord;
        }

        foreach ($rows as $index => $row) {
            [$districtName, $wardName, $lat, $lng] = self::PLACES[$row['place']];

            $district = District::query()
                ->where('region_id', $region->id)
                ->where('name', $districtName)
                ->first();

            $ward = $wardName === null || $district === null
                ? null
                : $district->wards()->where('name', $wardName)->first();

            $category = Category::query()
                ->where('slug', $row['category'])
                ->where('is_leaf', true)
                ->first();

            if ($category === null) {
                $this->command->warn('  skipped '.$row['slug'].' — category '.$row['category'].' missing.');

                continue;
            }

            $owner = $landlords[$index % count($landlords)];

            $listing = Listing::query()->withTrashed()->firstOrNew(['slug' => $row['slug']]);

            $listing->forceFill([
                'uuid' => $listing->uuid ?? (string) Str::uuid7(),
                'slug' => $row['slug'],
                'user_id' => $owner->id,
                'category_id' => $category->id,
                'title' => $row['title'],
                'description' => $row['description'],
                'purpose' => $row['purpose'],
                'price' => $row['price'],
                'currency' => 'TZS',
                'price_unit' => $row['unit'],
                'is_negotiable' => $row['negotiable'] ?? false,
                'condition' => ListingCondition::Used,
                'region_id' => $region->id,
                'district_id' => $district?->id,
                'ward_id' => $ward?->id,
                'address_line' => $row['place'],
                // Nudged per listing so co-located records do not stack into a
                // single map pin and hide each other.
                'latitude' => round($lat + (($index % 7) - 3) * 0.0025, 6),
                'longitude' => round($lng + (($index % 5) - 2) * 0.0025, 6),
                'status' => ListingStatus::Published,
                'is_verified' => $row['verified'] ?? false,
                'is_featured' => $row['featured'] ?? false,
                'published_at' => now()->subDays(($index % 40) + 1),
                'expires_at' => now()->addDays(60),
                'deleted_at' => null,
            ])->save();

            $this->syncAttributes($listing, $row['attributes'], $attributeIds);

            // ---- gallery -------------------------------------------------
            //
            // Four to six DIFFERENT photos of the right kind of thing. The
            // gallery, the lightbox and the thumbnail strip all need more than
            // one image to be worth building.
            $pool = self::PHOTOS[$row['photos']];
            $count = 4 + ($index % 3);

            for ($position = 0; $position < $count; $position++) {
                $this->media(
                    $pool[($index + $position) % count($pool)],
                    Listing::class,
                    $listing->id,
                    $row['title'],
                    MediaCollection::Gallery,
                    $position,
                    $position === 0,
                );
            }

            // ---- amenities and facilities --------------------------------
            $listing->amenities()->sync(
                collect($row['amenities'] ?? [])->map(fn (string $slug) => $amenityIds[$slug] ?? null)->filter()->all()
            );

            $listing->facilities()->sync(
                collect($row['facilities'] ?? [])->map(fn (string $slug) => $facilityIds[$slug] ?? null)->filter()->all()
            );

            $indexer->index($listing->fresh());
            $created++;
        }

        $this->command->info('  '.$created.' listings, each with 4–6 gallery images, amenities and facilities.');

        return [];
    }

    /**
     * Every property type the brief asks for, spread across the city.
     *
     * @return list<array<string, mixed>>
     */
    private function listingRows(): array
    {
        $rows = [];

        $specs = [
            // [category, photos, label, purpose, unit, base price, places, amenities, facilities, attrs]
            ['property-apartments', 'apartment', 'Apartment', ListingPurpose::Rent, PriceUnit::Monthly, 850_000,
                ['Masaki', 'Oyster Bay', 'Msasani', 'Upanga'],
                ['air-conditioning', 'balcony', 'elevator', 'security', 'parking', 'fibre-internet'],
                ['public-transport', 'shopping-mall', 'school-nearby'],
                ['beds' => 2, 'bathrooms' => 2, 'sqft' => 1150, 'balconies' => 1, 'parkings' => 1]],

            ['property-houses', 'house', 'House', ListingPurpose::Rent, PriceUnit::Monthly, 1_800_000,
                ['Mikocheni', 'Mbezi Beach', 'Kimara', 'Msasani'],
                ['garden', 'parking', 'security', 'water-tank', 'servant-quarters', 'backup-generator'],
                ['school-nearby', 'hospital-nearby', 'market'],
                ['beds' => 4, 'bathrooms' => 3, 'sqft' => 2400, 'balconies' => 1, 'doors' => 6, 'parkings' => 2]],

            ['property-houses', 'villa', 'Villa', ListingPurpose::Sale, PriceUnit::Total, 780_000_000,
                ['Masaki', 'Oyster Bay', 'Mbezi Beach'],
                ['swimming-pool', 'sea-view', 'garden', 'security', 'cctv', 'servant-quarters', 'backup-generator'],
                ['restaurant', 'shopping-mall', 'hospital-nearby'],
                ['beds' => 5, 'bathrooms' => 5, 'sqft' => 4200, 'balconies' => 3, 'parkings' => 4]],

            ['property-offices', 'office', 'Office Suite', ListingPurpose::Lease, PriceUnit::Monthly, 3_200_000,
                ['Posta-less', 'Upanga', 'Ilala', 'Masaki'],
                ['air-conditioning', 'elevator', 'fibre-internet', 'backup-generator', 'cctv', 'parking'],
                ['bank-atm', 'restaurant', 'public-transport'],
                ['sqft' => 1800, 'parkings' => 6]],

            ['property-plots', 'land', 'Plot', ListingPurpose::Sale, PriceUnit::Total, 95_000_000,
                ['Kimara', 'Mbagala', 'Kigamboni', 'Ubungo'],
                ['water-tank'],
                ['public-transport', 'school-nearby'],
                ['sqft' => 10_000]],

            ['property-warehouses', 'warehouse', 'Warehouse', ListingPurpose::Lease, PriceUnit::Monthly, 6_500_000,
                ['Ubungo', 'Temeke', 'Mbagala'],
                ['security', 'cctv', 'parking', 'backup-generator'],
                ['public-transport', 'petrol-station'],
                ['sqft' => 15_000, 'doors' => 4, 'parkings' => 10]],

            ['property-commercial', 'shop', 'Retail Shop', ListingPurpose::Rent, PriceUnit::Monthly, 1_400_000,
                ['Kariakoo', 'Ilala', 'Temeke', 'Ubungo'],
                ['air-conditioning', 'security', 'cctv'],
                ['market', 'bank-atm', 'public-transport'],
                ['sqft' => 750, 'doors' => 2]],

            ['property-hotels', 'hotel', 'Hotel', ListingPurpose::Sale, PriceUnit::Total, 2_400_000_000,
                ['Masaki', 'Oyster Bay', 'Kigamboni'],
                ['swimming-pool', 'sea-view', 'air-conditioning', 'elevator', 'gym', 'security', 'parking'],
                ['restaurant', 'shopping-mall', 'hospital-nearby'],
                ['beds' => 42, 'bathrooms' => 46, 'sqft' => 28_000, 'parkings' => 30]],

            ['property-flats', 'apartment', 'Flat', ListingPurpose::Rent, PriceUnit::Monthly, 620_000,
                ['Upanga', 'Kariakoo', 'Ilala', 'Temeke'],
                ['balcony', 'water-tank', 'security'],
                ['market', 'public-transport', 'pharmacy'],
                ['beds' => 2, 'bathrooms' => 1, 'sqft' => 820, 'balconies' => 1]],

            ['property-rooms', 'room', 'Room', ListingPurpose::Rent, PriceUnit::Monthly, 280_000,
                ['Kimara', 'Mbagala', 'Ubungo', 'Temeke'],
                ['furnished', 'water-tank'],
                ['public-transport', 'market'],
                ['beds' => 1, 'bathrooms' => 1, 'sqft' => 320]],
        ];

        foreach ($specs as [$category, $photos, $label, $purpose, $unit, $base, $places, $amenities, $facilities, $attrs]) {
            foreach ($places as $order => $place) {
                if (! isset(self::PLACES[$place])) {
                    continue;
                }

                // Prices vary per unit so range filters have something to bite
                // on; the spread is deterministic, not random, so re-running
                // the seeder does not reshuffle the catalogue.
                $price = (int) round($base * (1 + ($order * 0.18)));
                $slug = Str::slug($place.'-'.$label.'-'.($order + 1));

                $rows[] = [
                    'slug' => $slug,
                    'title' => $label.' in '.$place.' — '.$this->purposeWord($purpose),
                    'category' => $category,
                    'photos' => $photos,
                    'place' => $place,
                    'purpose' => $purpose,
                    'unit' => $unit,
                    'price' => $price,
                    'negotiable' => $order % 3 === 0,
                    'verified' => $order % 2 === 0,
                    'featured' => $order === 0,
                    'amenities' => array_slice($amenities, 0, 3 + ($order % 4)),
                    'facilities' => array_slice($facilities, 0, 2 + ($order % 2)),
                    'attributes' => $this->varyAttributes($attrs, $order),
                    'description' => $this->describe($label, $place, $attrs, $amenities),
                ];
            }
        }

        return $rows;
    }

    /** @param array<string,int> $attrs @return array<string,int> */
    private function varyAttributes(array $attrs, int $order): array
    {
        $out = [];

        foreach ($attrs as $code => $value) {
            $out[$code] = match ($code) {
                'beds', 'bathrooms' => max(1, $value + ($order % 3) - 1),
                'sqft' => (int) round($value * (1 + ($order * 0.12))),
                default => $value,
            };
        }

        return $out;
    }

    /** @param array<string,int> $attrs @param list<string> $amenities */
    private function describe(string $label, string $place, array $attrs, array $amenities): string
    {
        $facts = [];

        if (($attrs['beds'] ?? 0) > 0) {
            $facts[] = $attrs['beds'].' bedroom'.($attrs['beds'] === 1 ? '' : 's');
        }

        if (($attrs['bathrooms'] ?? 0) > 0) {
            $facts[] = $attrs['bathrooms'].' bathroom'.($attrs['bathrooms'] === 1 ? '' : 's');
        }

        if (($attrs['sqft'] ?? 0) > 0) {
            $facts[] = number_format((int) $attrs['sqft']).' sqft';
        }

        $headline = $label.' in '.$place.', Dar es Salaam.';
        $detail = $facts === [] ? '' : ' '.ucfirst(implode(', ', $facts)).'.';
        $extras = ' Includes '.implode(', ', array_map(
            static fn (string $slug) => str_replace('-', ' ', $slug),
            array_slice($amenities, 0, 3),
        )).'.';

        return $headline.$detail.$extras.' Viewings by appointment; contact the agent through SAKA.';
    }

    private function purposeWord(ListingPurpose $purpose): string
    {
        return match ($purpose) {
            ListingPurpose::Sale => 'For Sale',
            ListingPurpose::Rent => 'For Rent',
            default => 'To Lease',
        };
    }

    /** @param array<string,int> $values */
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

    // ------------------------------------------------------- public places

    /** The three categories the directory was missing. */
    private function seedPlaceCategories(): void
    {
        $rows = [
            ['Police Stations', 'police-stations', '🚓', '1590496793929-36417d3117de'],
            ['Supermarkets', 'supermarkets', '🛒', '1578916171728-46686eac8d58'],
            ['Bus Stations', 'bus-stations', '🚌', '1544620347-c4fd4a3d5957'],
        ];

        foreach ($rows as $position => [$name, $slug, $icon, $photo]) {
            $category = PublicPlaceCategory::query()->firstOrNew(['slug' => $slug]);

            $category->forceFill([
                'name' => $name,
                'slug' => $slug,
                'icon' => $icon,
                'position' => 90 + $position,
                'is_active' => true,
            ])->save();

            $media = $this->media($photo, PublicPlaceCategory::class, $category->id, $name, MediaCollection::CategoryImage);
            $category->forceFill(['image_media_id' => $media->id])->save();
        }

        $this->command->info('  3 new public-place categories (police, supermarkets, bus stations).');
    }

    private function seedPublicPlaces(): void
    {
        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();

        /** @var array<string, list<array{0:string,1:string,2:float,3:float,4:?string}>> */
        $rows = [
            'hospitals' => [
                ['Muhimbili National Hospital', 'Upanga', -6.8047, 39.2717, '+255222151367'],
                ['Aga Khan Hospital', 'Upanga', -6.8100, 39.2900, '+255222115151'],
                ['Regency Medical Centre', 'Upanga', -6.8130, 39.2830, '+255222150500'],
                ['TMJ Hospital', 'Mikocheni', -6.7690, 39.2510, null],
                ['Hindu Mandal Hospital', 'Upanga', -6.8155, 39.2865, null],
            ],
            'schools' => [
                ['Aga Khan Academy', 'Masaki', -6.8536, 39.2586, null],
                ['International School of Tanganyika', 'Masaki', -6.7480, 39.2790, '+255222151817'],
                ['Shaaban Robert Secondary', 'Upanga', -6.8120, 39.2840, null],
                ['Feza Boys Secondary', 'Mbezi Beach', -6.7030, 39.2230, null],
                ['Kibasila Secondary', 'Temeke', -6.8570, 39.2760, null],
            ],
            'police-stations' => [
                ['Central Police Station', 'Ilala', -6.8172, 39.2892, '+255222117362'],
                ['Oysterbay Police Station', 'Oyster Bay', -6.7750, 39.2810, null],
                ['Kinondoni Police Station', 'Mikocheni', -6.7860, 39.2560, null],
                ['Temeke Police Station', 'Temeke', -6.8590, 39.2720, null],
                ['Ubungo Police Station', 'Ubungo', -6.7900, 39.2120, null],
            ],
            'pharmacies' => [
                ['Shoppers Pharmacy Masaki', 'Masaki', -6.7440, 39.2775, null],
                ['MedPlus Mikocheni', 'Mikocheni', -6.7650, 39.2480, null],
                ['City Pharmacy Kariakoo', 'Kariakoo', -6.8190, 39.2740, null],
                ['Kimara Chemist', 'Kimara', -6.7770, 39.1560, null],
            ],
            'supermarkets' => [
                ['Shoppers Supermarket Namanga', 'Msasani', -6.7600, 39.2740, null],
                ['Village Supermarket Masaki', 'Masaki', -6.7430, 39.2800, null],
                ['Shoprite Kariakoo', 'Kariakoo', -6.8200, 39.2720, null],
                ['Uchumi Mbezi', 'Mbezi Beach', -6.7020, 39.2210, null],
                ['Nakumatt Mlimani', 'Ubungo', -6.7760, 39.2090, null],
            ],
            'petrol-stations' => [
                ['Puma Energy Masaki', 'Masaki', -6.7450, 39.2760, null],
                ['Total Msasani', 'Msasani', -6.7580, 39.2760, null],
                ['Oryx Ubungo', 'Ubungo', -6.7880, 39.2110, null],
                ['Engen Mbagala', 'Mbagala', -6.9140, 39.2610, null],
            ],
            'bus-stations' => [
                ['Ubungo Bus Terminal', 'Ubungo', -6.7893, 39.2103, null],
                ['Kariakoo Bus Stand', 'Kariakoo', -6.8185, 39.2735, null],
                ['Mbezi Bus Terminal', 'Mbezi Beach', -6.7000, 39.2180, null],
                ['Temeke Bus Stand', 'Temeke', -6.8580, 39.2730, null],
            ],
            'restaurants' => [
                ['The Waterfront Sunset', 'Msasani', -6.7565, 39.2755, null],
                ['Mamboz Corner BBQ', 'Kariakoo', -6.8175, 39.2745, null],
                ['Cape Town Fish Market', 'Masaki', -6.7425, 39.2795, null],
                ['Chops and Hops', 'Oyster Bay', -6.7740, 39.2835, null],
            ],
            'banks' => [
                ['CRDB Bank Azikiwe', 'Ilala', -6.8165, 39.2885, null],
                ['NMB Bank Head Office', 'Ilala', -6.8150, 39.2870, null],
                ['NBC Bank Masaki', 'Masaki', -6.7435, 39.2785, null],
                ['Stanbic Bank Mikocheni', 'Mikocheni', -6.7655, 39.2475, null],
                ['Exim Bank Upanga', 'Upanga', -6.7824, 39.2926, null],
                ['Equity Bank Kariakoo', 'Kariakoo', -6.8195, 39.2725, null],
            ],
            'hotels' => [
                ['Serena Hotel Dar', 'Ilala', -6.8140, 39.2905, null],
                ['Hyatt Regency Kilimanjaro', 'Ilala', -6.8175, 39.2925, null],
                ['Sea Cliff Hotel', 'Masaki', -6.7405, 39.2755, null],
                ['Golden Tulip Msasani', 'Msasani', -6.7590, 39.2745, null],
            ],
            'shopping-malls' => [
                ['Mlimani City Mall', 'Ubungo', -6.7755, 39.2085, null],
                ['Mkuki House', 'Ilala', -6.8160, 39.2880, null],
                ['Oysterbay Shopping Centre', 'Oyster Bay', -6.7735, 39.2820, null],
                ['Quality Centre Mbezi', 'Mbezi Beach', -6.7015, 39.2195, null],
            ],
        ];

        $placePhotos = [
            'hospitals' => ['1519494026892-80bbd2d6fd0d', '1538108149393-fbbd81895907', '1584982751601-97dcc096659c'],
            'schools' => ['1580582932707-520aed937b7b', '1503676260728-1c00da094a0b', '1509062522246-3755977927d7'],
            'police-stations' => ['1590496793929-36417d3117de', '1453873531674-2151bcd01707', '1521791055366-0d553872125f'],
            'pharmacies' => ['1587854692152-cbe660dbde88', '1576602976047-174e57a47881', '1471864190281-a93a3070b6de'],
            'supermarkets' => ['1578916171728-46686eac8d58', '1580913428023-02c695666d61', '1604719312566-8912e9227c6a'],
            'petrol-stations' => ['1545459720-aac8509eb02c', '1527018601619-a508a2be00cd', '1615906655593-ad0386982a0f'],
            'bus-stations' => ['1544620347-c4fd4a3d5957', '1570125909232-eb263c188f7e', '1494515843206-f3117d3f51b7'],
            'restaurants' => ['1517248135467-4c7edcad34c4', '1552566626-52f8b828add9', '1555396273-367ea4eb4db5'],
            'banks' => ['1565514020179-026b92b2ed33', '1541354329998-f4d9a9f9297f', '1526304640581-d334cdbbf45e'],
            'hotels' => ['1566073771259-6a8506099945', '1571003123894-1f0594d2b5d9', '1582719478250-c89cae4dc85b'],
            'shopping-malls' => ['1519567241046-7f570eee3ce6', '1441986300917-64674bd600d8', '1567401893414-76b7b1e5a7a5'],
        ];

        $total = 0;

        foreach ($rows as $categorySlug => $places) {
            $category = PublicPlaceCategory::query()->where('slug', $categorySlug)->first();

            if ($category === null) {
                continue;
            }

            foreach ($places as $index => [$name, $place, $lat, $lng, $phone]) {
                $districtName = self::PLACES[$place][0] ?? 'Ilala';
                $district = District::query()->where('region_id', $region->id)->where('name', $districtName)->first();

                $record = PublicPlace::query()->firstOrNew(['slug' => Str::slug($name)]);

                $record->forceFill([
                    'uuid' => $record->uuid ?? (string) Str::uuid7(),
                    'public_place_category_id' => $category->id,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $name.' in '.$place.', Dar es Salaam.',
                    'region_id' => $region->id,
                    'district_id' => $district?->id,
                    'address_line' => $place.', Dar es Salaam',
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'phone' => $phone,
                    'is_active' => true,
                ])->save();

                // Every place gets its own image — the directory cards and the
                // map popups both read it.
                $pool = $placePhotos[$categorySlug] ?? $placePhotos['shopping-malls'];
                $media = $this->media($pool[$index % count($pool)], PublicPlace::class, $record->id, $name, MediaCollection::CategoryImage);
                $record->forceFill(['image_media_id' => $media->id])->save();

                $total++;
            }

            $category->forceFill([
                'place_count' => PublicPlace::query()->where('public_place_category_id', $category->id)->count(),
            ])->save();
        }

        $this->command->info('  '.$total.' public places across '.count($rows).' categories, each with an image.');
    }

    // ---------------------------------------------------------- engagement

    /**
     * Reviews, favourites and inquiries.
     *
     * Without these the account area, the vendor inbox and every rating badge
     * render their empty state, which makes finished features look unbuilt.
     */
    private function seedEngagement(): void
    {
        $buyer = User::query()->where('email', 'buyer@saka.test')->first();

        if ($buyer === null) {
            $this->command->warn('  no buyer@saka.test — skipping engagement.');

            return;
        }

        $listings = Listing::query()->where('status', ListingStatus::Published)->take(24)->get();
        $reviews = 0;
        $favourites = 0;
        $inquiries = 0;

        $bodies = [
            5 => ['Exactly as described. The agent answered on WhatsApp within an hour and the viewing was on time.', 'Clean, secure and the water pressure is genuinely good. Would rent here again.'],
            4 => ['Good value for the area. Parking is tighter than the photos suggest but everything else checked out.', 'Solid place. Landlord fixed the geyser the same week we reported it.'],
            3 => ['Fine for the price. Road access gets difficult after heavy rain.', 'Decent space, though the listing photos are flattering.'],
        ];

        foreach ($listings as $index => $listing) {
            // ---- reviews ---------------------------------------------------
            if ($index % 2 === 0) {
                $rating = [5, 4, 3][$index % 3];

                Review::query()->updateOrCreate(
                    ['listing_id' => $listing->id, 'reviewer_id' => $buyer->id],
                    [
                        'uuid' => (string) Str::uuid7(),
                        'seller_id' => $listing->user_id,
                        'rating' => $rating,
                        'title' => ['Great find', 'Solid, would recommend', 'Does the job'][$index % 3],
                        'body' => $bodies[$rating][$index % 2],
                        'status' => 'approved',
                        'moderated_at' => now(),
                        'helpful_count' => ($index * 3) % 11,
                    ],
                );

                $reviews++;
            }

            // ---- favourites -------------------------------------------------
            if ($index % 3 === 0) {
                Favorite::query()->updateOrCreate(
                    [
                        'user_id' => $buyer->id,
                        'favoritable_type' => Listing::class,
                        'favoritable_id' => $listing->id,
                    ],
                    ['removed_at' => null],
                );

                $favourites++;
            }

            // ---- inquiries ----------------------------------------------------
            if ($index % 4 === 0) {
                Inquiry::query()->updateOrCreate(
                    ['listing_id' => $listing->id, 'email' => $buyer->email],
                    [
                        'uuid' => (string) Str::uuid7(),
                        'seller_id' => $listing->user_id,
                        'sender_user_id' => $buyer->id,
                        'first_name' => $buyer->first_name,
                        'last_name' => $buyer->last_name,
                        'phone' => $buyer->phone,
                        'message' => 'Hello, is this still available? I would like to arrange a viewing this week if possible.',
                        'source' => 'listing',
                        'status' => $index % 8 === 0 ? 'replied' : 'new',
                        'reply_body' => $index % 8 === 0 ? 'Yes, still available. Would Thursday afternoon suit you?' : null,
                        'replied_at' => $index % 8 === 0 ? now()->subDay() : null,
                    ],
                );

                $inquiries++;
            }
        }

        // Saved businesses, so the favourites screen has both of its tabs full.
        foreach (SellerProfile::query()->take(4)->get() as $profile) {
            Favorite::query()->updateOrCreate(
                [
                    'user_id' => $buyer->id,
                    'favoritable_type' => SellerProfile::class,
                    'favoritable_id' => $profile->id,
                ],
                ['removed_at' => null],
            );
        }

        $this->recomputeCounters();

        $this->command->info("  {$reviews} reviews, {$favourites} favourites, {$inquiries} inquiries.");
    }

    /**
     * Rebuild the denormalised counters the engagement rows should have moved.
     *
     * `listings.view_count`, `favorite_count` and `inquiry_count` — and
     * `seller_profiles.rating_avg` / `rating_count` — are maintained by the
     * application's own write paths. Inserting rows straight through the model
     * skips those paths entirely, so the vendor dashboard read zeroes while the
     * tables underneath held eleven inquiries and twelve favourites.
     *
     * Recomputing from the source tables is the honest fix: it leaves the API
     * contract alone and makes the seeded state identical to what the app would
     * have produced itself.
     */
    private function recomputeCounters(): void
    {
        DB::statement('
            UPDATE listings l
            SET favorite_count = (
                SELECT COUNT(*) FROM favorites f
                WHERE f.favoritable_type = ?
                  AND f.favoritable_id = l.id
                  AND f.removed_at IS NULL
            )
        ', [Listing::class]);

        DB::statement('
            UPDATE listings l
            SET inquiry_count = (
                SELECT COUNT(*) FROM inquiries i WHERE i.listing_id = l.id
            )
        ');

        DB::statement('
            UPDATE listings l
            SET view_count = (
                SELECT COUNT(*) FROM listing_views v WHERE v.listing_id = l.id
            )
        ');

        /*
         * `active_listings` is not cosmetic: BusinessController filters
         * `is_verified AND active_listings > 0` and sorts the directory by it,
         * so a stale zero hides a business from /businesses entirely.
         */
        DB::statement('
            UPDATE seller_profiles p
            SET total_listings = (
                    SELECT COUNT(*) FROM listings l
                    WHERE l.user_id = p.user_id AND l.deleted_at IS NULL
                ),
                active_listings = (
                    SELECT COUNT(*) FROM listings l
                    WHERE l.user_id = p.user_id AND l.status = ? AND l.deleted_at IS NULL
                )
        ', [ListingStatus::Published->value]);

        DB::statement('
            UPDATE seller_profiles p
            SET rating_count = (
                    SELECT COUNT(*) FROM reviews r
                    WHERE r.seller_id = p.user_id AND r.status = ? AND r.deleted_at IS NULL
                ),
                rating_avg = COALESCE((
                    SELECT AVG(r.rating) FROM reviews r
                    WHERE r.seller_id = p.user_id AND r.status = ? AND r.deleted_at IS NULL
                ), 0)
        ', ['approved', 'approved']);

        $this->command->info('  Recomputed listing and seller counters.');
    }

    // --------------------------------------------------------------- media

    /**
     * A media row whose disk resolves to the remote image origin, so
     * Media::url() returns a working absolute URL without anything being
     * uploaded. Same mechanism DemoSeeder uses.
     */
    private function media(
        string $photo,
        string $type,
        int $id,
        ?string $alt,
        MediaCollection $collection = MediaCollection::Gallery,
        int $position = 0,
        bool $isPrimary = false,
    ): Media {
        return Media::updateOrCreate(
            [
                'mediable_type' => $type,
                'mediable_id' => $id,
                'path' => $this->photo($photo),
            ],
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
                'position' => $position,
                'is_primary' => $isPrimary,
                'processing_status' => 'complete',
            ],
        );
    }

    private function photo(string $id): string
    {
        return 'photo-'.$id.'?auto=format&fit=crop&w=1200&q=80';
    }
}
