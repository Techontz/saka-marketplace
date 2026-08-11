<?php

declare(strict_types=1);
use App\Services\Search\Drivers\MySqlFullTextDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Client applications
    |--------------------------------------------------------------------------
    | The API is client-agnostic (Milestone 4 decision 3). These URLs are used
    | for CORS, e-mail links and OAuth redirects only — no client is special.
    */
    'frontend_url' => env('SAKA_FRONTEND_URL', 'http://localhost:3000'),
    'admin_url' => env('SAKA_ADMIN_URL', 'http://localhost:3001'),

    /*
    |--------------------------------------------------------------------------
    | Marketplace defaults
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('SAKA_DEFAULT_CURRENCY', 'TZS'),
    'default_country' => env('SAKA_DEFAULT_COUNTRY', 'TZ'),

    /*
    | Money is stored in MINOR UNITS everywhere. TZS has no circulating subunit,
    | so the exponent is 0 — but keeping the concept means adding USD/KES later
    | is configuration, not a migration.
    */
    'currencies' => [
        'TZS' => ['exponent' => 0, 'symbol' => 'TZS'],
        'USD' => ['exponent' => 2, 'symbol' => '$'],
        'KES' => ['exponent' => 2, 'symbol' => 'KSh'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Listings
    |--------------------------------------------------------------------------
    */
    'listings' => [
        'expiry_days' => (int) env('SAKA_LISTING_EXPIRY_DAYS', 60),
        'require_moderation' => (bool) env('SAKA_REQUIRE_LISTING_MODERATION', true),

        // Milestone 4 decision 5: publishing requires a verified phone number.
        // Browsing stays open to guests.
        'require_phone_verification_to_publish' => (bool) env('SAKA_REQUIRE_PHONE_VERIFICATION', true),

        'daily_create_quota' => [
            'buyer' => 3,
            'seller' => 50,
            'agent' => 200,
        ],

        /*
         * Categories whose listings may carry a surveyed parcel outline.
         *
         * Matched against the leaf category slug AND its vertical, so naming a
         * vertical here would enable every subcategory under it. Kept as config
         * rather than a boolean column on `categories` because it is a product
         * rule, not taxonomy data.
         *
         * Only Plots. `agriculture` was tried as a whole vertical and was
         * wrong: it put a drawing tool — and, in the seeder, an actual parcel
         * outline — on 50 kg bags of fertiliser and day-old chicks. A boundary
         * belongs to a thing sold BY ITS EXTENT, which within agriculture is
         * farmland, and there is no farmland subcategory to name. Add one here
         * the day the taxonomy grows one.
         */
        'boundary_categories' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAKA_BOUNDARY_CATEGORIES', 'property-plots')),
        ))),

        // A hand-traced outline; more corners than this is a GIS import, not a
        // seller clicking on satellite imagery.
        'boundary_max_vertices' => (int) env('SAKA_BOUNDARY_MAX_VERTICES', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    | `disk` is the ONLY thing that needs to change to move to S3. Each media
    | row records the disk it was written to, so old and new files both resolve
    | during and after a migration.
    */
    'media' => [
        'disk' => env('MEDIA_DISK', 'public'),
        'max_image_mb' => (int) env('MEDIA_MAX_IMAGE_MB', 5),
        'max_images_per_listing' => (int) env('MEDIA_MAX_IMAGES_PER_LISTING', 20),

        'accepted_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

        // SVG is deliberately excluded: it is an XSS vector when served inline.
        'variants' => [
            'thumb' => ['width' => 200, 'height' => 150],
            'card' => ['width' => 400, 'height' => 300],
            'detail' => ['width' => 800, 'height' => 600],
            'full' => ['width' => 1600, 'height' => 1200],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone verification (OTP)
    |--------------------------------------------------------------------------
    */
    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 3,
        'resend_cooldown_seconds' => 60,
        'max_per_hour' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth tokens
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'token_ttl_minutes' => (int) env('SANCTUM_TOKEN_TTL_MINUTES', 1440),
        'refresh_ttl_minutes' => (int) env('SANCTUM_REFRESH_TTL_MINUTES', 43200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    | MVP runs MySQL FULLTEXT. Meilisearch is introduced by adding a driver and
    | changing this one value — no controller, route or response change.
    */
    'search' => [
        'driver' => env('SAKA_SEARCH_DRIVER', 'mysql'),

        'drivers' => [
            'mysql' => MySqlFullTextDriver::class,
            // 'meilisearch' => App\Services\Search\Drivers\MeilisearchDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    | The metrics endpoint exposes inventory volume and queue depth, so it is
    | gated on a shared secret. With no token set the endpoint fails CLOSED.
    */
    'seeding' => [
        'admin_email' => env('SEED_ADMIN_EMAIL', 'admin@saka.africa'),
        // Read through config, never env(), so a cached config does not turn
        // this into null and silently skip seeding.
        'admin_password' => env('SEED_ADMIN_PASSWORD'),
    ],

    /*
     * Set true when more than one web node serves this deployment.
     *
     * Only consulted to decide whether to REFUSE file-driver maintenance mode
     * from the admin portal — with the file driver, `artisan down` only takes
     * down the node that handled the request, leaving the platform half-up in
     * a way that is very hard to diagnose.
     */
    'multi_node' => (bool) env('SAKA_MULTI_NODE', false),

    'observability' => [
        'metrics_token' => env('METRICS_TOKEN'),
        'slow_query_ms' => (int) env('SLOW_QUERY_MS', 500),
        'slow_request_ms' => (int) env('SLOW_REQUEST_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

];
