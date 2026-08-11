<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Domain\Catalog\Enums\AttributeInputType;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Specialists vertical.
 *
 * Seed data, NOT a migration — which is the whole point of the EAV design. A
 * specialist is a listing in the `specialists` vertical, "practice area" is an
 * attribute bound to the Lawyers category, and adding "Notaries" tomorrow is
 * three rows an administrator can create through the existing taxonomy screens.
 *
 * The per-category attribute bindings below are what make the profile forms and
 * the filter panel category-aware without a single conditional in the frontend:
 * a lawyer is asked for their practice area and a teacher is not, because the
 * category says so.
 *
 * Idempotent throughout — this runs alongside the other catalogue seeders and
 * on every fresh test database.
 */
class SpecialistCatalogSeeder extends Seeder
{
    /**
     * Attributes shared by every specialist category.
     *
     * Experience and languages are asked of everyone because they are the two
     * things every customer filters on regardless of profession — and because
     * an attribute defined once and bound many times is the point of the EAV
     * table.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SHARED = [
        'years_experience' => [
            'name' => 'Years of experience',
            'input' => AttributeInputType::Number,
            'data' => AttributeDataType::Integer,
            'unit' => 'years',
            'min' => 0,
            'max' => 60,
        ],
        'languages' => [
            'name' => 'Languages',
            'input' => AttributeInputType::Select,
            'data' => AttributeDataType::String,
            'options' => ['Swahili', 'English', 'French', 'Arabic', 'Chinese'],
        ],
        'credentials' => [
            'name' => 'Professional credentials',
            'input' => AttributeInputType::Text,
            'data' => AttributeDataType::String,
            // Free text on purpose: "Advocate of the High Court of Tanzania"
            // and "CPA(T), NBAA registered" are both real and neither belongs
            // in a dropdown somebody has to maintain.
            'filterable' => false,
        ],
    ];

    /**
     * The categories, and the attributes each one adds on top of SHARED.
     *
     * @var array<string, array{name: string, icon: string, attributes: array<string, array<string, mixed>>}>
     */
    private const CATEGORIES = [
        'lawyers' => [
            'name' => 'Lawyers',
            'icon' => '⚖️',
            'attributes' => [
                'practice_area' => [
                    'name' => 'Practice area',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => [
                        'Commercial law', 'Property & conveyancing', 'Family law',
                        'Employment law', 'Criminal defence', 'Tax law',
                        'Immigration', 'Intellectual property',
                    ],
                ],
                'consultation_type' => [
                    'name' => 'Consultation type',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['In chambers', 'Online', 'At your premises'],
                ],
            ],
        ],
        'legal-advisors' => [
            'name' => 'Legal advisors',
            'icon' => '📜',
            'attributes' => [
                'advisory_area' => [
                    'name' => 'Advisory area',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Contracts', 'Compliance', 'Corporate governance', 'Land matters'],
                ],
            ],
        ],
        'accountants' => [
            'name' => 'Accountants',
            'icon' => '📊',
            'attributes' => [
                'accounting_service' => [
                    'name' => 'Service',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Bookkeeping', 'Tax returns', 'Audit', 'Payroll', 'Company registration'],
                ],
            ],
        ],
        'teachers' => [
            'name' => 'Teachers & tutors',
            'icon' => '📚',
            'attributes' => [
                'subjects' => [
                    'name' => 'Subjects',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => [
                        'Mathematics', 'Physics', 'Chemistry', 'Biology', 'English',
                        'Kiswahili', 'History', 'Geography', 'Computer studies', 'Economics',
                    ],
                ],
                'education_level' => [
                    'name' => 'Level taught',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Primary', 'O-Level', 'A-Level', 'University', 'Adult learners'],
                ],
                'teaching_mode' => [
                    'name' => 'Teaching mode',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['One-to-one', 'Small group', 'Classroom'],
                ],
            ],
        ],
        'engineers' => [
            'name' => 'Engineers',
            'icon' => '🛠️',
            'attributes' => [
                'discipline' => [
                    'name' => 'Discipline',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => [
                        'Civil', 'Structural', 'Mechanical', 'Electrical',
                        'Water & sanitation', 'Geotechnical',
                    ],
                ],
            ],
        ],
        'architects' => [
            'name' => 'Architects',
            'icon' => '📐',
            'attributes' => [
                'project_type' => [
                    'name' => 'Project type',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Residential', 'Commercial', 'Interior', 'Landscape', 'Restoration'],
                ],
            ],
        ],
        'software-developers' => [
            'name' => 'Software developers',
            'icon' => '💻',
            'attributes' => [
                'technology' => [
                    'name' => 'Technology',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => [
                        'PHP / Laravel', 'JavaScript / React', 'Python', 'Java',
                        'Flutter', 'Android', 'iOS', '.NET',
                    ],
                ],
                'specialisation' => [
                    'name' => 'Specialisation',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Web applications', 'Mobile apps', 'APIs & integrations', 'E-commerce', 'Data & reporting'],
                ],
                'portfolio_url' => [
                    'name' => 'Portfolio',
                    'input' => AttributeInputType::Text,
                    'data' => AttributeDataType::String,
                    'filterable' => false,
                ],
            ],
        ],
        'it-consultants' => [
            'name' => 'IT consultants',
            'icon' => '🖧',
            'attributes' => [
                'it_service' => [
                    'name' => 'Service',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Networking', 'Cloud migration', 'Cyber security', 'Support & maintenance', 'ERP'],
                ],
            ],
        ],
        'designers' => [
            'name' => 'Designers',
            'icon' => '🎨',
            'attributes' => [
                'design_field' => [
                    'name' => 'Design field',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Branding & identity', 'Graphic design', 'UI/UX', 'Print', 'Motion'],
                ],
            ],
        ],
        'photographers' => [
            'name' => 'Photographers & videographers',
            'icon' => '📷',
            'attributes' => [
                'shoot_type' => [
                    'name' => 'Shoot type',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Weddings', 'Events', 'Portraits', 'Product', 'Real estate', 'Corporate video'],
                ],
            ],
        ],
        'marketing-consultants' => [
            'name' => 'Marketing consultants',
            'icon' => '📣',
            'attributes' => [
                'marketing_service' => [
                    'name' => 'Service',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Social media', 'SEO', 'Content', 'Paid advertising', 'Brand strategy'],
                ],
            ],
        ],
        'business-consultants' => [
            'name' => 'Business consultants',
            'icon' => '💼',
            'attributes' => [
                'consulting_area' => [
                    'name' => 'Consulting area',
                    'input' => AttributeInputType::Select,
                    'data' => AttributeDataType::String,
                    'options' => ['Business planning', 'Financial advisory', 'HR & recruitment', 'Operations', 'Market research'],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $vertical = $this->vertical();

        $shared = [];

        foreach (self::SHARED as $code => $definition) {
            $shared[$code] = $this->attribute($code, $definition);
        }

        $position = 0;

        foreach (self::CATEGORIES as $slug => $definition) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'parent_id' => $vertical->id,
                    'name' => $definition['name'],
                    'icon' => $definition['icon'],
                    'path' => $vertical->id.'/',
                    'depth' => 1,
                    'position' => $position += 10,
                    'is_active' => true,
                    'is_leaf' => true,
                ],
            );

            // The materialised path includes the node itself; it can only be
            // written once the id exists.
            $category->forceFill(['path' => $vertical->id.'/'.$category->id])->save();

            $bindings = $shared;

            foreach ($definition['attributes'] as $code => $attributeDefinition) {
                $bindings[$code] = $this->attribute($code, $attributeDefinition);
            }

            $order = 0;

            foreach ($bindings as $attribute) {
                DB::table('category_attribute')->updateOrInsert(
                    ['category_id' => $category->id, 'attribute_id' => $attribute->id],
                    [
                        'is_required' => false,
                        'is_filterable' => (bool) $attribute->is_filterable,
                        'position' => $order += 10,
                    ],
                );
            }
        }
    }

    /** The `specialists` root, created if the catalogue does not have one. */
    private function vertical(): Category
    {
        $vertical = Category::query()->updateOrCreate(
            ['slug' => 'specialists'],
            [
                'parent_id' => null,
                'name' => 'Specialists',
                'icon' => '🎓',
                'description' => 'Lawyers, teachers, engineers, developers and other professionals available for hire across Tanzania.',
                'depth' => 0,
                // A vertical holds no listings itself — only its children do.
                'is_leaf' => false,
                'is_active' => true,
                'position' => 130,
            ],
        );

        $vertical->forceFill(['path' => (string) $vertical->id])->save();

        return $vertical;
    }

    /** @param  array<string, mixed>  $definition */
    private function attribute(string $code, array $definition): Attribute
    {
        $attribute = Attribute::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $definition['name'],
                'input_type' => $definition['input'],
                'data_type' => $definition['data'],
                'unit' => $definition['unit'] ?? null,
                'is_filterable' => $definition['filterable'] ?? true,
                'is_searchable' => false,
                'is_required' => false,
                'min_value' => $definition['min'] ?? null,
                'max_value' => $definition['max'] ?? null,
            ],
        );

        foreach ($definition['options'] ?? [] as $index => $option) {
            AttributeOption::query()->updateOrCreate(
                ['attribute_id' => $attribute->id, 'value' => $option],
                ['label' => $option, 'position' => ($index + 1) * 10],
            );
        }

        return $attribute;
    }
}
