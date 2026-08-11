<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HomepageSection;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Homepage section configuration, and the settings the admin portal edits.
 *
 * SECTIONS mirror the components the marketing site already renders, with the
 * headings copied verbatim from them. Seeding rather than inventing is the
 * point: an administrator opening the CMS sees the page as it is today and can
 * change it, instead of an empty screen that implies the homepage is unmanaged.
 *
 * SETTINGS are seeded as empty rows on purpose. The settings API only accepts
 * keys that already exist — that is what stops a client inventing configuration
 * — so a key with no row is a key the admin portal cannot offer. Every group
 * the portal exposes needs its keys declared here first.
 *
 * Idempotent: safe to re-run after adding a section or a setting.
 */
class HomepageSeeder extends Seeder
{
    /**
     * key => [title, subtitle, item limit]
     *
     * `key` binds to a React component and is never editable through the API.
     * Titles and subtitles are lifted from the live components.
     *
     * @var array<string, array{0: string, 1: string|null, 2: int|null}>
     */
    private const SECTIONS = [
        'categories' => ['Browse by Category', 'Select a category to explore subcategories', null],
        'recommended' => ['Recommended Listings', 'Handpicked Listings just for you based on your preferences', 4],
        'trending' => ['Trending Listings', 'Discover our most popular and trending Listings', 8],
        'about' => ['About SAKA', null, null],
        'faq' => [
            'Frequently Asked Questions',
            'Find answers to common questions about our property listings, rental process, and services.',
            null,
        ],
    ];

    /**
     * key => [group, is_public, description]
     *
     * `is_public` decides whether the key appears on the unauthenticated
     * `/settings/public` endpoint, so anything that is a CREDENTIAL is false —
     * an SMTP password or a Google client secret must never be world-readable,
     * and the settings API deliberately refuses to make `is_public` writable.
     *
     * @var array<string, array{0: string, 1: bool, 2: string}>
     */
    private const SETTINGS = [
        // ---- general ---------------------------------------------------
        'site.logo_url' => ['general', true, 'Absolute URL of the site logo.'],
        'site.default_locale' => ['general', true, 'Default interface language.'],
        'site.support_hours' => ['contact', true, 'Displayed opening hours for support.'],

        // ---- maps ------------------------------------------------------
        'maps.provider' => ['maps', true, 'Map provider. "google" is the only implementation today.'],
        'maps.api_key' => ['maps', false, 'Server-side Google Maps key. Never exposed publicly.'],
        'maps.default_latitude' => ['maps', true, 'Map centre when a listing has no coordinates.'],
        'maps.default_longitude' => ['maps', true, 'Map centre when a listing has no coordinates.'],
        'maps.default_zoom' => ['maps', true, 'Initial zoom level.'],

        // ---- email -----------------------------------------------------
        'mail.from_name' => ['email', false, 'Display name on outgoing mail.'],
        'mail.from_address' => ['email', false, 'From address on outgoing mail.'],
        'mail.reply_to' => ['email', false, 'Reply-to address on outgoing mail.'],
        'mail.footer_text' => ['email', false, 'Footer line appended to notification emails.'],

        // ---- google sign-in --------------------------------------------
        'auth.google_enabled' => ['auth', true, 'Whether Google sign-in is offered.'],
        'auth.google_client_id' => ['auth', true, 'Public OAuth client id. Public by design — the browser needs it.'],
        'auth.require_phone_to_publish' => ['auth', true, 'Publishing requires a verified phone.'],

        // ---- SEO -------------------------------------------------------
        'seo.meta_title' => ['seo', true, 'Default <title> for pages with no title of their own.'],
        'seo.meta_description' => ['seo', true, 'Default meta description.'],
        'seo.og_image_url' => ['seo', true, 'Fallback Open Graph image.'],
        'seo.robots_indexing' => ['seo', true, 'Whether crawlers may index the site.'],
        'seo.google_analytics_id' => ['seo', true, 'Measurement id, rendered into the page.'],

        // ---- listings --------------------------------------------------
        'listings.auto_expire_days' => ['listings', false, 'Days before a published listing expires.'],
        'listings.max_images' => ['listings', true, 'Maximum images per listing.'],
    ];

    public function run(): void
    {
        $position = 0;

        foreach (self::SECTIONS as $key => [$title, $subtitle, $limit]) {
            HomepageSection::updateOrCreate(
                ['key' => $key],
                [
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'item_limit' => $limit,
                    'position' => $position += 10,
                    'is_active' => true,
                ],
            );
        }

        $created = 0;

        foreach (self::SETTINGS as $key => [$group, $isPublic, $description]) {
            // firstOrCreate, NOT updateOrCreate: re-running this must never
            // overwrite a value an administrator has since set.
            $setting = Setting::firstOrCreate(
                ['key' => $key],
                ['value' => null, 'group' => $group, 'is_public' => $isPublic, 'description' => $description],
            );

            if ($setting->wasRecentlyCreated) {
                $created++;
            }

            // Metadata is code-owned and is kept in step, unlike the value.
            $setting->forceFill([
                'group' => $group,
                'is_public' => $isPublic,
                'description' => $description,
            ])->save();
        }

        $this->command->info(sprintf(
            '  Seeded %d homepage sections and %d settings (%d new).',
            count(self::SECTIONS),
            count(self::SETTINGS),
            $created,
        ));
    }
}
