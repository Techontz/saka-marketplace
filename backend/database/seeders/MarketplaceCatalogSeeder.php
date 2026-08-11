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
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\District;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Media;
use App\Models\Region;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Listing\LandBoundaryService;
use App\Services\Listing\ListingIndexer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every vertical, not just property.
 *
 * RichDemoSeeder filled the property catalogue and stopped there, which left
 * eight verticals with attributes, filters, landing pages and hero artwork all
 * wired up and NOTHING behind them. A category system cannot be assessed —
 * by a reviewer or by us — when eight of its nine branches return an empty
 * state, so this seeder gives each one real sellers, real listings, real
 * attribute values, real photos and real reviews.
 *
 * It also adds the two verticals the taxonomy was missing (Construction and
 * Industrial) with their own attributes, and draws a surveyed parcel outline on
 * every land listing so the boundary feature has something to display.
 *
 * Additive and idempotent, exactly like RichDemoSeeder: everything is keyed on
 * a slug and written with firstOrNew/updateOrCreate, so a second run is a
 * no-op and neither seeder can collide with the other.
 *
 *     php artisan db:seed --class=MarketplaceCatalogSeeder
 */
class MarketplaceCatalogSeeder extends Seeder
{
    /** neighbourhood => [district, lat, lng] — the same real coordinates. */
    private const PLACES = [
        'Masaki' => ['Kinondoni', -6.7420, 39.2790],
        'Oyster Bay' => ['Kinondoni', -6.7745, 39.2830],
        'Mikocheni' => ['Kinondoni', -6.7660, 39.2470],
        'Mbezi Beach' => ['Kinondoni', -6.7010, 39.2200],
        'Msasani' => ['Kinondoni', -6.7570, 39.2750],
        'Upanga' => ['Ilala', -6.8100, 39.2870],
        'Kariakoo' => ['Ilala', -6.8180, 39.2730],
        'Ilala' => ['Ilala', -6.8235, 39.2695],
        'Ubungo' => ['Ubungo', -6.7890, 39.2100],
        'Kimara' => ['Ubungo', -6.7760, 39.1550],
        'Temeke' => ['Temeke', -6.8560, 39.2740],
        'Mbagala' => ['Temeke', -6.9130, 39.2620],
        'Kigamboni' => ['Kigamboni', -6.8290, 39.3070],
    ];

    /**
     * Photo pools, one per KIND OF THING rather than per category.
     *
     * A pool is sliced so each listing takes a different starting offset — that
     * is what stops every phone in the catalogue showing the same photograph,
     * which is the single most obvious tell that a marketplace is seeded.
     *
     * @var array<string, list<string>>
     */
    private const PHOTOS = [
        'land' => ['1500382017468-9049fed747ef', '1464226184884-fa280b87c399', '1416879595882-3373a0480b5b', '1523348837708-15d4a09cfac2', '1592982537447-7440770cbfc9'],

        'hostel' => ['1567767292278-a4f21aa2d36e', '1556909114-f6e7ad7d3136', '1505693416388-ac5ce068fe85', '1540518614846-7eded433c457'],

        'car' => ['1552519507-da3b142c6e3d', '1503376780353-7e6692767b70', '1494976388531-d1058494cdd8', '1541899481282-d53bffe3c35d', '1583121274602-3e2820c69888'],
        'suv' => ['1519641471654-76ce0107ad1b', '1533473359331-0135ef1b58bf', '1606664515524-ed2f786a0bd6', '1552519507-da3b142c6e3d'],
        'motorcycle' => ['1558981806-ec527fa84c39', '1568772585407-9361f9bf3a87', '1591637333184-19aa84b3e01f', '1449426468159-d96dbf08f19f'],
        'truck' => ['1601584115197-04ecc0da31d7', '1519003722824-194d4455a60c', '1586191582151-f73872dfd183'],
        'bus' => ['1544620347-c4fd4a3d5957', '1570125909232-eb263c188f7e', '1494515843206-f3117d3f51b7'],
        'pickup' => ['1558618666-fcd25c85cd64', '1601584115197-04ecc0da31d7', '1519003722824-194d4455a60c'],
        'boat' => ['1544551763-46a013bb70d5', '1520255870062-bd79d3865de7', '1567899378494-47b22a2ae96a'],
        'car-parts' => ['1486262715619-67b85e0b08d3', '1530046339160-ce3e530c7d2f', '1619642751034-765dfdf7c58e'],
        'tyres' => ['1449965408869-eaa3f722e40d', '1486262715619-67b85e0b08d3'],

        'phone' => ['1511707171634-5f897ff02aa9', '1592750475338-74b7b21085ab', '1580910051074-3eb694886505', '1567581935884-3349723552ca'],
        'laptop' => ['1496181133206-80ce9b88a853', '1517336714731-489689fd1ca8', '1541807084-5c52b6b3adef', '1593642632823-8f785ba67e45'],
        'desktop' => ['1587202372775-e229f172b9d7', '1547082299-de196ea013d6', '1593640495253-23196b27a87f'],
        'tablet' => ['1544244015-0df4b3ffc6b0', '1561154464-82e9adf32764', '1585790050230-5dd28404ccb9'],
        'gaming' => ['1493711662062-fa541adb3fc8', '1606144042614-b2417e99c4e3', '1550745165-9bc0b252726f'],
        'tv' => ['1593359677879-a4bb92f829d1', '1461151304267-38535e780c79', '1567690187548-f07b1d7bf5a9'],
        'camera' => ['1502920917128-1aa500764cbd', '1516035069371-29a1b244cc32', '1495707902641-75cac588d2e9'],
        'accessory' => ['1572569511254-d8f925fe2cbb', '1590658268037-6bf12165a8df', '1585123334904-845d60e97b29'],

        'bed' => ['1505693416388-ac5ce068fe85', '1522771739844-6a9f6d5f14af', '1560448204-e02f11c3d0e2'],
        'sofa' => ['1555041469-a586c61ea9bc', '1567016432779-094069958ea5', '1493663284031-b7e3aefcae8e'],
        'dining' => ['1617806118233-18e1de247200', '1615873968403-89e068629265', '1595428774223-ef52624120d2'],
        'desk' => ['1524758631624-e2822e304c36', '1497366216548-37526070297c', '1593642532400-2682810df593'],
        'wardrobe' => ['1558997519-83ea9252edf8', '1595526114035-0d45ed16cfbf', '1540518614846-7eded433c457'],
        'kitchen-unit' => ['1556909114-f6e7ad7d3136', '1600585154340-be6161a56a0c', '1556911220-bff31c812dba'],

        'menswear' => ['1516257984-b1b4d707412e', '1490578474895-699cd4e2cf59', '1507003211169-0a1dd7228f2d'],
        'womenswear' => ['1483985988355-763728e1935b', '1595777457583-95e059d581b8', '1490481651871-ab68de25d43d'],
        'kidswear' => ['1503944168849-8bf86875bbd8', '1519457431-44ccd64a579b', '1471286174890-9c112ffca5b4'],
        'shoes' => ['1542291026-7eec264c27ff', '1549298916-b41d501d3772', '1595950653106-6c9ebd614d3a'],
        'bags' => ['1548036328-c9fa89d128fa', '1584917865442-de89df76afd3', '1591561954557-26941169b49e'],
        'jewellery' => ['1515562141207-7a88fb7ce338', '1611652022419-a9419f74343d', '1599643478518-a784e5dc4c8f'],
        'watches' => ['1524592094714-0f0654e20314', '1523170335258-f5ed11844a49', '1587836374828-4dbafa94cf0e'],

        'office-job' => ['1521791136064-7986c2920216', '1497215728101-856f4ea42174', '1600880292203-757bb62b4baf'],
        'field-job' => ['1454165804606-c3d57bc86b40', '1521737604893-d14cc237f11d', '1552664730-d307ca884978'],

        'cleaning' => ['1581578731548-c64695cc6952', '1585421514738-01798e348b17', '1527515637462-cff94eecc1ac'],
        'moving' => ['1600518464441-9154a4dea21b', '1530124566582-a618bc2615dc', '1586528116311-ad8dd3c8310d'],
        'repair' => ['1581092160562-40aa08e78837', '1621905251189-08b45d6a269e', '1607472586893-edb57bdc0e39'],
        'builder' => ['1541888946425-d81bb19240f5', '1541976590-713941681591', '1504307651254-35680f356dfd'],
        'plumbing' => ['1607472586893-edb57bdc0e39', '1585704032915-c3400ca199e7'],
        'electrical' => ['1621905251918-48416bd8575a', '1558618666-fcd25c85cd64'],
        'painting' => ['1562259949-e8e7689d7828', '1589939705384-5185137a7f0f'],
        'photography' => ['1502920917128-1aa500764cbd', '1554048612-b6a482bc67e5'],

        'livestock' => ['1516467508483-a7212febe31a', '1500595046743-cd271d694d30', '1570042225831-d98fa7577f1e'],
        'seeds' => ['1574323347407-f5e1ad6d020b', '1416879595882-3373a0480b5b', '1523348837708-15d4a09cfac2'],
        'farm-machine' => ['1530267981375-f0de937f5f13', '1592982537447-7440770cbfc9', '1500382017468-9049fed747ef'],
        'fertiliser' => ['1585314062340-f1a5a7c9328d', '1464226184884-fa280b87c399'],
        'animal-feed' => ['1560493676-04071c5f467b', '1500076656116-558758c991c1'],

        'dog' => ['1517849845537-4d257902454a', '1543466835-00a7907e9de1', '1552053831-71594a27632d'],
        'cat' => ['1514888286974-6c03e2ca1dba', '1526336024174-e58f5cdd8e13', '1495360010541-f48722b34f7d'],
        'bird' => ['1552728089-57bdde30beb3', '1522858547137-f1dcec554f55'],
        'fish' => ['1520302630591-fd1c66edc19d', '1544552866-d3ed42536cfd'],
        'pet-food' => ['1589924691995-400dc9ecc119', '1601758228041-f3b2795255f1'],

        'materials' => ['1541888946425-d81bb19240f5', '1504307651254-35680f356dfd', '1541976590-713941681591'],
        'tools' => ['1581092160562-40aa08e78837', '1572981779307-38b8cabb2407', '1504148455328-c376907d081c'],
        'heavy-plant' => ['1530267981375-f0de937f5f13', '1581094794329-c8112a89af12', '1487754180451-c456f719a1fc'],
        'generator' => ['1497440001374-f26997328c1b', '1509390144018-eeaf65052242', '1581094794329-c8112a89af12'],
        'pump' => ['1585704032915-c3400ca199e7', '1607472586893-edb57bdc0e39'],
        'industrial-machine' => ['1565043666747-69f6646db940', '1581091226033-d5c48150dbaa', '1504917595217-d4dc5ebe6122'],
        'safety-gear' => ['1618090584176-7132b9911657', '1581094794329-c8112a89af12'],
    ];

    private const SELLER_LOGOS = [
        '1560179707-f14e90ef3623', '1541354329998-f4d9a9f9297f', '1611162617474-5b21e879e113',
        '1572044162444-ad60f128bdea', '1620288627223-53302f4e8c74', '1614680376573-df3480f0c6ff',
        '1567443024551-f3e3cc2be870', '1519389950473-47ba0277781c', '1553877522-43269d4ea984',
    ];

    private const SELLER_COVERS = [
        '1486406146926-c627a92ad1ab', '1497366754035-f200968a6e72', '1541888946425-d81bb19240f5',
        '1497366811353-6870744d04b2', '1524758631624-e2822e304c36', '1441986300917-64674bd600d8',
        '1519389950473-47ba0277781c', '1504384308090-c894fdcc538d', '1497215728101-856f4ea42174',
    ];

    /** @var array<string, Attribute> */
    private array $attributes = [];

    /** @var array<string, array<string, int>> attribute code => option value => id */
    private array $options = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('MarketplaceCatalogSeeder refuses to run in production.');

            return;
        }

        $this->command->info('Seeding the non-property verticals…');

        $this->seedMissingVerticals();
        $this->loadAttributes();

        $sellers = $this->seedSellers();
        $this->seedListings($sellers);
        $this->seedLandBoundaries();
        $this->seedEngagement();
        $this->recomputeCounters();

        $this->command->info('Done.');
    }

    // ------------------------------------------------------ new verticals

    /**
     * Construction and Industrial.
     *
     * Both were in the product's category list and in neither the taxonomy nor
     * the seeder, so a customer picking them from a menu got a 404. They are
     * created here rather than in CatalogSeeder because CatalogSeeder is the
     * canonical taxonomy for the milestones already signed off, and editing it
     * would silently change what those migrations produce on a fresh install.
     */
    private function seedMissingVerticals(): void
    {
        // Two attributes these verticals need and nothing else has.
        $newAttributes = [
            'material_grade' => ['Grade / Specification', 'text', 'string', null, true],
            'power_kva' => ['Power output', 'number', 'decimal', 'kVA', true],
        ];

        foreach ($newAttributes as $code => [$name, $input, $data, $unit, $filterable]) {
            Attribute::updateOrCreate(['code' => $code], [
                'name' => $name,
                'input_type' => $input,
                'data_type' => $data,
                'unit' => $unit,
                'is_filterable' => $filterable,
                'is_searchable' => false,
                'position' => 900,
            ]);
        }

        $verticals = [
            'Construction' => [
                'icon' => '🏗️',
                'image' => '1541888946425-d81bb19240f5',
                'subcategories' => ['Building Materials', 'Tools & Equipment', 'Heavy Machinery', 'Steel & Cement', 'Roofing', 'Tiles & Sanitary'],
                'attributes' => ['brand', 'material_grade', 'quantity', 'unit_of_measure', 'warranty_months'],
            ],
            'Industrial' => [
                'icon' => '🏭',
                'image' => '1565043666747-69f6646db940',
                'subcategories' => ['Generators', 'Pumps & Compressors', 'Industrial Machinery', 'Packaging Equipment', 'Safety Equipment', 'Industrial Spares'],
                'attributes' => ['brand', 'power_kva', 'year', 'warranty_months', 'quantity'],
            ],
        ];

        $basePosition = (int) Category::query()->whereNull('parent_id')->max('position');

        foreach ($verticals as $name => $spec) {
            $root = Category::updateOrCreate(['slug' => Str::slug($name)], [
                'parent_id' => null,
                'name' => $name,
                'icon' => $spec['icon'],
                'depth' => 0,
                'position' => $basePosition += 10,
                'is_active' => true,
                'is_leaf' => false,
            ]);

            $root->forceFill(['path' => (string) $root->id])->save();

            // The vertical's own artwork, which every hero and category card
            // reads. Without it these two would fall back to the property photo
            // and a page about generators would be framed by apartments.
            $media = $this->media($spec['image'], Category::class, $root->id, $name, MediaCollection::CategoryImage);
            $root->forceFill(['image_media_id' => $media->id])->save();

            foreach ($spec['subcategories'] as $i => $subName) {
                $sub = Category::updateOrCreate(['slug' => Str::slug("{$name} {$subName}")], [
                    'parent_id' => $root->id,
                    'name' => $subName,
                    'depth' => 1,
                    'position' => $i * 10,
                    'is_active' => true,
                    'is_leaf' => true,
                ]);

                $sub->forceFill(['path' => "{$root->id}/{$sub->id}"])->save();
            }

            // Attributes bind to the VERTICAL and resolve down to every leaf,
            // which is the existing contract — see Category::resolvedAttributes.
            $bindings = [];

            foreach ($spec['attributes'] as $position => $code) {
                $attribute = Attribute::query()->where('code', $code)->first();

                if ($attribute !== null) {
                    $bindings[$attribute->id] = [
                        'is_required' => false,
                        'is_filterable' => true,
                        'position' => $position * 10,
                    ];
                }
            }

            DB::table('category_attribute')->where('category_id', $root->id)->delete();

            foreach ($bindings as $attributeId => $pivot) {
                DB::table('category_attribute')->insert(array_merge($pivot, [
                    'category_id' => $root->id,
                    'attribute_id' => $attributeId,
                ]));
            }
        }

        $this->command->info('  2 new verticals (Construction, Industrial) with 12 subcategories.');
    }

    private function loadAttributes(): void
    {
        $this->attributes = Attribute::query()->get()->keyBy('code')->all();

        $byId = [];

        foreach ($this->attributes as $attribute) {
            $byId[$attribute->id] = $attribute->code;
        }

        foreach (AttributeOption::query()->get() as $option) {
            $code = $byId[$option->attribute_id] ?? null;

            if ($code !== null) {
                $this->options[$code][$option->value] = $option->id;
            }
        }
    }

    // ------------------------------------------------------------- sellers

    /**
     * A trader per vertical.
     *
     * Round-robining listings across the six property businesses would have put
     * motorcycles under an architecture practice. Each vertical gets a seller
     * whose business plausibly sells that thing, because the business page is a
     * real page a customer lands on and it has to read as a real company.
     *
     * @return array<string, User> vertical slug => seller
     */
    private function seedSellers(): array
    {
        $rows = [
            ['vertical' => 'vehicles', 'email' => 'motors@saka.demo', 'first' => 'Juma', 'last' => 'Kessy',
                'slug' => 'kessy-motors', 'name' => 'Kessy Motors', 'type' => BusinessType::CarDealer, 'place' => 'Ubungo',
                'bio' => 'Japanese and German imports, inspected before they leave the yard. Trade-ins accepted, financing arranged with CRDB and NMB.',
                'phone' => '+255754220001', 'public' => 'sales@kessymotors.co.tz', 'web' => 'https://kessymotors.co.tz', 'verified' => true],

            ['vertical' => 'electronics', 'email' => 'techzone@saka.demo', 'first' => 'Fatma', 'last' => 'Rashid',
                'slug' => 'techzone-tz', 'name' => 'TechZone Tanzania', 'type' => BusinessType::Shop, 'place' => 'Kariakoo',
                'bio' => 'Phones, laptops and accessories with a real warranty and a real counter you can walk into. Six branches across Dar.',
                'phone' => '+255754220002', 'public' => 'shop@techzone.co.tz', 'web' => 'https://techzone.co.tz', 'verified' => true],

            ['vertical' => 'fashion', 'email' => 'boutique@saka.demo', 'first' => 'Zawadi', 'last' => 'Mushi',
                'slug' => 'zawadi-boutique', 'name' => 'Zawadi Boutique', 'type' => BusinessType::Shop, 'place' => 'Masaki',
                'bio' => 'Contemporary East African tailoring and imported labels. Alterations in-house, usually same week.',
                'phone' => '+255754220003', 'public' => 'hello@zawadiboutique.co.tz', 'web' => 'https://zawadiboutique.co.tz', 'verified' => false],

            ['vertical' => 'jobs', 'email' => 'recruit@saka.demo', 'first' => 'Emmanuel', 'last' => 'Lyimo',
                'slug' => 'ajira-recruitment', 'name' => 'Ajira Recruitment', 'type' => BusinessType::ServiceProvider, 'place' => 'Upanga',
                'bio' => 'Permanent and contract hiring for hospitality, logistics and construction. We do not charge candidates, ever.',
                'phone' => '+255754220004', 'public' => 'careers@ajira.co.tz', 'web' => 'https://ajira.co.tz', 'verified' => true],

            ['vertical' => 'agriculture', 'email' => 'agri@saka.demo', 'first' => 'Neema', 'last' => 'Sanga',
                'slug' => 'kilimo-supplies', 'name' => 'Kilimo Supplies', 'type' => BusinessType::Shop, 'place' => 'Kimara',
                'bio' => 'Certified seed, fertiliser and smallholder machinery. Agronomist on site Tuesdays and Thursdays.',
                'phone' => '+255754220005', 'public' => 'orders@kilimosupplies.co.tz', 'web' => null, 'verified' => true],

            ['vertical' => 'pets', 'email' => 'pets@saka.demo', 'first' => 'Daniel', 'last' => 'Mwakalinga',
                'slug' => 'pet-corner-dar', 'name' => 'Pet Corner Dar', 'type' => BusinessType::Shop, 'place' => 'Mikocheni',
                'bio' => 'Registered breeder and pet supply shop. Every animal leaves vaccinated, chipped and with its papers.',
                'phone' => '+255754220006', 'public' => 'info@petcorner.co.tz', 'web' => 'https://petcorner.co.tz', 'verified' => false],

            ['vertical' => 'construction', 'email' => 'buildmart@saka.demo', 'first' => 'Hamisi', 'last' => 'Kombo',
                'slug' => 'buildmart-tz', 'name' => 'BuildMart Tanzania', 'type' => BusinessType::Shop, 'place' => 'Mbagala',
                'bio' => 'Cement, steel, roofing and sanitaryware at trade prices. Site delivery across the coast region.',
                'phone' => '+255754220007', 'public' => 'trade@buildmart.co.tz', 'web' => 'https://buildmart.co.tz', 'verified' => true],

            ['vertical' => 'industrial', 'email' => 'industrial@saka.demo', 'first' => 'Rehema', 'last' => 'Mkumbo',
                'slug' => 'mkumbo-industrial', 'name' => 'Mkumbo Industrial', 'type' => BusinessType::Shop, 'place' => 'Temeke',
                'bio' => 'Generators, pumps and processing plant. Installation, service contracts and genuine spares held in stock.',
                'phone' => '+255754220008', 'public' => 'sales@mkumboindustrial.co.tz', 'web' => 'https://mkumboindustrial.co.tz', 'verified' => true],
        ];

        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();
        $sellers = [];

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

            [$districtName, $lat, $lng] = self::PLACES[$row['place']];
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
                'public_email' => $row['public'],
                'whatsapp' => $row['phone'],
                'website' => $row['web'],
                'opening_hours' => [
                    'mon' => [['open' => '08:00', 'close' => '18:00']],
                    'tue' => [['open' => '08:00', 'close' => '18:00']],
                    'wed' => [['open' => '08:00', 'close' => '18:00']],
                    'thu' => [['open' => '08:00', 'close' => '18:00']],
                    'fri' => [['open' => '08:00', 'close' => '18:00']],
                    'sat' => [['open' => '09:00', 'close' => '15:00']],
                    'sun' => [],
                ],
                'social_links' => ['instagram' => 'https://instagram.com/'.str_replace('-', '', $row['slug'])],
                'is_verified' => $row['verified'],
                'verified_at' => $row['verified'] ? now() : null,
                'onboarding_completed_at' => now(),
            ])->save();

            $logo = $this->media(
                self::SELLER_LOGOS[$index % count(self::SELLER_LOGOS)],
                SellerProfile::class, $profile->id, $row['name'].' logo', MediaCollection::Logo, 0, true,
            );

            $cover = $this->media(
                self::SELLER_COVERS[$index % count(self::SELLER_COVERS)],
                SellerProfile::class, $profile->id, $row['name'].' cover', MediaCollection::Logo, 1, false,
            );

            $profile->forceFill(['logo_media_id' => $logo->id, 'cover_media_id' => $cover->id])->save();

            $sellers[$row['vertical']] = $user;
        }

        /*
         * Furniture and Services already have the right trader.
         *
         * RichDemoSeeder created Mollel Furniture and Safari Movers as part of
         * the property directory. Inventing a second furniture shop here would
         * split the catalogue across two businesses that sell the same thing
         * and leave both business pages looking half-stocked, so these two
         * verticals reuse the sellers that already exist.
         */
        $reused = [
            'furniture' => 'furniture@saka.demo',
            'services' => 'movers@saka.demo',
            // Hostels are the one property leaf RichDemoSeeder left empty; the
            // letting agency is the right owner for them.
            'property' => 'agency@saka.demo',
        ];

        foreach ($reused as $vertical => $email) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                $sellers[$vertical] = $user;
            } else {
                $this->command->warn("  no seller for {$vertical} ({$email}) — run RichDemoSeeder first.");
            }
        }

        $this->command->info('  '.count($sellers).' vertical sellers, each with a logo and a cover.');

        return $sellers;
    }

    // ------------------------------------------------------------ listings

    /**
     * @param  array<string, User>  $sellers
     */
    private function seedListings(array $sellers): void
    {
        $region = Region::query()->where('slug', 'dar-es-salaam')->firstOrFail();
        $indexer = app(ListingIndexer::class);
        $created = 0;
        $skipped = [];

        foreach ($this->catalogue() as $index => $row) {
            $category = Category::query()->where('slug', $row['category'])->where('is_leaf', true)->first();

            if ($category === null) {
                $skipped[] = $row['category'];

                continue;
            }

            $vertical = Category::query()->find($category->parent_id)?->slug ?? $category->slug;
            $owner = $sellers[$vertical] ?? null;

            if ($owner === null) {
                $skipped[] = $vertical;

                continue;
            }

            [$districtName, $lat, $lng] = self::PLACES[$row['place']];
            $district = District::query()->where('region_id', $region->id)->where('name', $districtName)->first();

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
                'is_negotiable' => $index % 3 === 0,
                'condition' => $row['condition'],
                'region_id' => $region->id,
                'district_id' => $district?->id,
                'ward_id' => null,
                'address_line' => $row['place'].', Dar es Salaam',
                // Spread so co-located rows do not stack into one map pin.
                'latitude' => round($lat + (($index % 9) - 4) * 0.0022, 6),
                'longitude' => round($lng + (($index % 7) - 3) * 0.0022, 6),
                'status' => ListingStatus::Published,
                'is_verified' => $index % 3 === 0,
                'is_featured' => $index % 11 === 0,
                'published_at' => now()->subDays(($index % 45) + 1),
                'expires_at' => now()->addDays(60),
                'deleted_at' => null,
            ])->save();

            $this->setAttributes($listing, $row['attributes']);

            $pool = self::PHOTOS[$row['photos']];
            $count = min(count($pool), 3 + ($index % 3));

            for ($position = 0; $position < $count; $position++) {
                $this->media(
                    $pool[($index + $position) % count($pool)],
                    Listing::class, $listing->id, $row['title'],
                    MediaCollection::Gallery, $position, $position === 0,
                );
            }

            $indexer->index($listing->fresh());
            $created++;
        }

        if ($skipped !== []) {
            $this->command->warn('  skipped: '.implode(', ', array_unique($skipped)));
        }

        $this->command->info('  '.$created.' listings across the non-property verticals.');
    }

    /**
     * The catalogue itself.
     *
     * Each entry produces several listings — one per variant — so every leaf
     * category has more than one row, prices spread far enough for a range
     * filter to bite, and conditions vary so the condition filter is not a
     * single-value dropdown.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        $specs = [
            // The one property leaf with nothing in it. Student and worker
            // hostels are a real segment here and an empty category page is a
            // dead end a customer can reach from the main menu.
            ['property-hostels', 'hostel', ListingPurpose::Rent, PriceUnit::Monthly, [
                ['Student Hostel Bed — Ubungo, near UDSM', 180_000, 'Ubungo', ListingCondition::Used, ['beds' => 1, 'bathrooms' => 1, 'sqft' => 180, 'furnishing' => 'furnished']],
                ['Ladies Hostel Room — Kimara', 240_000, 'Kimara', ListingCondition::Used, ['beds' => 1, 'bathrooms' => 1, 'sqft' => 220, 'furnishing' => 'semi-furnished']],
                ['Workers Hostel — twin share, Temeke', 150_000, 'Temeke', ListingCondition::Used, ['beds' => 2, 'bathrooms' => 1, 'sqft' => 260, 'furnishing' => 'furnished']],
            ]],

            /*
             * Land, at the sizes land is actually sold in.
             *
             * RichDemoSeeder's four plots are all a quarter acre, which makes
             * every parcel outline on the site the same size and tells a
             * reviewer nothing about whether the measurement works. These span
             * half an acre to five acres so the area readout, the unit switch
             * (m² → acres → hectares) and the map framing all get exercised.
             */
            ['property-plots', 'land', ListingPurpose::Sale, PriceUnit::Total, [
                ['Half-Acre Serviced Plot — Kigamboni', 68_000_000, 'Kigamboni', ListingCondition::New, ['sqft' => 21_780]],
                ['One-Acre Plot with Title Deed — Mbezi Beach', 195_000_000, 'Mbezi Beach', ListingCondition::New, ['sqft' => 43_560]],
                ['Two-Acre Corner Plot — Kimara Mwisho', 240_000_000, 'Kimara', ListingCondition::New, ['sqft' => 87_120]],
                ['Five-Acre Farm Plot — Mbagala Kuu', 310_000_000, 'Mbagala', ListingCondition::New, ['sqft' => 217_800]],
            ]],

            // ---------------------------------------------------- vehicles
            ['vehicles-cars', 'car', ListingPurpose::Sale, PriceUnit::Total, [
                ['Toyota Corolla 1.8 Petrol', 24_500_000, 'Ubungo', ListingCondition::Used, ['make' => 'Toyota', 'model' => 'Corolla', 'year' => 2016, 'mileage' => 98_000, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'engine_cc' => 1800, 'colour' => 'white']],
                ['Mercedes-Benz C200 AMG Line', 68_000_000, 'Masaki', ListingCondition::Used, ['make' => 'Mercedes-Benz', 'model' => 'C200', 'year' => 2019, 'mileage' => 42_000, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'engine_cc' => 2000, 'colour' => 'black']],
                ['Volkswagen Golf TDI', 19_800_000, 'Mikocheni', ListingCondition::Used, ['make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2014, 'mileage' => 132_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 1600, 'colour' => 'blue']],
                ['Toyota Axio Hybrid', 31_000_000, 'Kariakoo', ListingCondition::Used, ['make' => 'Toyota', 'model' => 'Axio', 'year' => 2018, 'mileage' => 61_000, 'fuel_type' => 'hybrid', 'transmission' => 'automatic', 'engine_cc' => 1500, 'colour' => 'grey']],
            ]],

            ['vehicles-suvs', 'suv', ListingPurpose::Sale, PriceUnit::Total, [
                ['Toyota Land Cruiser Prado TX', 96_000_000, 'Masaki', ListingCondition::Used, ['make' => 'Toyota', 'model' => 'Prado', 'year' => 2017, 'mileage' => 88_000, 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'engine_cc' => 2800, 'colour' => 'white']],
                ['Nissan X-Trail 4WD', 38_500_000, 'Mbezi Beach', ListingCondition::Used, ['make' => 'Nissan', 'model' => 'X-Trail', 'year' => 2016, 'mileage' => 104_000, 'fuel_type' => 'petrol', 'transmission' => 'automatic', 'engine_cc' => 2000, 'colour' => 'grey']],
                ['Mitsubishi Pajero Sport', 52_000_000, 'Ubungo', ListingCondition::Used, ['make' => 'Mitsubishi', 'model' => 'Pajero Sport', 'year' => 2018, 'mileage' => 76_000, 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'engine_cc' => 2400, 'colour' => 'black']],
            ]],

            ['vehicles-motorcycles', 'motorcycle', ListingPurpose::Sale, PriceUnit::Total, [
                ['Bajaj Boxer 150 — bodaboda ready', 2_450_000, 'Temeke', ListingCondition::Used, ['make' => 'Bajaj', 'model' => 'Boxer 150', 'year' => 2022, 'mileage' => 24_000, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'engine_cc' => 150, 'colour' => 'red']],
                ['Honda CB125F', 3_200_000, 'Kimara', ListingCondition::New, ['make' => 'Honda', 'model' => 'CB125F', 'year' => 2024, 'mileage' => 400, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'engine_cc' => 125, 'colour' => 'black']],
                ['TVS Apache RTR 160', 4_100_000, 'Mbagala', ListingCondition::Used, ['make' => 'TVS', 'model' => 'Apache RTR 160', 'year' => 2021, 'mileage' => 31_000, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'engine_cc' => 160, 'colour' => 'blue']],
            ]],

            ['vehicles-trucks', 'truck', ListingPurpose::Sale, PriceUnit::Total, [
                ['FAW J5 10-Tonne Tipper', 118_000_000, 'Ubungo', ListingCondition::Used, ['make' => 'FAW', 'model' => 'J5', 'year' => 2019, 'mileage' => 210_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 8600, 'colour' => 'white']],
                ['Isuzu FVR Cargo Body', 87_500_000, 'Temeke', ListingCondition::Used, ['make' => 'Isuzu', 'model' => 'FVR', 'year' => 2017, 'mileage' => 265_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 7800, 'colour' => 'white']],
            ]],

            ['vehicles-buses', 'bus', ListingPurpose::Sale, PriceUnit::Total, [
                ['Toyota Coaster 30-Seater', 96_000_000, 'Ubungo', ListingCondition::Used, ['make' => 'Toyota', 'model' => 'Coaster', 'year' => 2016, 'mileage' => 178_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 4200, 'colour' => 'white']],
                ['Nissan Civilian 26-Seater', 61_000_000, 'Mbagala', ListingCondition::Used, ['make' => 'Nissan', 'model' => 'Civilian', 'year' => 2013, 'mileage' => 240_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 4200, 'colour' => 'white']],
            ]],

            ['vehicles-pickups', 'pickup', ListingPurpose::Sale, PriceUnit::Total, [
                ['Toyota Hilux Double Cab 2.4', 74_000_000, 'Mikocheni', ListingCondition::Used, ['make' => 'Toyota', 'model' => 'Hilux', 'year' => 2019, 'mileage' => 92_000, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'engine_cc' => 2400, 'colour' => 'grey']],
                ['Ford Ranger XLT', 66_000_000, 'Mbezi Beach', ListingCondition::Used, ['make' => 'Ford', 'model' => 'Ranger', 'year' => 2018, 'mileage' => 110_000, 'fuel_type' => 'diesel', 'transmission' => 'automatic', 'engine_cc' => 3200, 'colour' => 'blue']],
            ]],

            ['vehicles-boats', 'boat', ListingPurpose::Sale, PriceUnit::Total, [
                ['Fibreglass Fishing Boat 26ft', 28_000_000, 'Kigamboni', ListingCondition::Used, ['make' => 'Yamaha', 'model' => '26ft Skiff', 'year' => 2015, 'fuel_type' => 'petrol', 'engine_cc' => 850, 'colour' => 'white']],
                ['Passenger Dhow — licensed 24 seats', 41_000_000, 'Kigamboni', ListingCondition::Used, ['make' => 'Local build', 'model' => 'Dhow', 'year' => 2012, 'fuel_type' => 'diesel', 'engine_cc' => 2200, 'colour' => 'brown']],
            ]],

            ['vehicles-spare-parts', 'car-parts', ListingPurpose::Sale, PriceUnit::Total, [
                ['Toyota 2AZ-FE Engine — reconditioned', 4_800_000, 'Kariakoo', ListingCondition::Refurbished, ['make' => 'Toyota', 'model' => '2AZ-FE', 'colour' => 'grey']],
                ['Nissan Navara Gearbox', 3_300_000, 'Ubungo', ListingCondition::Used, ['make' => 'Nissan', 'model' => 'Navara', 'colour' => 'grey']],
            ]],

            ['vehicles-tyres', 'tyres', ListingPurpose::Sale, PriceUnit::Total, [
                ['Dunlop 265/65 R17 — set of four', 1_450_000, 'Ubungo', ListingCondition::New, ['make' => 'Dunlop', 'model' => '265/65 R17', 'colour' => 'black']],
                ['Michelin 195/65 R15 — set of four', 890_000, 'Kariakoo', ListingCondition::New, ['make' => 'Michelin', 'model' => '195/65 R15', 'colour' => 'black']],
            ]],

            // ------------------------------------------------- electronics
            ['electronics-phones', 'phone', ListingPurpose::Sale, PriceUnit::Total, [
                ['iPhone 14 Pro 256GB', 2_150_000, 'Kariakoo', ListingCondition::Used, ['brand' => 'Apple', 'storage_gb' => 256, 'ram_gb' => 6, 'screen_size' => 6.1, 'warranty_months' => 3, 'colour' => 'black']],
                ['Samsung Galaxy S23 128GB', 1_480_000, 'Masaki', ListingCondition::New, ['brand' => 'Samsung', 'storage_gb' => 128, 'ram_gb' => 8, 'screen_size' => 6.1, 'warranty_months' => 12, 'colour' => 'green']],
                ['Tecno Camon 20 256GB', 545_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Tecno', 'storage_gb' => 256, 'ram_gb' => 8, 'screen_size' => 6.7, 'warranty_months' => 12, 'colour' => 'blue']],
                ['Infinix Hot 40i', 315_000, 'Temeke', ListingCondition::New, ['brand' => 'Infinix', 'storage_gb' => 128, 'ram_gb' => 4, 'screen_size' => 6.6, 'warranty_months' => 12, 'colour' => 'white']],
            ]],

            ['electronics-laptops', 'laptop', ListingPurpose::Sale, PriceUnit::Total, [
                ['MacBook Air M2 512GB', 3_400_000, 'Masaki', ListingCondition::Used, ['brand' => 'Apple', 'storage_gb' => 512, 'ram_gb' => 16, 'screen_size' => 13.6, 'warranty_months' => 6, 'colour' => 'grey']],
                ['Dell Latitude 5420 i5', 1_150_000, 'Upanga', ListingCondition::Refurbished, ['brand' => 'Dell', 'storage_gb' => 256, 'ram_gb' => 16, 'screen_size' => 14.0, 'warranty_months' => 6, 'colour' => 'black']],
                ['HP Pavilion 15 Ryzen 5', 1_390_000, 'Kariakoo', ListingCondition::New, ['brand' => 'HP', 'storage_gb' => 512, 'ram_gb' => 8, 'screen_size' => 15.6, 'warranty_months' => 12, 'colour' => 'grey']],
            ]],

            ['electronics-desktop-pcs', 'desktop', ListingPurpose::Sale, PriceUnit::Total, [
                ['Dell OptiPlex 7080 SFF', 890_000, 'Upanga', ListingCondition::Refurbished, ['brand' => 'Dell', 'storage_gb' => 512, 'ram_gb' => 16, 'warranty_months' => 6, 'colour' => 'black']],
                ['Custom Ryzen 7 Workstation', 2_450_000, 'Mikocheni', ListingCondition::New, ['brand' => 'Custom', 'storage_gb' => 1024, 'ram_gb' => 32, 'warranty_months' => 12, 'colour' => 'black']],
            ]],

            ['electronics-tablets', 'tablet', ListingPurpose::Sale, PriceUnit::Total, [
                ['iPad 10th Gen 64GB Wi-Fi', 1_090_000, 'Masaki', ListingCondition::New, ['brand' => 'Apple', 'storage_gb' => 64, 'ram_gb' => 4, 'screen_size' => 10.9, 'warranty_months' => 12, 'colour' => 'blue']],
                ['Samsung Galaxy Tab A9+', 620_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Samsung', 'storage_gb' => 128, 'ram_gb' => 8, 'screen_size' => 11.0, 'warranty_months' => 12, 'colour' => 'grey']],
            ]],

            ['electronics-gaming', 'gaming', ListingPurpose::Sale, PriceUnit::Total, [
                ['PlayStation 5 Slim + 2 pads', 1_750_000, 'Mikocheni', ListingCondition::Used, ['brand' => 'Sony', 'storage_gb' => 1024, 'warranty_months' => 3, 'colour' => 'white']],
                ['Xbox Series S 512GB', 890_000, 'Upanga', ListingCondition::New, ['brand' => 'Microsoft', 'storage_gb' => 512, 'warranty_months' => 12, 'colour' => 'white']],
            ]],

            ['electronics-tvs', 'tv', ListingPurpose::Sale, PriceUnit::Total, [
                ['Samsung 55" Crystal UHD 4K', 1_280_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Samsung', 'screen_size' => 55.0, 'warranty_months' => 24, 'colour' => 'black']],
                ['Hisense 43" Smart TV', 590_000, 'Temeke', ListingCondition::New, ['brand' => 'Hisense', 'screen_size' => 43.0, 'warranty_months' => 12, 'colour' => 'black']],
                ['LG 65" OLED C3', 4_600_000, 'Masaki', ListingCondition::New, ['brand' => 'LG', 'screen_size' => 65.0, 'warranty_months' => 24, 'colour' => 'black']],
            ]],

            ['electronics-cameras', 'camera', ListingPurpose::Sale, PriceUnit::Total, [
                ['Canon EOS R6 body', 4_900_000, 'Masaki', ListingCondition::Used, ['brand' => 'Canon', 'warranty_months' => 6, 'colour' => 'black']],
                ['Sony ZV-1 vlogging camera', 1_690_000, 'Mikocheni', ListingCondition::New, ['brand' => 'Sony', 'warranty_months' => 12, 'colour' => 'black']],
            ]],

            ['electronics-accessories', 'accessory', ListingPurpose::Sale, PriceUnit::Total, [
                ['Anker 20,000mAh Power Bank', 78_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Anker', 'warranty_months' => 12, 'colour' => 'black']],
                ['JBL Tune 770NC Headphones', 285_000, 'Masaki', ListingCondition::New, ['brand' => 'JBL', 'warranty_months' => 12, 'colour' => 'blue']],
            ]],

            // --------------------------------------------------- furniture
            ['furniture-beds', 'bed', ListingPurpose::Sale, PriceUnit::Total, [
                ['Mahogany King Bed with Headboard', 1_450_000, 'Mikocheni', ListingCondition::New, ['material' => 'Solid mahogany', 'dimensions' => '200 × 180 cm', 'colour' => 'brown']],
                ['Upholstered Queen Bed', 980_000, 'Msasani', ListingCondition::New, ['material' => 'Pine frame, linen upholstery', 'dimensions' => '200 × 150 cm', 'colour' => 'grey']],
            ]],

            ['furniture-sofas', 'sofa', ListingPurpose::Sale, PriceUnit::Total, [
                ['7-Seater L-Shape Sofa', 2_100_000, 'Mikocheni', ListingCondition::New, ['material' => 'Hardwood frame, fabric', 'dimensions' => '320 × 220 cm', 'colour' => 'grey']],
                ['3+2 Leather Sofa Set', 3_400_000, 'Masaki', ListingCondition::New, ['material' => 'Genuine leather', 'dimensions' => '210 cm + 160 cm', 'colour' => 'brown']],
            ]],

            ['furniture-dining', 'dining', ListingPurpose::Sale, PriceUnit::Total, [
                ['6-Seater Teak Dining Set', 1_850_000, 'Mikocheni', ListingCondition::New, ['material' => 'Teak', 'dimensions' => '180 × 90 cm', 'colour' => 'brown']],
                ['4-Seater Glass Dining Table', 720_000, 'Kariakoo', ListingCondition::New, ['material' => 'Tempered glass, steel', 'dimensions' => '120 × 80 cm', 'colour' => 'black']],
            ]],

            ['furniture-office-furniture', 'desk', ListingPurpose::Sale, PriceUnit::Total, [
                ['Executive Desk 1.8 m', 1_250_000, 'Upanga', ListingCondition::New, ['material' => 'Engineered oak', 'dimensions' => '180 × 80 cm', 'colour' => 'brown']],
                ['Ergonomic Mesh Office Chair', 340_000, 'Upanga', ListingCondition::New, ['material' => 'Mesh, nylon base', 'dimensions' => '65 × 65 cm', 'colour' => 'black']],
            ]],

            ['furniture-wardrobes', 'wardrobe', ListingPurpose::Sale, PriceUnit::Total, [
                ['4-Door Sliding Wardrobe', 1_680_000, 'Mikocheni', ListingCondition::New, ['material' => 'MDF with mirror doors', 'dimensions' => '240 × 210 cm', 'colour' => 'white']],
                ['2-Door Wardrobe with Drawers', 690_000, 'Temeke', ListingCondition::New, ['material' => 'MDF', 'dimensions' => '120 × 200 cm', 'colour' => 'brown']],
            ]],

            ['furniture-kitchen', 'kitchen-unit', ListingPurpose::Sale, PriceUnit::Total, [
                ['Fitted Kitchen Units — 3.6 m run', 4_200_000, 'Masaki', ListingCondition::New, ['material' => 'Marine ply, quartz top', 'dimensions' => '360 cm run', 'colour' => 'white']],
                ['Kitchen Island with Storage', 1_350_000, 'Mbezi Beach', ListingCondition::New, ['material' => 'Oak veneer', 'dimensions' => '150 × 90 cm', 'colour' => 'brown']],
            ]],

            // ----------------------------------------------------- fashion
            ['fashion-men', 'menswear', ListingPurpose::Sale, PriceUnit::Total, [
                ['Tailored Two-Piece Suit', 480_000, 'Masaki', ListingCondition::New, ['size' => 'l', 'gender' => 'men', 'material' => 'Wool blend', 'colour' => 'blue']],
                ['Linen Kanzu — hand finished', 165_000, 'Kariakoo', ListingCondition::New, ['size' => 'xl', 'gender' => 'men', 'material' => 'Linen', 'colour' => 'white']],
            ]],

            ['fashion-women', 'womenswear', ListingPurpose::Sale, PriceUnit::Total, [
                ['Kitenge Wrap Dress', 145_000, 'Masaki', ListingCondition::New, ['size' => 'm', 'gender' => 'women', 'material' => 'Cotton kitenge', 'colour' => 'multi']],
                ['Silk Evening Gown', 620_000, 'Oyster Bay', ListingCondition::New, ['size' => 's', 'gender' => 'women', 'material' => 'Silk', 'colour' => 'red']],
            ]],

            ['fashion-kids', 'kidswear', ListingPurpose::Sale, PriceUnit::Total, [
                ['School Uniform Set — ages 6–8', 65_000, 'Temeke', ListingCondition::New, ['size' => 's', 'gender' => 'kids', 'material' => 'Poly-cotton', 'colour' => 'blue']],
                ['Kids Kitenge Outfit', 48_000, 'Kariakoo', ListingCondition::New, ['size' => 'xs', 'gender' => 'kids', 'material' => 'Cotton', 'colour' => 'multi']],
            ]],

            ['fashion-shoes', 'shoes', ListingPurpose::Sale, PriceUnit::Total, [
                ['Leather Oxford Shoes', 210_000, 'Masaki', ListingCondition::New, ['size' => 'l', 'gender' => 'men', 'material' => 'Full-grain leather', 'colour' => 'brown']],
                ['Nike Air Zoom Pegasus 40', 320_000, 'Mikocheni', ListingCondition::New, ['size' => 'm', 'gender' => 'unisex', 'material' => 'Mesh', 'colour' => 'black']],
            ]],

            ['fashion-bags', 'bags', ListingPurpose::Sale, PriceUnit::Total, [
                ['Handwoven Sisal Basket Bag', 85_000, 'Masaki', ListingCondition::New, ['gender' => 'women', 'material' => 'Sisal', 'colour' => 'brown']],
                ['Leather Laptop Satchel 15"', 265_000, 'Upanga', ListingCondition::New, ['gender' => 'unisex', 'material' => 'Leather', 'colour' => 'black']],
            ]],

            ['fashion-jewelry', 'jewellery', ListingPurpose::Sale, PriceUnit::Total, [
                ['Tanzanite Pendant — 1.2 ct', 1_850_000, 'Masaki', ListingCondition::New, ['gender' => 'women', 'material' => 'Tanzanite, 18k gold', 'colour' => 'blue']],
                ['Maasai Beaded Collar', 72_000, 'Oyster Bay', ListingCondition::New, ['gender' => 'women', 'material' => 'Glass beads', 'colour' => 'multi']],
            ]],

            ['fashion-watches', 'watches', ListingPurpose::Sale, PriceUnit::Total, [
                ['Seiko 5 Automatic', 480_000, 'Masaki', ListingCondition::New, ['gender' => 'men', 'material' => 'Stainless steel', 'colour' => 'grey']],
                ['Casio G-Shock GA-2100', 295_000, 'Kariakoo', ListingCondition::New, ['gender' => 'unisex', 'material' => 'Resin', 'colour' => 'black']],
            ]],

            // -------------------------------------------------------- jobs
            ['jobs-full-time', 'office-job', ListingPurpose::Hire, PriceUnit::Monthly, [
                ['Financial Accountant — Manufacturing', 3_200_000, 'Upanga', null, ['employment_type' => 'full-time', 'experience_years' => 5, 'salary_period' => 'monthly', 'is_remote' => false]],
                ['Logistics Supervisor — Port Operations', 2_400_000, 'Ilala', null, ['employment_type' => 'full-time', 'experience_years' => 4, 'salary_period' => 'monthly', 'is_remote' => false]],
                ['Hotel Front Office Manager', 1_800_000, 'Masaki', null, ['employment_type' => 'full-time', 'experience_years' => 3, 'salary_period' => 'monthly', 'is_remote' => false]],
            ]],

            ['jobs-part-time', 'field-job', ListingPurpose::Hire, PriceUnit::Daily, [
                ['Weekend Retail Assistant', 35_000, 'Kariakoo', null, ['employment_type' => 'part-time', 'experience_years' => 1, 'salary_period' => 'daily', 'is_remote' => false]],
                ['Evening Security Guard', 28_000, 'Temeke', null, ['employment_type' => 'part-time', 'experience_years' => 2, 'salary_period' => 'daily', 'is_remote' => false]],
            ]],

            ['jobs-remote', 'office-job', ListingPurpose::Hire, PriceUnit::Monthly, [
                ['Remote Customer Support Agent — English/Swahili', 1_200_000, 'Upanga', null, ['employment_type' => 'full-time', 'experience_years' => 2, 'salary_period' => 'monthly', 'is_remote' => true]],
                ['Remote Laravel Developer', 4_500_000, 'Masaki', null, ['employment_type' => 'contract', 'experience_years' => 4, 'salary_period' => 'monthly', 'is_remote' => true]],
            ]],

            ['jobs-internships', 'office-job', ListingPurpose::Hire, PriceUnit::Monthly, [
                ['Marketing Intern — 6 months', 400_000, 'Upanga', null, ['employment_type' => 'internship', 'experience_years' => 0, 'salary_period' => 'monthly', 'is_remote' => false]],
                ['Civil Engineering Intern', 450_000, 'Ubungo', null, ['employment_type' => 'internship', 'experience_years' => 0, 'salary_period' => 'monthly', 'is_remote' => false]],
            ]],

            ['jobs-freelance', 'office-job', ListingPurpose::Hire, PriceUnit::Hourly, [
                ['Freelance Graphic Designer', 45_000, 'Mikocheni', null, ['employment_type' => 'freelance', 'experience_years' => 3, 'salary_period' => 'hourly', 'is_remote' => true]],
                ['Freelance Swahili Translator', 30_000, 'Ilala', null, ['employment_type' => 'freelance', 'experience_years' => 2, 'salary_period' => 'hourly', 'is_remote' => true]],
            ]],

            // ---------------------------------------------------- services
            ['services-cleaning', 'cleaning', ListingPurpose::Hire, PriceUnit::Daily, [
                ['Deep Home Cleaning — 3 bedroom', 120_000, 'Masaki', null, ['service_area' => 'Msasani peninsula, Mikocheni, Oyster Bay', 'availability' => 'weekdays', 'experience_years' => 6]],
                ['Post-Construction Cleaning', 350_000, 'Ubungo', null, ['service_area' => 'Greater Dar es Salaam', 'availability' => 'anytime', 'experience_years' => 8]],
            ]],

            ['services-moving', 'moving', ListingPurpose::Hire, PriceUnit::Daily, [
                ['House Removal — 3 tonne truck and crew', 380_000, 'Temeke', null, ['service_area' => 'Dar es Salaam and Coast region', 'availability' => 'anytime', 'experience_years' => 10]],
                ['Office Relocation — weekend service', 950_000, 'Ilala', null, ['service_area' => 'Dar es Salaam CBD', 'availability' => 'weekends', 'experience_years' => 7]],
            ]],

            ['services-repair', 'repair', ListingPurpose::Hire, PriceUnit::Hourly, [
                ['Appliance Repair — fridges and washers', 45_000, 'Mikocheni', null, ['service_area' => 'Kinondoni', 'availability' => 'weekdays', 'experience_years' => 12]],
                ['Air Conditioning Service & Regas', 65_000, 'Upanga', null, ['service_area' => 'Ilala and Kinondoni', 'availability' => 'by-appointment', 'experience_years' => 9]],
            ]],

            ['services-construction', 'builder', ListingPurpose::Hire, PriceUnit::Total, [
                ['Boundary Wall Construction — per running metre', 185_000, 'Kimara', null, ['service_area' => 'Greater Dar es Salaam', 'availability' => 'weekdays', 'experience_years' => 15]],
                ['Two-Storey House Build — turnkey', 240_000_000, 'Mbezi Beach', null, ['service_area' => 'Dar es Salaam and Bagamoyo', 'availability' => 'by-appointment', 'experience_years' => 18]],
            ]],

            ['services-plumbing', 'plumbing', ListingPurpose::Hire, PriceUnit::Hourly, [
                ['Emergency Plumbing Call-Out', 55_000, 'Mikocheni', null, ['service_area' => 'Kinondoni and Ilala', 'availability' => 'anytime', 'experience_years' => 11]],
                ['Borehole Pump Installation', 1_400_000, 'Kimara', null, ['service_area' => 'Ubungo, Kimara, Kibaha', 'availability' => 'weekdays', 'experience_years' => 14]],
            ]],

            ['services-electrical', 'electrical', ListingPurpose::Hire, PriceUnit::Hourly, [
                ['Certified Electrician — domestic rewiring', 60_000, 'Temeke', null, ['service_area' => 'Temeke and Kigamboni', 'availability' => 'weekdays', 'experience_years' => 13]],
                ['Solar Installation — 5 kW system', 8_900_000, 'Mbezi Beach', null, ['service_area' => 'Dar es Salaam', 'availability' => 'by-appointment', 'experience_years' => 8]],
            ]],

            ['services-painting', 'painting', ListingPurpose::Hire, PriceUnit::Total, [
                ['Interior Painting — 3 bedroom house', 850_000, 'Mikocheni', null, ['service_area' => 'Kinondoni', 'availability' => 'weekdays', 'experience_years' => 9]],
                ['Exterior Weatherproof Coating', 1_600_000, 'Kigamboni', null, ['service_area' => 'Kigamboni and Temeke', 'availability' => 'anytime', 'experience_years' => 6]],
            ]],

            ['services-photography', 'photography', ListingPurpose::Hire, PriceUnit::Daily, [
                ['Wedding Photography — full day', 1_800_000, 'Masaki', null, ['service_area' => 'Dar es Salaam, Zanzibar, Bagamoyo', 'availability' => 'weekends', 'experience_years' => 10]],
                ['Property Photography for Listings', 250_000, 'Msasani', null, ['service_area' => 'Greater Dar es Salaam', 'availability' => 'by-appointment', 'experience_years' => 5]],
            ]],

            // ------------------------------------------------- agriculture
            ['agriculture-livestock', 'livestock', ListingPurpose::Sale, PriceUnit::Total, [
                ['Boran Heifers — 12 head', 18_000_000, 'Kimara', ListingCondition::New, ['quantity' => 12, 'unit_of_measure' => 'piece']],
                ['Dairy Friesian Cow — in milk', 2_400_000, 'Mbagala', ListingCondition::New, ['quantity' => 1, 'unit_of_measure' => 'piece']],
                ['Broiler Chicks — day old, 500 batch', 950_000, 'Kimara', ListingCondition::New, ['quantity' => 500, 'unit_of_measure' => 'piece']],
            ]],

            ['agriculture-seeds', 'seeds', ListingPurpose::Sale, PriceUnit::Total, [
                ['Certified Maize Seed DK8031 — 25 kg', 185_000, 'Kimara', ListingCondition::New, ['quantity' => 25, 'unit_of_measure' => 'kg']],
                ['Sunflower Seed Record — 10 kg', 62_000, 'Mbagala', ListingCondition::New, ['quantity' => 10, 'unit_of_measure' => 'kg']],
            ]],

            ['agriculture-machinery', 'farm-machine', ListingPurpose::Sale, PriceUnit::Total, [
                ['Massey Ferguson 375 Tractor', 46_000_000, 'Kimara', ListingCondition::Used, ['quantity' => 1, 'unit_of_measure' => 'piece']],
                ['Diesel Maize Mill — 2 tonne/hour', 6_800_000, 'Mbagala', ListingCondition::New, ['quantity' => 1, 'unit_of_measure' => 'piece']],
            ]],

            ['agriculture-fertilizers', 'fertiliser', ListingPurpose::Sale, PriceUnit::Total, [
                ['Urea 46% — 50 kg bag', 78_000, 'Kimara', ListingCondition::New, ['quantity' => 50, 'unit_of_measure' => 'bag']],
                ['DAP Fertiliser — tonne lot', 1_450_000, 'Mbagala', ListingCondition::New, ['quantity' => 1, 'unit_of_measure' => 'tonne']],
            ]],

            ['agriculture-animal-feed', 'animal-feed', ListingPurpose::Sale, PriceUnit::Total, [
                ['Layers Mash — 50 kg', 68_000, 'Kimara', ListingCondition::New, ['quantity' => 50, 'unit_of_measure' => 'kg']],
                ['Dairy Meal — tonne lot', 1_100_000, 'Kimara', ListingCondition::New, ['quantity' => 1, 'unit_of_measure' => 'tonne']],
            ]],

            // -------------------------------------------------------- pets
            ['pets-dogs', 'dog', ListingPurpose::Sale, PriceUnit::Total, [
                ['German Shepherd Puppy — 10 weeks', 850_000, 'Mikocheni', ListingCondition::New, ['breed' => 'German Shepherd', 'age_months' => 3, 'vaccinated' => true]],
                ['Boerboel Puppy — papers included', 1_400_000, 'Mbezi Beach', ListingCondition::New, ['breed' => 'Boerboel', 'age_months' => 4, 'vaccinated' => true]],
            ]],

            ['pets-cats', 'cat', ListingPurpose::Sale, PriceUnit::Total, [
                ['Persian Kitten — 12 weeks', 420_000, 'Mikocheni', ListingCondition::New, ['breed' => 'Persian', 'age_months' => 3, 'vaccinated' => true]],
                ['Domestic Shorthair — rehoming', 60_000, 'Msasani', ListingCondition::New, ['breed' => 'Domestic Shorthair', 'age_months' => 14, 'vaccinated' => true]],
            ]],

            ['pets-birds', 'bird', ListingPurpose::Sale, PriceUnit::Total, [
                ['African Grey Parrot — hand reared', 1_650_000, 'Mikocheni', ListingCondition::New, ['breed' => 'African Grey', 'age_months' => 8, 'vaccinated' => false]],
                ['Budgerigar Pair', 85_000, 'Kariakoo', ListingCondition::New, ['breed' => 'Budgerigar', 'age_months' => 6, 'vaccinated' => false]],
            ]],

            ['pets-fish', 'fish', ListingPurpose::Sale, PriceUnit::Total, [
                ['Tropical Aquarium Setup — 120 litre', 480_000, 'Mikocheni', ListingCondition::New, ['breed' => 'Community tropicals', 'age_months' => 4, 'vaccinated' => false]],
                ['Koi Carp — 30 cm', 220_000, 'Mbezi Beach', ListingCondition::New, ['breed' => 'Koi', 'age_months' => 18, 'vaccinated' => false]],
            ]],

            ['pets-pet-food', 'pet-food', ListingPurpose::Sale, PriceUnit::Total, [
                ['Royal Canin Adult Dog Food — 15 kg', 195_000, 'Mikocheni', ListingCondition::New, ['breed' => 'All breeds', 'age_months' => 12, 'vaccinated' => false]],
                ['Whiskas Adult Cat Food — 7 kg', 92_000, 'Mikocheni', ListingCondition::New, ['breed' => 'All breeds', 'age_months' => 12, 'vaccinated' => false]],
            ]],

            // ------------------------------------------------ construction
            ['construction-building-materials', 'materials', ListingPurpose::Sale, PriceUnit::Total, [
                ['Twiga Cement 32.5N — 50 kg bag', 18_500, 'Mbagala', ListingCondition::New, ['brand' => 'Twiga', 'material_grade' => '32.5N Portland', 'quantity' => 50, 'unit_of_measure' => 'bag']],
                ['River Sand — 7 tonne tipper load', 320_000, 'Kigamboni', ListingCondition::New, ['brand' => 'Local', 'material_grade' => 'Washed river sand', 'quantity' => 7, 'unit_of_measure' => 'tonne']],
            ]],

            ['construction-tools-equipment', 'tools', ListingPurpose::Sale, PriceUnit::Total, [
                ['Bosch GBH 2-26 Rotary Hammer', 780_000, 'Mbagala', ListingCondition::New, ['brand' => 'Bosch', 'material_grade' => 'Professional', 'quantity' => 1, 'unit_of_measure' => 'piece', 'warranty_months' => 24]],
                ['Scaffolding Frame Set — 20 bays', 4_600_000, 'Ubungo', ListingCondition::Used, ['brand' => 'Generic', 'material_grade' => 'Galvanised steel', 'quantity' => 20, 'unit_of_measure' => 'piece']],
            ]],

            ['construction-heavy-machinery', 'heavy-plant', ListingPurpose::Sale, PriceUnit::Total, [
                ['CAT 320D Excavator', 210_000_000, 'Ubungo', ListingCondition::Used, ['brand' => 'Caterpillar', 'material_grade' => '20-tonne class', 'quantity' => 1, 'unit_of_measure' => 'piece']],
                ['Concrete Mixer 400 litre', 3_900_000, 'Mbagala', ListingCondition::New, ['brand' => 'Altrad', 'material_grade' => '400 L drum', 'quantity' => 1, 'unit_of_measure' => 'piece', 'warranty_months' => 12]],
            ]],

            ['construction-steel-cement', 'materials', ListingPurpose::Sale, PriceUnit::Total, [
                ['Y12 Deformed Reinforcement Bar — tonne', 2_150_000, 'Mbagala', ListingCondition::New, ['brand' => 'Kiluwa Steel', 'material_grade' => 'Y12 / BS4449 500B', 'quantity' => 1, 'unit_of_measure' => 'tonne']],
                ['Nyati Cement 42.5R — pallet of 40', 720_000, 'Mbagala', ListingCondition::New, ['brand' => 'Nyati', 'material_grade' => '42.5R', 'quantity' => 40, 'unit_of_measure' => 'bag']],
            ]],

            ['construction-roofing', 'materials', ListingPurpose::Sale, PriceUnit::Total, [
                ['ALAF Versatile Roofing Sheet — 3 m', 62_000, 'Mbagala', ListingCondition::New, ['brand' => 'ALAF', 'material_grade' => '0.4 mm gauge', 'quantity' => 1, 'unit_of_measure' => 'piece', 'warranty_months' => 120]],
                ['Stone-Coated Roof Tile — per m²', 48_000, 'Mbezi Beach', ListingCondition::New, ['brand' => 'Decra', 'material_grade' => 'Stone coated steel', 'quantity' => 1, 'unit_of_measure' => 'piece', 'warranty_months' => 360]],
            ]],

            ['construction-tiles-sanitary', 'materials', ListingPurpose::Sale, PriceUnit::Total, [
                ['Porcelain Floor Tile 60×60 — per m²', 38_000, 'Mbagala', ListingCondition::New, ['brand' => 'Goodwill', 'material_grade' => 'PEI IV porcelain', 'quantity' => 1, 'unit_of_measure' => 'piece']],
                ['Close-Coupled WC Suite', 285_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Twyford', 'material_grade' => 'Vitreous china', 'quantity' => 1, 'unit_of_measure' => 'piece', 'warranty_months' => 24]],
            ]],

            // -------------------------------------------------- industrial
            ['industrial-generators', 'generator', ListingPurpose::Sale, PriceUnit::Total, [
                ['Perkins 100 kVA Diesel Generator', 62_000_000, 'Temeke', ListingCondition::New, ['brand' => 'Perkins', 'power_kva' => 100, 'year' => 2025, 'warranty_months' => 24, 'quantity' => 1]],
                ['Cummins 250 kVA Silent Genset', 148_000_000, 'Temeke', ListingCondition::Used, ['brand' => 'Cummins', 'power_kva' => 250, 'year' => 2020, 'warranty_months' => 6, 'quantity' => 1]],
                ['Honda 5 kVA Petrol Generator', 2_650_000, 'Kariakoo', ListingCondition::New, ['brand' => 'Honda', 'power_kva' => 5, 'year' => 2025, 'warranty_months' => 12, 'quantity' => 1]],
            ]],

            ['industrial-pumps-compressors', 'pump', ListingPurpose::Sale, PriceUnit::Total, [
                ['Grundfos Submersible Borehole Pump 4"', 4_200_000, 'Temeke', ListingCondition::New, ['brand' => 'Grundfos', 'power_kva' => 4, 'year' => 2025, 'warranty_months' => 24, 'quantity' => 1]],
                ['Atlas Copco Screw Compressor 22 kW', 28_000_000, 'Ubungo', ListingCondition::Used, ['brand' => 'Atlas Copco', 'power_kva' => 22, 'year' => 2019, 'warranty_months' => 6, 'quantity' => 1]],
            ]],

            ['industrial-industrial-machinery', 'industrial-machine', ListingPurpose::Sale, PriceUnit::Total, [
                ['Maize Milling Plant — 5 tonne/hour', 96_000_000, 'Ubungo', ListingCondition::New, ['brand' => 'Buhler', 'power_kva' => 75, 'year' => 2024, 'warranty_months' => 12, 'quantity' => 1]],
                ['Cashew Shelling Line', 54_000_000, 'Temeke', ListingCondition::Used, ['brand' => 'Oltremare', 'power_kva' => 45, 'year' => 2018, 'warranty_months' => 3, 'quantity' => 1]],
            ]],

            ['industrial-packaging-equipment', 'industrial-machine', ListingPurpose::Sale, PriceUnit::Total, [
                ['Automatic Bottle Filling Line — 2000 bph', 78_000_000, 'Ubungo', ListingCondition::New, ['brand' => 'Krones', 'power_kva' => 30, 'year' => 2024, 'warranty_months' => 12, 'quantity' => 1]],
                ['Vertical Form Fill Seal Machine', 21_500_000, 'Temeke', ListingCondition::Used, ['brand' => 'Bosch', 'power_kva' => 8, 'year' => 2020, 'warranty_months' => 6, 'quantity' => 1]],
            ]],

            ['industrial-safety-equipment', 'safety-gear', ListingPurpose::Sale, PriceUnit::Total, [
                ['Full Body Safety Harness — EN 361', 165_000, 'Temeke', ListingCondition::New, ['brand' => '3M', 'power_kva' => 0, 'year' => 2025, 'warranty_months' => 12, 'quantity' => 1]],
                ['Fire Extinguisher 9 kg DCP — 10 units', 890_000, 'Ubungo', ListingCondition::New, ['brand' => 'Naffco', 'power_kva' => 0, 'year' => 2025, 'warranty_months' => 12, 'quantity' => 10]],
            ]],

            ['industrial-industrial-spares', 'industrial-machine', ListingPurpose::Sale, PriceUnit::Total, [
                ['SKF Bearing Set — assorted industrial', 1_250_000, 'Temeke', ListingCondition::New, ['brand' => 'SKF', 'power_kva' => 0, 'year' => 2025, 'warranty_months' => 6, 'quantity' => 24]],
                ['Perkins 1104 Service Kit', 780_000, 'Temeke', ListingCondition::New, ['brand' => 'Perkins', 'power_kva' => 0, 'year' => 2025, 'warranty_months' => 6, 'quantity' => 1]],
            ]],
        ];

        $rows = [];

        foreach ($specs as [$category, $photos, $purpose, $unit, $variants]) {
            foreach ($variants as $order => [$title, $price, $place, $condition, $attributes]) {
                $rows[] = [
                    // The title alone. Appending the place produced slugs like
                    // `one-acre-plot-mbezi-beach-mbezi-beach`, because most
                    // titles already name the place — and the slug is the URL a
                    // customer sees and shares.
                    'slug' => Str::slug($title),
                    'title' => $title,
                    'category' => $category,
                    'photos' => $photos,
                    'place' => $place,
                    'purpose' => $purpose,
                    'unit' => $unit,
                    'price' => $price,
                    'condition' => $condition,
                    'attributes' => $attributes,
                    'description' => $this->describe($title, $place, $purpose, $attributes),
                ];

                unset($order);
            }
        }

        return $rows;
    }

    /**
     * A description written from the listing's own facts.
     *
     * Not lorem ipsum and not one paragraph copied across the catalogue: each
     * is assembled from the attributes that listing actually carries, so it
     * says something true and different for every row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function describe(string $title, string $place, ListingPurpose $purpose, array $attributes): string
    {
        $facts = [];

        foreach ($attributes as $code => $value) {
            $attribute = $this->attributes[$code] ?? null;

            if ($attribute === null || $value === null || $value === '') {
                continue;
            }

            $printed = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            $unit = $attribute->unit !== null ? ' '.$attribute->unit : '';

            $facts[] = strtolower($attribute->name).': '.$printed.$unit;
        }

        $opening = match ($purpose) {
            ListingPurpose::Hire => $title.', available in '.$place.', Dar es Salaam.',
            ListingPurpose::Rent, ListingPurpose::Lease => $title.' available to let in '.$place.', Dar es Salaam.',
            default => $title.' for sale in '.$place.', Dar es Salaam.',
        };

        $detail = $facts === [] ? '' : ' Key details — '.implode('; ', array_slice($facts, 0, 6)).'.';

        $closing = $purpose === ListingPurpose::Hire
            ? ' Message through SAKA to discuss dates and scope; references available on request.'
            : ' Inspection welcome before purchase. Message or call through SAKA to arrange a viewing.';

        return $opening.$detail.$closing;
    }

    /**
     * Write EAV values, routing each into the column its data type requires.
     *
     * A select attribute must ALSO carry its attribute_option_id — the option
     * is what the filter query joins on, so a value written as a bare string
     * displays correctly and is invisible to every filter.
     *
     * @param  array<string, mixed>  $values
     */
    private function setAttributes(Listing $listing, array $values): void
    {
        $listing->attributeValues()->delete();

        foreach ($values as $code => $value) {
            $attribute = $this->attributes[$code] ?? null;

            if ($attribute === null || $value === null) {
                continue;
            }

            $row = [
                'listing_id' => $listing->id,
                'attribute_id' => $attribute->id,
                'attribute_option_id' => null,
                'value_string' => null,
                'value_integer' => null,
                'value_decimal' => null,
                'value_boolean' => null,
                'value_date' => null,
            ];

            $isSelect = in_array($attribute->input_type, ['select', 'multiselect'], true);

            if ($isSelect) {
                $slug = Str::slug((string) $value);
                $optionId = $this->options[$code][$slug] ?? null;

                if ($optionId === null) {
                    // A value with no matching option would be unfilterable and
                    // would render as a raw slug. Better to omit it than to
                    // show a broken row on the detail page.
                    continue;
                }

                $row['attribute_option_id'] = $optionId;
                $row['value_string'] = $slug;
            } else {
                match ($attribute->data_type) {
                    'integer' => $row['value_integer'] = (int) $value,
                    'decimal' => $row['value_decimal'] = (float) $value,
                    'boolean' => $row['value_boolean'] = (bool) $value,
                    'date' => $row['value_date'] = $value,
                    default => $row['value_string'] = (string) $value,
                };
            }

            (new ListingAttributeValue)->forceFill($row)->save();
        }
    }

    // ---------------------------------------------------- land boundaries

    /**
     * Draw a parcel outline on every land listing.
     *
     * The shape is derived from the listing's own `sqft` attribute, so a 10,000
     * sqft plot gets a polygon that measures roughly 10,000 sqft when the
     * service re-measures it — which is the point: it exercises the real
     * measurement path rather than storing a number next to an unrelated shape.
     *
     * Corners are jittered deterministically from the listing id so no two
     * parcels are the same rectangle, and none of them is a perfect rectangle,
     * because real plots are not.
     */
    private function seedLandBoundaries(): void
    {
        $service = app(LandBoundaryService::class);

        $allowed = (array) config('saka.listings.boundary_categories', []);

        $listings = Listing::query()
            ->whereHas('category', function ($query) use ($allowed): void {
                $query->whereIn('slug', $allowed)
                    ->orWhereHas('parent', fn ($parent) => $parent->whereIn('slug', $allowed));
            })
            ->whereNotNull('latitude')
            // Lazy loading is disabled application-wide, so both relations this
            // loop reads have to be declared up front.
            ->with(['attributeValues.attribute', 'boundary'])
            ->get();

        $drawn = 0;
        $tooSmall = [];

        /*
         * Clear parcels that no longer qualify.
         *
         * The eligible set is config, and config changes — `agriculture` was
         * once in it and drew outlines around bags of urea. A boundary left
         * behind by an earlier rule is invisible in the seeder output and very
         * visible on the listing page.
         */
        $orphaned = DB::table('listing_boundaries')
            ->whereNotIn('listing_id', $listings->modelKeys())
            ->delete();

        if ($orphaned > 0) {
            $this->command->warn('  removed '.$orphaned.' boundaries on listings that no longer qualify.');
        }

        foreach ($listings as $listing) {
            if ($listing->boundary !== null) {
                continue;
            }

            $sqft = 0;

            foreach ($listing->attributeValues as $value) {
                if ($value->attribute?->code === 'sqft') {
                    $sqft = (int) $value->value();
                }
            }

            // Plots without a stated area still get a parcel — a quarter-acre
            // default, which is the commonest plot size sold here.
            $targetSqm = $sqft > 0 ? $sqft * 0.092903 : 1011.7;

            /*
             * Below ~200 m² this is not a plot.
             *
             * DemoSeeder's four "Kariakoo Commercial Floor" rows are filed
             * under Plots with a floor area of 500–1,200 sqft, which produced
             * 25 m² parcels — a shape the size of a bedroom, shaded on a map
             * and labelled as land for sale. Skipping them is right: the data
             * is what is wrong, and inventing a plausible outline for it would
             * hide that rather than fix it.
             */
            if ($targetSqm < 200) {
                $tooSmall[] = $listing->slug;

                continue;
            }

            $rings = [$this->parcelRing(
                (float) $listing->latitude,
                (float) $listing->longitude,
                $targetSqm,
                $listing->id,
            )];

            $service->save(
                $listing,
                $rings,
                'DSM/'.str_pad((string) $listing->id, 5, '0', STR_PAD_LEFT).'/'.now()->year,
                'Boundary traced from the approved survey plan. Corners marked with concrete beacons.',
            );

            $drawn++;
        }

        if ($tooSmall !== []) {
            $this->command->warn('  '.count($tooSmall).' plot listings too small to be land — no parcel drawn: '.implode(', ', $tooSmall));
        }

        $this->command->info('  '.$drawn.' land parcels with a surveyed boundary.');
    }

    /**
     * An irregular closed polygon of approximately the requested area.
     *
     * Built as a jittered circle: `n` vertices at varying radii around the
     * centre. A jittered RECTANGLE was the first attempt and looked wrong —
     * every plot came out as the same near-square, which reads as generated.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function parcelRing(float $lat, float $lng, float $targetSqm, int $seed): array
    {
        $vertices = 5 + ($seed % 3);

        // Radius of the circle with the target area, then corrected for the
        // fact that a regular n-gon inscribed in it is smaller than the circle.
        $inscribed = (float) $vertices / (2 * M_PI) * sin(2 * M_PI / $vertices);
        $radiusM = sqrt($targetSqm / (M_PI * $inscribed));

        $metresPerDegLat = 110_574.0;
        $metresPerDegLng = 111_320.0 * cos(deg2rad($lat));

        $ring = [];

        for ($i = 0; $i < $vertices; $i++) {
            $angle = 2 * M_PI * $i / $vertices;

            // ±12% deterministic jitter, so the outline is irregular but the
            // area stays close to target and re-running the seeder reproduces
            // the identical parcel.
            $wobble = 1 + 0.12 * sin($seed * 1.7 + $i * 2.3);
            $r = $radiusM * $wobble;

            $ring[] = [
                round($lng + ($r * cos($angle)) / $metresPerDegLng, 7),
                round($lat + ($r * sin($angle)) / $metresPerDegLat, 7),
            ];
        }

        return $ring;
    }

    // ---------------------------------------------------------- engagement

    /**
     * Reviews on the new listings.
     *
     * Every seller badge, star rating and "N reviews" count reads from these.
     * Without them eight verticals show a rating system that looks unbuilt.
     */
    private function seedEngagement(): void
    {
        $buyer = User::query()->where('email', 'buyer@saka.test')->first();

        if ($buyer === null) {
            $this->command->warn('  no buyer@saka.test — skipping engagement.');

            return;
        }

        $sellerEmails = [
            'motors@saka.demo', 'techzone@saka.demo', 'boutique@saka.demo', 'recruit@saka.demo',
            'agri@saka.demo', 'pets@saka.demo', 'buildmart@saka.demo', 'industrial@saka.demo',
        ];

        $listings = Listing::query()
            ->whereHas('user', fn ($query) => $query->whereIn('email', $sellerEmails))
            ->where('status', ListingStatus::Published)
            ->orderBy('id')
            ->get();

        $bodies = [
            5 => [
                'Item was exactly as described and the seller answered every question before I paid. Would buy from them again.',
                'Delivered the same day and the invoice was in order. No surprises, which is more than I can say for the last place I tried.',
            ],
            4 => [
                'Good quality for the money. One small mark that was not in the photos, but the seller pointed it out before I travelled.',
                'Straightforward transaction. Took a day longer than promised, otherwise no complaints.',
            ],
            3 => [
                'Does the job. Worth checking it in person first — the listing photos are flattering.',
                'Fair price, but be prepared to arrange your own transport.',
            ],
        ];

        $titles = ['Exactly as described', 'Good value', 'Would buy again', 'Solid, no complaints', 'Fine for the price'];
        $reviews = 0;
        $favourites = 0;

        foreach ($listings as $index => $listing) {
            if ($index % 3 !== 2) {
                $rating = [5, 4, 5, 3, 4][$index % 5];

                Review::query()->updateOrCreate(
                    ['listing_id' => $listing->id, 'reviewer_id' => $buyer->id],
                    [
                        'uuid' => (string) Str::uuid7(),
                        'seller_id' => $listing->user_id,
                        'rating' => $rating,
                        'title' => $titles[$index % count($titles)],
                        'body' => $bodies[$rating][$index % 2],
                        'status' => 'approved',
                        'moderated_at' => now(),
                        'helpful_count' => ($index * 5) % 17,
                    ],
                );

                $reviews++;
            }

            if ($index % 6 === 0) {
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
        }

        $this->command->info("  {$reviews} reviews and {$favourites} favourites on the new listings.");
    }

    /**
     * Rebuild the denormalised counters, for the same reason RichDemoSeeder
     * does: rows written straight through the model skip the application write
     * paths that maintain them, and a stale `active_listings` of zero hides a
     * business from the directory entirely.
     */
    private function recomputeCounters(): void
    {
        DB::statement('
            UPDATE listings l SET favorite_count = (
                SELECT COUNT(*) FROM favorites f
                WHERE f.favoritable_type = ? AND f.favoritable_id = l.id AND f.removed_at IS NULL
            )', [Listing::class]);

        DB::statement('
            UPDATE listings l SET inquiry_count = (
                SELECT COUNT(*) FROM inquiries i WHERE i.listing_id = l.id
            )');

        DB::statement('
            UPDATE seller_profiles p
            SET total_listings = (
                    SELECT COUNT(*) FROM listings l WHERE l.user_id = p.user_id AND l.deleted_at IS NULL
                ),
                active_listings = (
                    SELECT COUNT(*) FROM listings l
                    WHERE l.user_id = p.user_id AND l.status = ? AND l.deleted_at IS NULL
                )', [ListingStatus::Published->value]);

        DB::statement('
            UPDATE seller_profiles p
            SET rating_count = (
                    SELECT COUNT(*) FROM reviews r
                    WHERE r.seller_id = p.user_id AND r.status = ? AND r.deleted_at IS NULL
                ),
                rating_avg = COALESCE((
                    SELECT AVG(r.rating) FROM reviews r
                    WHERE r.seller_id = p.user_id AND r.status = ? AND r.deleted_at IS NULL
                ), 0)', ['approved', 'approved']);

        /*
         * Category counts are NOT recomputed here.
         *
         * `saka:taxonomy:recount` already owns that, and it does it properly:
         * it rolls counts up the tree through the materialised `path` column,
         * counts only publicly visible statuses, and flushes the cached
         * category tree afterwards. An earlier version of this method
         * reimplemented it with a single-level parent rollup and no cache
         * flush, which is a second definition of the same number that can
         * disagree with the scheduled one.
         */
        Artisan::call('saka:taxonomy:recount');

        $this->command->info('  Recomputed listing, seller and category counters.');
    }

    // --------------------------------------------------------------- media

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
                'path' => 'photo-'.$photo.'?auto=format&fit=crop&w=1200&q=80',
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
}
