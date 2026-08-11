<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Support\Cache\CacheKeys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The attributes each vertical was missing.
 *
 * The filter panel is generated entirely from `category_attribute`, which is
 * what makes a vehicle search look nothing like a property search. That only
 * works if the taxonomy actually DEFINES the things buyers filter by — and six
 * were absent, so six filters simply did not exist:
 *
 *     vehicles     → body_type       ("I want an SUV, not a saloon")
 *     electronics  → processor       ("i7, not i3")
 *     fashion      → brand           (bound to every other retail vertical
 *                                     already; fashion was the omission)
 *     jobs         → education_level ("degree required?")
 *     services     → service_type    + response_time
 *
 * Additive and idempotent, like the other demo seeders: attributes are keyed on
 * `code`, options on (attribute, value), and bindings are re-asserted rather
 * than duplicated. Existing listings are backfilled so the new filters return
 * results immediately instead of emptying the catalogue.
 *
 *     php artisan db:seed --class=CategoryAttributeGapSeeder
 */
class CategoryAttributeGapSeeder extends Seeder
{
    /**
     * code => [name, input_type, data_type, unit, filterable, options]
     *
     * @var array<string, array{0:string,1:string,2:string,3:?string,4:bool,5:list<string>}>
     */
    private const ATTRIBUTES = [
        'body_type' => ['Body type', 'select', 'string', null, true, [
            'Hatchback', 'Saloon', 'SUV', 'Station Wagon', 'Pickup', 'Minivan',
            'Coupe', 'Convertible', 'Bus', 'Truck', 'Motorcycle',
        ]],

        'processor' => ['Processor', 'select', 'string', null, true, [
            'Intel Core i3', 'Intel Core i5', 'Intel Core i7', 'Intel Core i9',
            'AMD Ryzen 3', 'AMD Ryzen 5', 'AMD Ryzen 7', 'AMD Ryzen 9',
            'Apple M1', 'Apple M2', 'Apple M3', 'Snapdragon', 'MediaTek', 'Other',
        ]],

        'education_level' => ['Minimum education', 'select', 'string', null, true, [
            'No formal requirement', 'Primary', 'Secondary', 'Certificate',
            'Diploma', 'Bachelor Degree', 'Master Degree', 'Doctorate',
        ]],

        'service_type' => ['Service type', 'select', 'string', null, true, [
            'One-off job', 'Recurring contract', 'Emergency call-out',
            'Consultation', 'Installation', 'Maintenance', 'Repair',
        ]],

        // Hours rather than a free-text promise: a filter needs an orderable
        // number, and "within 2 hours" is a claim a buyer can act on.
        'response_time' => ['Responds within', 'number', 'integer', 'hours', true, []],
    ];

    /** vertical slug => attribute codes to bind, in display order. */
    private const BINDINGS = [
        'vehicles' => ['body_type'],
        'electronics' => ['processor'],
        'fashion' => ['brand'],
        'jobs' => ['education_level'],
        'services' => ['service_type', 'response_time'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('CategoryAttributeGapSeeder refuses to run in production.');

            return;
        }

        $this->seedAttributes();
        $this->bind();
        $this->backfill();

        // The attribute set per category is cached for a day; without this the
        // new filters would not appear until tomorrow.
        CacheKeys::flushTaxonomy();

        $this->command->info('Done.');
    }

    private function seedAttributes(): void
    {
        $position = 500;

        foreach (self::ATTRIBUTES as $code => [$name, $input, $data, $unit, $filterable, $options]) {
            $attribute = Attribute::updateOrCreate(['code' => $code], [
                'name' => $name,
                'input_type' => $input,
                'data_type' => $data,
                'unit' => $unit,
                'is_filterable' => $filterable,
                // Body type and processor are things people type into a search
                // box, not only tick in a filter.
                'is_searchable' => in_array($code, ['body_type', 'processor'], true),
                'position' => $position += 10,
            ]);

            foreach ($options as $index => $label) {
                AttributeOption::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => Str::slug($label)],
                    ['label' => $label, 'position' => $index * 10],
                );
            }
        }

        $this->command->info('  '.count(self::ATTRIBUTES).' attributes created or updated.');
    }

    private function bind(): void
    {
        $bound = 0;

        foreach (self::BINDINGS as $verticalSlug => $codes) {
            $vertical = Category::query()->where('slug', $verticalSlug)->whereNull('parent_id')->first();

            if ($vertical === null) {
                $this->command->warn("  no vertical '{$verticalSlug}' — skipped.");

                continue;
            }

            // Appended after whatever the vertical already defines, so the
            // existing filter order is undisturbed.
            $nextPosition = (int) DB::table('category_attribute')
                ->where('category_id', $vertical->id)->max('position');

            foreach ($codes as $code) {
                $attribute = Attribute::query()->where('code', $code)->first();

                if ($attribute === null) {
                    continue;
                }

                $exists = DB::table('category_attribute')
                    ->where('category_id', $vertical->id)
                    ->where('attribute_id', $attribute->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('category_attribute')->insert([
                    'category_id' => $vertical->id,
                    'attribute_id' => $attribute->id,
                    'is_required' => false,
                    'is_filterable' => true,
                    'position' => $nextPosition += 10,
                ]);

                $bound++;
            }
        }

        $this->command->info("  {$bound} new category bindings.");
    }

    /**
     * Give existing listings a value for the new attributes.
     *
     * Without this every new filter returns an empty result set on a catalogue
     * of two hundred listings, which reads as a broken filter rather than an
     * unpopulated one. Values are DERIVED from what each listing already says —
     * a listing titled "Land Cruiser Prado" is an SUV — and a listing the rules
     * cannot classify is left alone rather than guessed at.
     */
    private function backfill(): void
    {
        $filled = 0;

        $filled += $this->backfillFromTitle('vehicles', 'body_type', [
            'suv' => ['prado', 'x-trail', 'pajero', 'land cruiser', 'rav4', 'suv'],
            'pickup' => ['hilux', 'ranger', 'pickup', 'navara', 'd-max'],
            'saloon' => ['corolla', 'axio', 'c200', 'saloon', 'sedan'],
            'hatchback' => ['golf', 'vitz', 'march', 'hatchback'],
            'bus' => ['coaster', 'civilian', 'bus'],
            'truck' => ['tipper', 'fvr', 'truck', 'lorry'],
            'motorcycle' => ['boxer', 'apache', 'cb125', 'motorcycle', 'bodaboda'],
        ]);

        $filled += $this->backfillFromTitle('electronics', 'processor', [
            'apple-m2' => ['macbook air m2', ' m2'],
            'intel-core-i5' => ['latitude', 'optiplex', 'i5'],
            'amd-ryzen-5' => ['ryzen 5', 'pavilion'],
            'amd-ryzen-7' => ['ryzen 7'],
            'snapdragon' => ['galaxy s23', 'iphone'],
            'mediatek' => ['tecno', 'infinix'],
        ]);

        $filled += $this->backfillFromTitle('jobs', 'education_level', [
            'bachelor-degree' => ['accountant', 'developer', 'engineer', 'manager'],
            'diploma' => ['supervisor', 'technician'],
            'secondary' => ['assistant', 'guard', 'intern'],
        ]);

        $filled += $this->backfillFromTitle('services', 'service_type', [
            'emergency-call-out' => ['emergency', 'call-out'],
            'installation' => ['installation', 'install'],
            'maintenance' => ['service', 'regas', 'coating'],
            'repair' => ['repair'],
            'one-off-job' => ['cleaning', 'painting', 'photography', 'removal', 'relocation'],
        ]);

        // Fashion brand and service response time are numbers/strings rather
        // than options, so they take a different path.
        $filled += $this->backfillFashionBrand();
        $filled += $this->backfillResponseTime();

        $this->command->info("  {$filled} attribute values backfilled onto existing listings.");
    }

    /**
     * @param  array<string, list<string>>  $rules  option value => title needles
     */
    private function backfillFromTitle(string $vertical, string $code, array $rules): int
    {
        $attribute = Attribute::query()->where('code', $code)->first();

        if ($attribute === null) {
            return 0;
        }

        $options = AttributeOption::query()
            ->where('attribute_id', $attribute->id)
            ->pluck('id', 'value');

        $listings = $this->listingsIn($vertical);
        $count = 0;

        foreach ($listings as $listing) {
            if ($this->alreadyHas($listing->id, $attribute->id)) {
                continue;
            }

            $haystack = mb_strtolower($listing->title);
            $matched = null;

            foreach ($rules as $optionValue => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($haystack, $needle)) {
                        $matched = $optionValue;
                        break 2;
                    }
                }
            }

            // No rule matched: leave it unset. A wrong body type is worse than
            // a missing one — it hides the listing from the correct filter and
            // surfaces it under the wrong one.
            if ($matched === null || ! isset($options[$matched])) {
                continue;
            }

            (new ListingAttributeValue)->forceFill([
                'listing_id' => $listing->id,
                'attribute_id' => $attribute->id,
                'attribute_option_id' => $options[$matched],
                'value_string' => $matched,
            ])->save();

            $count++;
        }

        return $count;
    }

    /** The brand is the first word of a fashion listing's title often enough. */
    private function backfillFashionBrand(): int
    {
        $attribute = Attribute::query()->where('code', 'brand')->first();

        if ($attribute === null) {
            return 0;
        }

        $known = ['Nike', 'Casio', 'Seiko', 'Adidas', 'Puma', 'Zara', 'Gucci'];
        $count = 0;

        foreach ($this->listingsIn('fashion') as $listing) {
            if ($this->alreadyHas($listing->id, $attribute->id)) {
                continue;
            }

            $brand = null;

            foreach ($known as $candidate) {
                if (str_contains(mb_strtolower($listing->title), mb_strtolower($candidate))) {
                    $brand = $candidate;
                    break;
                }
            }

            if ($brand === null) {
                continue;
            }

            (new ListingAttributeValue)->forceFill([
                'listing_id' => $listing->id,
                'attribute_id' => $attribute->id,
                'value_string' => $brand,
            ])->save();

            $count++;
        }

        return $count;
    }

    /**
     * A response-time claim for every service listing.
     *
     * Derived from `availability`, which the listing already carries: someone
     * advertising "anytime" is offering a faster response than someone
     * available "by appointment". Deterministic, so re-running does not
     * reshuffle the catalogue.
     */
    private function backfillResponseTime(): int
    {
        $attribute = Attribute::query()->where('code', 'response_time')->first();

        if ($attribute === null) {
            return 0;
        }

        $availability = Attribute::query()->where('code', 'availability')->first();
        $count = 0;

        foreach ($this->listingsIn('services') as $listing) {
            if ($this->alreadyHas($listing->id, $attribute->id)) {
                continue;
            }

            $current = $availability === null ? null : ListingAttributeValue::query()
                ->where('listing_id', $listing->id)
                ->where('attribute_id', $availability->id)
                ->value('value_string');

            $hours = match ($current) {
                'anytime' => 2,
                'weekdays' => 8,
                'weekends' => 24,
                default => 48,
            };

            (new ListingAttributeValue)->forceFill([
                'listing_id' => $listing->id,
                'attribute_id' => $attribute->id,
                'value_integer' => $hours,
            ])->save();

            $count++;
        }

        return $count;
    }

    /** @return Collection<int, object> */
    private function listingsIn(string $verticalSlug)
    {
        return DB::table('listings as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')
            ->where(function ($q) use ($verticalSlug): void {
                $q->where('c.slug', $verticalSlug)->orWhere('p.slug', $verticalSlug);
            })
            ->whereNull('l.deleted_at')
            ->where('l.status', ListingStatus::Published->value)
            ->select('l.id', 'l.title')
            ->get();
    }

    private function alreadyHas(int $listingId, int $attributeId): bool
    {
        return ListingAttributeValue::query()
            ->where('listing_id', $listingId)
            ->where('attribute_id', $attributeId)
            ->exists();
    }
}
