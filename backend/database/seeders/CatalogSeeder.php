<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Catalog\Enums\AttributeInputType;
use App\Models\Amenity;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Facility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Taxonomy + the EAV attribute set.
 *
 * The category tree is taken verbatim from the frontend's `listingCategories`
 * (9 verticals, same names, same icons, same subcategory order) so the existing
 * UI renders unchanged against live data.
 *
 * The attribute set is the proof that the EAV design works: a Vehicle gets
 * `mileage` and `fuel_type`, a Job gets `employment_type`, a Property gets
 * `beds`/`bathrooms`/`sqft` — with no nullable columns and no per-vertical
 * tables. Adding a tenth vertical is another entry in this array.
 */
class CatalogSeeder extends Seeder
{
    /** @var array<string, array{icon: string, subcategories: array<int, string>}> */
    private const CATEGORIES = [
        'Property' => ['icon' => '🏠', 'subcategories' => [
            'Apartments', 'Houses', 'Rooms', 'Flats', 'Plots',
            'Commercial', 'Offices', 'Warehouses', 'Hotels', 'Hostels',
        ]],
        'Vehicles' => ['icon' => '🚗', 'subcategories' => [
            'Cars', 'SUVs', 'Motorcycles', 'Trucks', 'Buses',
            'Pickups', 'Boats', 'Spare Parts', 'Tyres',
        ]],
        'Electronics' => ['icon' => '💻', 'subcategories' => [
            'Phones', 'Laptops', 'Desktop PCs', 'Tablets',
            'Gaming', 'TVs', 'Cameras', 'Accessories',
        ]],
        'Furniture' => ['icon' => '🛋️', 'subcategories' => [
            'Beds', 'Sofas', 'Dining', 'Office Furniture', 'Wardrobes', 'Kitchen',
        ]],
        'Fashion' => ['icon' => '👕', 'subcategories' => [
            'Men', 'Women', 'Kids', 'Shoes', 'Bags', 'Jewelry', 'Watches',
        ]],
        'Jobs' => ['icon' => '💼', 'subcategories' => [
            'Full Time', 'Part Time', 'Remote', 'Internships', 'Freelance',
        ]],
        'Services' => ['icon' => '🛠️', 'subcategories' => [
            'Cleaning', 'Moving', 'Repair', 'Construction',
            'Plumbing', 'Electrical', 'Painting', 'Photography',
        ]],
        'Agriculture' => ['icon' => '🌾', 'subcategories' => [
            'Livestock', 'Seeds', 'Machinery', 'Fertilizers', 'Animal Feed',
        ]],
        'Pets' => ['icon' => '🐕', 'subcategories' => [
            'Dogs', 'Cats', 'Birds', 'Fish', 'Pet Food',
        ]],
    ];

    /**
     * code => [name, input, data, unit, filterable, options[], min, max]
     *
     * @var array<string, array<int, mixed>>
     */
    private const ATTRIBUTES = [
        // ---- Property -------------------------------------------------------
        'beds' => ['Bedrooms', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 0, 50],
        'bathrooms' => ['Bathrooms', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 0, 50],
        'sqft' => ['Area', AttributeInputType::Number, AttributeDataType::Integer, 'sqft', true, [], 0, 1000000],
        'balconies' => ['Balconies', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 0, 20],
        'parkings' => ['Parking spaces', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 0, 50],
        'furnishing' => ['Furnishing', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Furnished', 'Semi-Furnished', 'Unfurnished'], null, null],
        'unit_facing' => ['Unit facing', AttributeInputType::Select, AttributeDataType::String, null, false,
            ['North', 'South', 'East', 'West'], null, null],
        'floor_number' => ['Floor', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], -5, 200],
        'doors' => ['Doors', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 0, 50],

        // ---- Vehicles -------------------------------------------------------
        'make' => ['Make', AttributeInputType::Text, AttributeDataType::String, null, true, [], null, null],
        'model' => ['Model', AttributeInputType::Text, AttributeDataType::String, null, true, [], null, null],
        'year' => ['Year of manufacture', AttributeInputType::Number, AttributeDataType::Integer, null, true, [], 1950, 2100],
        'mileage' => ['Mileage', AttributeInputType::Number, AttributeDataType::Integer, 'km', true, [], 0, 2000000],
        'fuel_type' => ['Fuel type', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Petrol', 'Diesel', 'Hybrid', 'Electric', 'LPG'], null, null],
        'transmission' => ['Transmission', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Manual', 'Automatic'], null, null],
        'engine_cc' => ['Engine size', AttributeInputType::Number, AttributeDataType::Integer, 'cc', true, [], 0, 20000],

        // ---- Electronics ----------------------------------------------------
        'brand' => ['Brand', AttributeInputType::Text, AttributeDataType::String, null, true, [], null, null],
        'storage_gb' => ['Storage', AttributeInputType::Number, AttributeDataType::Integer, 'GB', true, [], 0, 100000],
        'ram_gb' => ['RAM', AttributeInputType::Number, AttributeDataType::Integer, 'GB', true, [], 0, 1024],
        'screen_size' => ['Screen size', AttributeInputType::Number, AttributeDataType::Decimal, 'in', true, [], 0, 200],
        'warranty_months' => ['Warranty', AttributeInputType::Number, AttributeDataType::Integer, 'months', false, [], 0, 120],

        // ---- Fashion / Furniture -------------------------------------------
        'size' => ['Size', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['XS', 'S', 'M', 'L', 'XL', 'XXL'], null, null],
        'colour' => ['Colour', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Black', 'White', 'Grey', 'Blue', 'Red', 'Green', 'Brown', 'Multi'], null, null],
        'gender' => ['Gender', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Men', 'Women', 'Unisex', 'Kids'], null, null],
        'material' => ['Material', AttributeInputType::Text, AttributeDataType::String, null, false, [], null, null],
        'dimensions' => ['Dimensions', AttributeInputType::Text, AttributeDataType::String, null, false, [], null, null],

        // ---- Jobs -----------------------------------------------------------
        'employment_type' => ['Employment type', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Full time', 'Part time', 'Contract', 'Internship', 'Freelance'], null, null],
        'experience_years' => ['Experience required', AttributeInputType::Number, AttributeDataType::Integer, 'years', true, [], 0, 60],
        'salary_period' => ['Salary period', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Hourly', 'Daily', 'Monthly', 'Yearly'], null, null],
        'is_remote' => ['Remote', AttributeInputType::Boolean, AttributeDataType::Boolean, null, true, [], null, null],

        // ---- Services -------------------------------------------------------
        'service_area' => ['Service area', AttributeInputType::Text, AttributeDataType::String, null, false, [], null, null],
        'availability' => ['Availability', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['Weekdays', 'Weekends', 'Anytime', 'By appointment'], null, null],

        // ---- Agriculture ----------------------------------------------------
        'quantity' => ['Quantity', AttributeInputType::Number, AttributeDataType::Decimal, null, true, [], 0, 1000000],
        'unit_of_measure' => ['Unit', AttributeInputType::Select, AttributeDataType::String, null, true,
            ['kg', 'tonne', 'litre', 'bag', 'piece', 'acre'], null, null],

        // ---- Pets -----------------------------------------------------------
        'breed' => ['Breed', AttributeInputType::Text, AttributeDataType::String, null, true, [], null, null],
        'age_months' => ['Age', AttributeInputType::Number, AttributeDataType::Integer, 'months', true, [], 0, 600],
        'vaccinated' => ['Vaccinated', AttributeInputType::Boolean, AttributeDataType::Boolean, null, true, [], null, null],
    ];

    /**
     * Attributes bound at ROOT category level; they inherit down to every
     * subcategory via Category::resolvedAttributes(). `required` marks the
     * facets a listing in that vertical cannot omit.
     *
     * @var array<string, array{required: array<int,string>, optional: array<int,string>}>
     */
    private const BINDINGS = [
        'Property' => [
            'required' => ['beds', 'bathrooms', 'sqft'],
            'optional' => ['balconies', 'doors', 'parkings', 'furnishing', 'unit_facing', 'floor_number'],
        ],
        'Vehicles' => [
            'required' => ['make', 'year'],
            'optional' => ['model', 'mileage', 'fuel_type', 'transmission', 'engine_cc', 'colour'],
        ],
        'Electronics' => [
            'required' => ['brand'],
            'optional' => ['storage_gb', 'ram_gb', 'screen_size', 'warranty_months', 'colour'],
        ],
        'Furniture' => [
            'required' => [],
            'optional' => ['material', 'dimensions', 'colour'],
        ],
        'Fashion' => [
            'required' => ['size'],
            'optional' => ['colour', 'gender', 'material'],
        ],
        'Jobs' => [
            'required' => ['employment_type'],
            'optional' => ['experience_years', 'salary_period', 'is_remote'],
        ],
        'Services' => [
            'required' => [],
            'optional' => ['service_area', 'availability', 'experience_years'],
        ],
        'Agriculture' => [
            'required' => ['quantity', 'unit_of_measure'],
            'optional' => [],
        ],
        'Pets' => [
            'required' => [],
            'optional' => ['breed', 'age_months', 'vaccinated'],
        ],
    ];

    private const AMENITIES = [
        'Air Conditioning', 'Balcony', 'Swimming Pool', 'Gym', 'Parking',
        'Security', 'Elevator', 'Garden', 'Furnished', 'Backup Generator',
        'Water Tank', 'CCTV', 'Servant Quarters', 'Sea View', 'Fibre Internet',
    ];

    private const FACILITIES = [
        'Hospital Nearby', 'School Nearby', 'Shopping Mall', 'Public Transport',
        'Bank / ATM', 'Pharmacy', 'Restaurant', 'Petrol Station',
        'Place of Worship', 'Market',
    ];

    public function run(): void
    {
        $attributes = $this->seedAttributes();
        $roots = $this->seedCategories();
        $this->bindAttributes($roots, $attributes);
        $this->seedAmenitiesAndFacilities($roots);
    }

    /** @return array<string, Attribute> */
    private function seedAttributes(): array
    {
        $created = [];
        $position = 0;

        foreach (self::ATTRIBUTES as $code => [$name, $input, $data, $unit, $filterable, $options, $min, $max]) {
            $attribute = Attribute::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'input_type' => $input,
                    'data_type' => $data,
                    'unit' => $unit,
                    'is_filterable' => $filterable,
                    'is_searchable' => in_array($code, ['make', 'model', 'brand', 'breed'], true),
                    'position' => $position += 10,
                    'min_value' => $min,
                    'max_value' => $max,
                ],
            );

            foreach ($options as $i => $option) {
                AttributeOption::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => Str::slug($option)],
                    ['label' => $option, 'position' => $i * 10],
                );
            }

            $created[$code] = $attribute;
        }

        $this->command->info('  Seeded '.count($created).' attributes.');

        return $created;
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $roots = [];
        $position = 0;
        $subCount = 0;

        foreach (self::CATEGORIES as $name => $meta) {
            $root = Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'parent_id' => null,
                    'name' => $name,
                    'icon' => $meta['icon'],
                    'depth' => 0,
                    'position' => $position += 10,
                    'is_active' => true,
                    // A root with children cannot itself hold listings.
                    'is_leaf' => false,
                ],
            );
            // Materialised path is self-referential at the root.
            $root->forceFill(['path' => (string) $root->id])->save();
            $roots[$name] = $root;

            foreach ($meta['subcategories'] as $i => $subName) {
                $sub = Category::updateOrCreate(
                    ['slug' => Str::slug("{$name} {$subName}")],
                    [
                        'parent_id' => $root->id,
                        'name' => $subName,
                        'depth' => 1,
                        'position' => $i * 10,
                        'is_active' => true,
                        'is_leaf' => true,
                    ],
                );
                $sub->forceFill(['path' => "{$root->id}/{$sub->id}"])->save();
                $subCount++;
            }
        }

        $this->command->info('  Seeded '.count($roots)." root categories and {$subCount} subcategories.");

        return $roots;
    }

    /**
     * @param  array<string, Category>  $roots
     * @param  array<string, Attribute>  $attributes
     */
    private function bindAttributes(array $roots, array $attributes): void
    {
        $bindings = 0;

        foreach (self::BINDINGS as $categoryName => $spec) {
            $category = $roots[$categoryName] ?? null;
            if ($category === null) {
                continue;
            }

            $sync = [];
            $position = 0;

            foreach ($spec['required'] as $code) {
                if (isset($attributes[$code])) {
                    $sync[$attributes[$code]->id] = [
                        'is_required' => true,
                        'is_filterable' => $attributes[$code]->is_filterable,
                        'position' => $position += 10,
                    ];
                }
            }

            foreach ($spec['optional'] as $code) {
                if (isset($attributes[$code])) {
                    $sync[$attributes[$code]->id] = [
                        'is_required' => false,
                        'is_filterable' => $attributes[$code]->is_filterable,
                        'position' => $position += 10,
                    ];
                }
            }

            $category->attributes()->sync($sync);
            $bindings += count($sync);
        }

        $this->command->info("  Bound {$bindings} category-attribute pairs.");
    }

    /** @param array<string, Category> $roots */
    private function seedAmenitiesAndFacilities(array $roots): void
    {
        $propertyId = $roots['Property']->id ?? null;

        foreach (self::AMENITIES as $i => $name) {
            Amenity::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'category_id' => $propertyId, 'position' => $i * 10, 'is_active' => true],
            );
        }

        foreach (self::FACILITIES as $i => $name) {
            Facility::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'category_id' => $propertyId, 'position' => $i * 10, 'is_active' => true],
            );
        }

        $this->command->info(
            '  Seeded '.count(self::AMENITIES).' amenities and '.count(self::FACILITIES).' facilities.'
        );
    }
}
