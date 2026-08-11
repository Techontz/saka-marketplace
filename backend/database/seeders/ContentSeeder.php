<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Page;
use App\Models\PublicPlace;
use App\Models\PublicPlaceCategory;
use App\Models\Region;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Editorial + directory content.
 *
 * FAQs and public-place data are lifted verbatim from the frontend's
 * `lib/listings.ts` (`faqs`, `publicCategories` and their `samples`) so the
 * existing UI renders unchanged once it points at the API.
 */
class ContentSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string}> */
    private const FAQS = [
        [
            'How do I list my property for rent or sale?',
            'You can list your property by signing in to your account, going to the Listings section, and clicking Add Property. Fill in the details, upload photos, and set your pricing. Once submitted, our team will review and publish your listing.',
        ],
        [
            'What is the typical rental process for tenants?',
            'Browse listings, contact the owner, schedule a viewing, submit an application, sign the lease, and move in after payment is confirmed.',
        ],
        [
            'How are payments and rent collection handled?',
            "All payments are processed securely through SAKA's integrated payment system, giving you receipts and reminders.",
        ],
        [
            'Can I schedule a property viewing?',
            'Yes. Every listing includes a schedule viewing button — pick a date and time that works, and the owner will confirm.',
        ],
        [
            'Who do I contact for maintenance or repairs?',
            'Once you are a tenant, log into your dashboard and open a maintenance request. Our team will dispatch a verified professional.',
        ],
    ];

    /** @var array<string, array{icon: string, places: array<int, string>}> */
    private const PUBLIC_PLACES = [
        'Hospitals' => ['icon' => '🏥', 'places' => [
            'Muhimbili National Hospital', 'Aga Khan Hospital',
            'Regency Medical Centre', 'TMJ Hospital',
        ]],
        'Banks' => ['icon' => '🏦', 'places' => [
            'CRDB Bank', 'NMB Bank', 'NBC Bank', 'Stanbic Bank', 'Exim Bank', 'Equity Bank',
        ]],
        'Schools' => ['icon' => '🏫', 'places' => [
            'Feza International School', 'IST Dar es Salaam',
            'Aga Khan Academy', 'Loyola High School',
        ]],
        'Pharmacies' => ['icon' => '💊', 'places' => [
            'Shoppers Pharmacy', 'Medipharm', 'HealthPlus Pharmacy', 'Sanofi Pharmacy',
        ]],
        'Hotels' => ['icon' => '🏨', 'places' => [
            'Serena Hotel', 'Hyatt Regency', 'Southern Sun', 'Golden Tulip',
        ]],
        'Restaurants' => ['icon' => '🍽️', 'places' => [
            'The Waterfront', 'Akemi Revolving', 'Cape Town Fish Market', 'Mamboz BBQ',
        ]],
        'Petrol Stations' => ['icon' => '⛽', 'places' => [
            'Puma Energy', 'Total Energies', 'Oryx', 'Engen',
        ]],
        'Shopping Malls' => ['icon' => '🛍️', 'places' => [
            'Mlimani City', 'Mkuki House', 'GSM Mall', 'Quality Center',
        ]],
    ];

    public function run(): void
    {
        $this->seedFaqs();
        $this->seedPages();
        $this->seedPublicPlaces();
        $this->seedSettings();
    }

    private function seedFaqs(): void
    {
        foreach (self::FAQS as $i => [$question, $answer]) {
            Faq::updateOrCreate(
                ['question' => $question],
                ['answer' => $answer, 'group' => 'general', 'position' => $i * 10, 'is_active' => true],
            );
        }

        $this->command->info('  Seeded '.count(self::FAQS).' FAQs.');
    }

    /**
     * Terms and Privacy exist as real pages. The frontend currently points both
     * footer links at /about — these give it somewhere correct to point.
     * Bodies are placeholders: real legal copy is a business deliverable, not
     * something to invent here.
     */
    private function seedPages(): void
    {
        $pages = [
            'terms-and-conditions' => 'Terms & Conditions',
            'privacy-policy' => 'Privacy Policy',
            'about' => 'About SAKA',
        ];

        foreach ($pages as $slug => $title) {
            Page::updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'body' => "<p>{$title} content pending legal review.</p>",
                    'meta_title' => "{$title} — SAKA",
                    'published_at' => null, // unpublished until real copy lands
                ],
            );
        }

        $this->command->info('  Seeded '.count($pages).' CMS pages (unpublished).');
    }

    private function seedPublicPlaces(): void
    {
        $dar = Region::where('slug', 'dar-es-salaam')->first();
        $placeCount = 0;
        $position = 0;
        foreach (self::PUBLIC_PLACES as $categoryName => $meta) {
            $category = PublicPlaceCategory::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                [
                    'name' => $categoryName,
                    'icon' => $meta['icon'],
                    'position' => $position += 10,
                    'is_active' => true,
                ],
            );

            foreach ($meta['places'] as $placeName) {
                [$latitude, $longitude] = $this->coordinatesFor($placeName, $placeCount);

                PublicPlace::updateOrCreate(
                    ['slug' => Str::slug($placeName)],
                    [
                        'public_place_category_id' => $category->id,
                        'name' => $placeName,
                        'region_id' => $dar?->id,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'is_active' => true,
                    ],
                );
                $placeCount++;
            }

            $category->update(['place_count' => count($meta['places'])]);
        }

        $this->command->info(
            '  Seeded '.count(self::PUBLIC_PLACES)." public-place categories and {$placeCount} places."
        );
    }

    /**
     * A coordinate for every seeded place.
     *
     * Places shipped WITHOUT coordinates, which quietly disabled everything
     * built on them: the places map drew nothing, "nearby listings" and
     * "nearby businesses" had no origin to search from, and the directions
     * buttons had nowhere to point. The feature was fine; the data could not
     * exercise it, which is the kind of gap that reads as a broken product.
     *
     * Known landmarks get their real position. Everything else is spread
     * deterministically around central Dar es Salaam so the map is populated
     * and clustering is exercised — deterministic so re-seeding does not move
     * a place that someone has already linked to.
     *
     * @return array{float, float}
     */
    private function coordinatesFor(string $placeName, int $index): array
    {
        /** @var array<string, array{float, float}> $known */
        $known = [
            'Muhimbili National Hospital' => [-6.8047, 39.2717],
            'Julius Nyerere International Airport' => [-6.8781, 39.2026],
            'University of Dar es Salaam' => [-6.7791, 39.2026],
            'Kariakoo Market' => [-6.8213, 39.2743],
            'Mlimani City' => [-6.7706, 39.2214],
            'Coco Beach' => [-6.7566, 39.2856],
            'National Museum' => [-6.8145, 39.2905],
            'Dar es Salaam Port' => [-6.8235, 39.2969],
        ];

        if (isset($known[$placeName])) {
            return $known[$placeName];
        }

        // A deterministic spiral around the city centre: no randomness, so the
        // same place lands in the same spot on every seed.
        $angle = $index * 2.399963;          // golden angle, for an even spread
        $radius = 0.012 * sqrt($index + 1);  // roughly 1.3km per step outward

        return [
            round(-6.8162 + ($radius * cos($angle)), 7),
            round(39.2803 + ($radius * sin($angle)), 7),
        ];
    }

    private function seedSettings(): void
    {
        $settings = [
            ['site.name', 'SAKA', 'general', true],
            ['site.tagline', 'Search listings with Sale and Rent in the World', 'general', true],
            /*
             * Contact details.
             *
             * The email carries a real, brand-consistent default on the .co.tz
             * domain the marketplace actually uses.
             *
             * THE PHONE NUMBER IS DELIBERATELY NULL. It shipped as
             * "+255 123 456 789" — not a valid Tanzanian mobile prefix, and
             * therefore obviously fake, but a fake number in a footer is not a
             * harmless placeholder: it is published on every page of the site,
             * customers ring it, and the day it resolves to somebody's real
             * line it becomes their problem. A missing phone row is honest; a
             * wrong one is not. The footer renders nothing until an operator
             * sets it in Settings.
             */
            ['contact.email', 'info@saka.africa', 'contact', true],
            ['contact.phone', null, 'contact', true],
            ['contact.address', 'Samora Avenue, Ilala, Dar es Salaam, Tanzania', 'contact', true],

            /*
             * Social profiles.
             *
             * Seeded EMPTY on purpose. The footer's three social icons were
             * `href="#"` — buttons that visibly did nothing on every page of
             * the site. They now render only for the networks that have a URL
             * here, so an operator fills these in from the admin portal and the
             * icons appear; leave one blank and its icon simply is not there.
             * Inventing profile URLs would have been worse than the dead links.
             */
            ['social.facebook', null, 'social', true],
            ['social.instagram', null, 'social', true],
            ['social.linkedin', null, 'social', true],
            ['social.x', null, 'social', true],
            ['listings.require_moderation', true, 'listings', false],
            ['listings.expiry_days', 60, 'listings', false],
            ['features.reviews_enabled', true, 'features', true],
            ['features.messaging_enabled', false, 'features', true],
            ['features.payments_enabled', false, 'features', true],
        ];

        foreach ($settings as [$key, $value, $group, $isPublic]) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $group, 'is_public' => $isPublic],
            );
        }

        $this->command->info('  Seeded '.count($settings).' settings.');
    }
}
