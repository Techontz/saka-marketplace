<?php

declare(strict_types=1);

/*
 * Curated OpenAPI metadata, consumed by docs/generate-openapi.php.
 *
 * Kept separate from the generator so the spec is driven by the ROUTER (which
 * cannot drift) while descriptions and schemas stay hand-written and useful.
 * A route with no entry here fails the generator rather than going silently
 * undocumented.
 */

const OPENAPI_INFO = [
    'title' => 'SAKA Marketplace API',
    'version' => '1.0.0',
    'description' => 'Multi-vertical marketplace API for Tanzania.

**Errors.** Every failure returns the same envelope:

    { "error": { "code": "...", "message": "...", "details": {...}, "request_id": "..." } }

Clients should switch on `error.code`, never on `message`. Validation failures (422) additionally carry Laravel\'s `errors` map.

**Correlation.** Every response carries `X-Request-Id`; an inbound value is honoured when it matches `^[A-Za-z0-9_-]{8,64}$`.

**Authentication.** Bearer tokens issued by `/auth/login`. Tokens are unscoped; authorization is enforced by server-side policies.

**Not found vs forbidden.** Resources the caller may not see return 404, never 403, so existence is not disclosed.',
    'contact' => [
        'name' => 'SAKA Engineering',
    ],
    'license' => [
        'name' => 'Proprietary',
    ],
];

const OPENAPI_SERVERS = [
    [
        'url' => 'https://api.saka.co.tz/api/v1',
        'description' => 'Production',
    ],
    [
        'url' => 'http://localhost:8000/api/v1',
        'description' => 'Local',
    ],
];

const OPENAPI_TAGS = [
    [
        'name' => 'System',
        'description' => 'Health probes and metrics',
    ],
    [
        'name' => 'Authentication',
        'description' => 'Registration, sign-in, tokens, phone verification',
    ],
    [
        'name' => 'Account',
        'description' => 'The signed-in user acting on themselves',
    ],
    [
        'name' => 'Listings',
        'description' => 'Public browse, search and detail',
    ],
    [
        'name' => 'Businesses',
        'description' => 'Public business pages: profile, listings, reviews and nearby',
    ],
    [
        'name' => 'Search',
        'description' => 'Type-ahead suggestions and popular searches',
    ],
    [
        'name' => 'Catalog',
        'description' => 'Categories, attributes, locations and taxonomies',
    ],
    [
        'name' => 'Favorites',
        'description' => 'Saved listings',
    ],
    [
        'name' => 'Reviews',
        'description' => 'Seller and listing reviews',
    ],
    [
        'name' => 'Inquiries',
        'description' => 'Contact seller and general contact',
    ],
    [
        'name' => 'Public places',
        'description' => 'Directory of hospitals, banks, schools and more',
    ],
    [
        'name' => 'Content',
        'description' => 'FAQs, CMS pages and public settings',
    ],
    [
        'name' => 'Seller',
        'description' => 'Managing your own listings and profile',
    ],
    [
        'name' => 'Media',
        'description' => 'Listing images',
    ],
    [
        'name' => 'Administration',
        'description' => 'Users, roles, verification, taxonomy, CMS and settings',
    ],
    [
        'name' => 'Moderation',
        'description' => 'Administrative review queues',
    ],
];

const OPENAPI_COMPONENTS = [
    'securitySchemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'Token from `/auth/login`, `/auth/register` or `/auth/oauth/google`.',
        ],
    ],
    'schemas' => [
        'Business' => [
            'type' => 'object',
            'description' => 'A business as the public sees it. Registration number and TIN are absent by construction, not by a conditional.',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'display_name' => [
                    'type' => 'string',
                ],
                'business_type' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_type_label' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'logo_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'location' => [
                    'type' => 'object',
                ],
                'rating' => [
                    'type' => 'object',
                    'properties' => [
                        'average' => [
                            'type' => [
                                'number',
                                'null',
                            ],
                        ],
                        'count' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'listing_count' => [
                    'type' => 'integer',
                ],
                'is_verified' => [
                    'type' => 'boolean',
                ],
                'distance_km' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                    'description' => 'Present only on a radius search.',
                ],
            ],
        ],
        'BusinessDetail' => [
            'type' => 'object',
            'description' => 'Business, plus everything a business PAGE needs: contact, hours, social links, verification and stats.',
            'allOf' => [
                [
                    '$ref' => '#/components/schemas/Business',
                ],
            ],
            'properties' => [
                'bio' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'cover_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'contact' => [
                    'type' => 'object',
                ],
                'opening_hours' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'description' => 'Null means never configured; an empty day means closed that day.',
                ],
                'social_links' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                ],
                'verification' => [
                    'type' => 'object',
                ],
                'stats' => [
                    'type' => 'object',
                ],
                'member_since' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'format' => 'date-time',
                ],
            ],
        ],
        'SearchSuggestion' => [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'listing',
                        'business',
                        'category',
                        'place',
                    ],
                ],
                'label' => [
                    'type' => 'string',
                ],
                'slug' => [
                    'type' => 'string',
                ],
            ],
        ],
        'Notification' => [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'e.g. inquiry.replied, favorite.price_changed, listing.approved',
                ],
                'data' => [
                    'type' => 'object',
                    'description' => 'Rendered at write time: title, body, and whatever the client needs to link to the thing it is about.',
                    'additionalProperties' => true,
                ],
                'read' => [
                    'type' => 'boolean',
                ],
                'read_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'format' => 'date-time',
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'FavoriteHistoryEntry' => [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'listing',
                        'business',
                    ],
                ],
                'saved_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'format' => 'date-time',
                ],
                'removed_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'format' => 'date-time',
                ],
                'still_saved' => [
                    'type' => 'boolean',
                ],
                'target' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'description' => 'Null when the listing or business has since been removed.',
                ],
            ],
        ],
        'Error' => [
            'type' => 'object',
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                        ],
                        'message' => [
                            'type' => 'string',
                        ],
                        'details' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                        'request_id' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'code',
                        'message',
                    ],
                ],
            ],
            'required' => [
                'error',
            ],
        ],
        'ValidationError' => [
            'allOf' => [
                [
                    '$ref' => '#/components/schemas/Error',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'errors' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Message' => [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                ],
            ],
        ],
        'Health' => [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                ],
                'service' => [
                    'type' => 'string',
                ],
                'version' => [
                    'type' => 'string',
                ],
                'time' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'Readiness' => [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                ],
                'checks' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'ok' => [
                                'type' => 'boolean',
                            ],
                            'latency_ms' => [
                                'type' => 'number',
                            ],
                            'error' => [
                                'type' => [
                                    'string',
                                    'null',
                                ],
                            ],
                        ],
                    ],
                ],
                'time' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'PaginatedEnvelope' => [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [],
                ],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'last' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'prev' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'next' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => [
                            'type' => 'integer',
                        ],
                        'per_page' => [
                            'type' => 'integer',
                        ],
                        'total' => [
                            'type' => 'integer',
                        ],
                        'last_page' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
            ],
        ],
        'User' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'first_name' => [
                    'type' => 'string',
                ],
                'last_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'full_name' => [
                    'type' => 'string',
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'locale' => [
                    'type' => 'string',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'active',
                        'suspended',
                        'banned',
                    ],
                ],
                'email_verified' => [
                    'type' => 'boolean',
                ],
                'phone_verified' => [
                    'type' => 'boolean',
                ],
                'can_publish_listings' => [
                    'type' => 'boolean',
                ],
                'avatar_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'roles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'permissions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'AuthResult' => [
            'type' => 'object',
            'properties' => [
                'user' => [
                    '$ref' => '#/components/schemas/User',
                ],
                'token' => [
                    'type' => 'string',
                ],
                'token_type' => [
                    'type' => 'string',
                    'enum' => [
                        'Bearer',
                    ],
                ],
                'expires_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'OtpRequested' => [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                ],
                'expires_in_minutes' => [
                    'type' => 'integer',
                ],
                'resend_after_seconds' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'PhoneVerified' => [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                ],
                'user' => [
                    '$ref' => '#/components/schemas/User',
                ],
            ],
        ],
        'Price' => [
            'type' => 'object',
            'properties' => [
                'amount' => [
                    'type' => 'integer',
                ],
                'currency' => [
                    'type' => 'string',
                ],
                'unit' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'is_negotiable' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'ListingSummary' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'slug' => [
                    'type' => 'string',
                ],
                'title' => [
                    'type' => 'string',
                ],
                'price' => [
                    'oneOf' => [
                        [
                            '$ref' => '#/components/schemas/Price',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'purpose' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'condition' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'draft',
                        'pending_review',
                        'published',
                        'rejected',
                        'paused',
                        'expired',
                        'sold',
                        'archived',
                    ],
                ],
                'is_verified' => [
                    'type' => 'boolean',
                ],
                'is_featured' => [
                    'type' => 'boolean',
                ],
                'category' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => [
                            'type' => 'string',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                        'icon' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'parent' => [
                            'type' => [
                                'object',
                                'null',
                            ],
                            'description' => 'The vertical this listing belongs to ("Property"), as distinct from its leaf category ("Apartments"). Null when the listing is attached directly to a root category.',
                            'properties' => [
                                'slug' => [
                                    'type' => 'string',
                                ],
                                'name' => [
                                    'type' => 'string',
                                ],
                                'icon' => [
                                    'type' => [
                                        'string',
                                        'null',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'region_slug' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'district' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'district_slug' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'ward' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'ward_slug' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'address_line' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'latitude' => [
                            'type' => [
                                'number',
                                'null',
                            ],
                        ],
                        'longitude' => [
                            'type' => [
                                'number',
                                'null',
                            ],
                        ],
                    ],
                ],
                'distance_km' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'primary_image' => [
                    'oneOf' => [
                        [
                            '$ref' => '#/components/schemas/Media',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'stats' => [
                    'type' => 'object',
                    'properties' => [
                        'views' => [
                            'type' => 'integer',
                        ],
                        'favorites' => [
                            'type' => 'integer',
                        ],
                        'inquiries' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'published_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
                'attributes' => [
                    'type' => 'object',
                    'description' => 'The listing\'s EAV values as a flat `code => value` map, e.g. `{"beds": 2, "sqft": 1259}`.

Present on LIST responses as well as detail, so a results page can render bedrooms and area without fetching each listing individually. Read from the `search_document` projection on the row, so it costs no extra query.

Note the shape difference: the DETAIL response returns `attributes` as an ARRAY of objects carrying name, unit and label as well as the value.',
                    'additionalProperties' => true,
                    'examples' => [
                        [
                            'beds' => 2,
                            'bathrooms' => 2,
                            'sqft' => 1259,
                        ],
                    ],
                ],
            ],
        ],
        'Listing' => [
            'allOf' => [
                [
                    '$ref' => '#/components/schemas/ListingSummary',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'description' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'postal_code' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'available_from' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'expires_at' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'images' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/components/schemas/Media',
                            ],
                        ],
                        'attributes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'code' => [
                                        'type' => 'string',
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                    'unit' => [
                                        'type' => [
                                            'string',
                                            'null',
                                        ],
                                    ],
                                    'value' => [],
                                    'label' => [
                                        'type' => [
                                            'string',
                                            'null',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'amenities' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/components/schemas/Taxonomy',
                            ],
                        ],
                        'facilities' => [
                            'type' => 'array',
                            'items' => [
                                '$ref' => '#/components/schemas/Taxonomy',
                            ],
                        ],
                        'seller' => [
                            '$ref' => '#/components/schemas/SellerSummary',
                        ],
                        'rejection_reason' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'ListingStatus' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'status' => [
                    'type' => 'string',
                ],
                'published_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'expires_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'allowed_transitions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ],
        'Media' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'url' => [
                    'type' => 'string',
                ],
                'variants' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => [
                                'type' => 'string',
                            ],
                            'width' => [
                                'type' => [
                                    'integer',
                                    'null',
                                ],
                            ],
                            'height' => [
                                'type' => [
                                    'integer',
                                    'null',
                                ],
                            ],
                        ],
                    ],
                ],
                'alt_text' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'width' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
                'height' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
                'position' => [
                    'type' => 'integer',
                ],
                'is_primary' => [
                    'type' => 'boolean',
                ],
                'processing_status' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'complete',
                        'failed',
                    ],
                ],
            ],
        ],
        'Category' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'icon' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'description' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'depth' => [
                    'type' => 'integer',
                ],
                'is_leaf' => [
                    'type' => 'boolean',
                ],
                'listing_count' => [
                    'type' => 'integer',
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'children' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/Category',
                    ],
                ],
            ],
        ],
        'Attribute' => [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'input_type' => [
                    'type' => 'string',
                    'enum' => [
                        'text',
                        'number',
                        'select',
                        'multiselect',
                        'boolean',
                        'range',
                        'date',
                    ],
                ],
                'data_type' => [
                    'type' => 'string',
                    'enum' => [
                        'string',
                        'integer',
                        'decimal',
                        'boolean',
                        'date',
                    ],
                ],
                'unit' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'is_filterable' => [
                    'type' => 'boolean',
                ],
                'is_required' => [
                    'type' => 'boolean',
                ],
                'min_value' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'max_value' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'options' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => [
                                'type' => 'string',
                            ],
                            'label' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Location' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'latitude' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'longitude' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'listing_count' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'Taxonomy' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'icon' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'SellerSummary' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'display_name' => [
                    'type' => 'string',
                ],
                'slug' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'is_verified' => [
                    'type' => 'boolean',
                ],
                'verification_level' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'rating_avg' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'rating_count' => [
                    'type' => 'integer',
                ],
                'member_since' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'SellerProfile' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'display_name' => [
                    'type' => 'string',
                ],
                'bio' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'logo_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'whatsapp' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'website' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'is_verified' => [
                    'type' => 'boolean',
                ],
                'verification_level' => [
                    'type' => 'string',
                ],
                'rating_avg' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'rating_count' => [
                    'type' => 'integer',
                ],
                'active_listings' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'SellerDashboard' => [
            'type' => 'object',
            'properties' => [
                'listings' => [
                    'type' => 'object',
                    'properties' => [
                        'total' => [
                            'type' => 'integer',
                        ],
                        'active' => [
                            'type' => 'integer',
                        ],
                        'draft' => [
                            'type' => 'integer',
                        ],
                        'pending' => [
                            'type' => 'integer',
                        ],
                        'rejected' => [
                            'type' => 'integer',
                        ],
                        'paused' => [
                            'type' => 'integer',
                        ],
                        'sold' => [
                            'type' => 'integer',
                        ],
                        'expired' => [
                            'type' => 'integer',
                        ],
                        'archived' => [
                            'type' => 'integer',
                        ],
                        'by_status' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'engagement' => [
                    'type' => 'object',
                    'properties' => [
                        'total_views' => [
                            'type' => 'integer',
                        ],
                        'views_last_30_days' => [
                            'type' => 'integer',
                        ],
                        'total_favorites' => [
                            'type' => 'integer',
                        ],
                        'total_inquiries' => [
                            'type' => 'integer',
                        ],
                        'unread_inquiries' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'verification' => [
                    'type' => 'object',
                    'properties' => [
                        'phone_verified' => [
                            'type' => 'boolean',
                        ],
                        'email_verified' => [
                            'type' => 'boolean',
                        ],
                        'can_publish' => [
                            'type' => 'boolean',
                        ],
                        'seller_verified' => [
                            'type' => 'boolean',
                        ],
                        'verification_level' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'profile_completion' => [
                    'type' => 'object',
                    'properties' => [
                        'percent' => [
                            'type' => 'integer',
                        ],
                        'completed' => [
                            'type' => 'integer',
                        ],
                        'total' => [
                            'type' => 'integer',
                        ],
                        'checklist' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'boolean',
                            ],
                        ],
                        'missing' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Review' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'rating' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'title' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'body' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'approved',
                        'rejected',
                    ],
                ],
                'helpful_count' => [
                    'type' => 'integer',
                ],
                'reply' => [
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'body' => [
                                    'type' => 'string',
                                ],
                                'replied_at' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'reviewer' => [
                    'type' => 'object',
                    'properties' => [
                        'uuid' => [
                            'type' => 'string',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'Inquiry' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'first_name' => [
                    'type' => 'string',
                ],
                'last_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'email' => [
                    'type' => 'string',
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'message' => [
                    'type' => 'string',
                ],
                'source' => [
                    'type' => 'string',
                    'enum' => [
                        'listing',
                        'contact_page',
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'new',
                        'read',
                        'replied',
                        'spam',
                        'closed',
                    ],
                ],
                'reply' => [
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'body' => [
                                    'type' => 'string',
                                ],
                                'replied_at' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'read_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'PublicPlace' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'category' => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => [
                            'type' => 'string',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                        'icon' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                    ],
                ],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'district' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'address_line' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                        'latitude' => [
                            'type' => [
                                'number',
                                'null',
                            ],
                        ],
                        'longitude' => [
                            'type' => [
                                'number',
                                'null',
                            ],
                        ],
                    ],
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'website' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'PublicPlaceCategory' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'icon' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'place_count' => [
                    'type' => 'integer',
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'Faq' => [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                ],
                'answer' => [
                    'type' => 'string',
                ],
                'group' => [
                    'type' => 'string',
                ],
            ],
        ],
        'Page' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'title' => [
                    'type' => 'string',
                ],
                'body' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'meta_title' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'meta_description' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'published_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'FavoriteState' => [
            'type' => 'object',
            'properties' => [
                'favorited' => [
                    'type' => 'boolean',
                ],
                'created' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'RegisterRequest' => [
            'type' => 'object',
            'properties' => [
                'first_name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 100,
                ],
                'last_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'password' => [
                    'type' => 'string',
                    'minLength' => 8,
                ],
                'password_confirmation' => [
                    'type' => 'string',
                ],
                'device_name' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'first_name',
                'email',
                'password',
                'password_confirmation',
            ],
        ],
        'LoginRequest' => [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'password' => [
                    'type' => 'string',
                ],
                'device_name' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'email',
                'password',
            ],
        ],
        'GoogleSignInRequest' => [
            'type' => 'object',
            'properties' => [
                'id_token' => [
                    'type' => 'string',
                ],
                'device_name' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'id_token',
            ],
        ],
        'ForgotPasswordRequest' => [
            'type' => 'object',
            'properties' => [
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
            ],
            'required' => [
                'email',
            ],
        ],
        'ResetPasswordRequest' => [
            'type' => 'object',
            'properties' => [
                'token' => [
                    'type' => 'string',
                ],
                'email' => [
                    'type' => 'string',
                ],
                'password' => [
                    'type' => 'string',
                ],
                'password_confirmation' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'token',
                'email',
                'password',
                'password_confirmation',
            ],
        ],
        'RequestOtpRequest' => [
            'type' => 'object',
            'properties' => [
                'phone' => [
                    'type' => 'string',
                    'minLength' => 9,
                    'maxLength' => 20,
                ],
            ],
            'required' => [
                'phone',
            ],
        ],
        'VerifyOtpRequest' => [
            'type' => 'object',
            'properties' => [
                'phone' => [
                    'type' => 'string',
                ],
                'code' => [
                    'type' => 'string',
                    'pattern' => '^[0-9]{6}$',
                ],
            ],
            'required' => [
                'phone',
                'code',
            ],
        ],
        'UpdateProfileRequest' => [
            'type' => 'object',
            'properties' => [
                'first_name' => [
                    'type' => 'string',
                ],
                'last_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'locale' => [
                    'type' => 'string',
                    'enum' => [
                        'en',
                        'sw',
                    ],
                ],
            ],
        ],
        'UpdatePasswordRequest' => [
            'type' => 'object',
            'properties' => [
                'current_password' => [
                    'type' => 'string',
                ],
                'password' => [
                    'type' => 'string',
                ],
                'password_confirmation' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'current_password',
                'password',
                'password_confirmation',
            ],
        ],
        'UpdateSellerProfileRequest' => [
            'type' => 'object',
            'properties' => [
                'display_name' => [
                    'type' => 'string',
                ],
                'bio' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_reg_no' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'tin' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'whatsapp' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'website' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'StoreReviewRequest' => [
            'type' => 'object',
            'properties' => [
                'rating' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'title' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'body' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
            'required' => [
                'rating',
            ],
        ],
        'ReplyRequest' => [
            'type' => 'object',
            'properties' => [
                'body' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 2000,
                ],
            ],
            'required' => [
                'body',
            ],
        ],
        'StoreInquiryRequest' => [
            'type' => 'object',
            'properties' => [
                'listing_slug' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'first_name' => [
                    'type' => 'string',
                    'minLength' => 2,
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'message' => [
                    'type' => 'string',
                    'minLength' => 10,
                    'maxLength' => 2000,
                ],
            ],
            'required' => [
                'first_name',
                'email',
                'message',
            ],
        ],
        'StoreListingRequest' => [
            'type' => 'object',
            'description' => 'A category, a region and a district are all required — each given as either its id or its slug, which is why they are not listed under `required`.',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 10,
                    'maxLength' => 200,
                ],
                'description' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Required unless category_slug is sent.',
                ],
                'category_slug' => [
                    'type' => 'string',
                    'description' => 'The slug published by /categories. Resolved to category_id; use either.',
                ],
                'purpose' => [
                    'type' => 'string',
                    'enum' => [
                        'rent',
                        'sale',
                        'lease',
                    ],
                ],
                'price' => [
                    'type' => 'integer',
                ],
                'currency' => [
                    'type' => 'string',
                ],
                'price_unit' => [
                    'type' => 'string',
                ],
                'is_negotiable' => [
                    'type' => 'boolean',
                ],
                'condition' => [
                    'type' => 'string',
                    'enum' => [
                        'new',
                        'used',
                        'refurbished',
                    ],
                ],
                'region_id' => [
                    'type' => 'integer',
                    'description' => 'Required unless region_slug is sent.',
                ],
                'region_slug' => [
                    'type' => 'string',
                    'description' => 'The slug published by /locations/regions. Resolved to region_id; use either.',
                ],
                'district_id' => [
                    'type' => 'integer',
                    'description' => 'Required unless district_slug is sent.',
                ],
                'district_slug' => [
                    'type' => 'string',
                ],
                'ward_id' => [
                    'type' => 'integer',
                ],
                'ward_slug' => [
                    'type' => 'string',
                ],
                'address_line' => [
                    'type' => 'string',
                ],
                'postal_code' => [
                    'type' => 'string',
                ],
                'latitude' => [
                    'type' => 'number',
                ],
                'longitude' => [
                    'type' => 'number',
                ],
                'available_from' => [
                    'type' => 'string',
                    'format' => 'date',
                ],
                'attributes' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Category-specific values keyed by attribute code. Required attributes are enforced from the category.',
                ],
                'amenities' => [
                    'type' => 'array',
                    'description' => 'Amenity ids or the slugs published by /amenities.',
                    'items' => [
                        'type' => [
                            'integer',
                            'string',
                        ],
                    ],
                ],
                'facilities' => [
                    'type' => 'array',
                    'description' => 'Facility ids or the slugs published by /facilities.',
                    'items' => [
                        'type' => [
                            'integer',
                            'string',
                        ],
                    ],
                ],
            ],
            'required' => [
                'title',
            ],
        ],
        'AdminUser' => [
            'type' => 'object',
            'description' => 'The ADMIN view of a user. Deliberately narrower than the row: no password hash, no tokens, no remember_token.',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'first_name' => [
                    'type' => 'string',
                ],
                'last_name' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                ],
                'phone' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'active',
                        'pending',
                        'suspended',
                        'banned',
                    ],
                ],
                'locale' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'email_verified' => [
                    'type' => 'boolean',
                ],
                'phone_verified' => [
                    'type' => 'boolean',
                ],
                'roles' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'listings_count' => [
                    'type' => 'integer',
                ],
                'seller_profile' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'slug' => [
                            'type' => 'string',
                        ],
                        'display_name' => [
                            'type' => 'string',
                        ],
                        'is_verified' => [
                            'type' => 'boolean',
                        ],
                        'verification_level' => [
                            'type' => 'string',
                        ],
                        'rating_avg' => [
                            'type' => 'number',
                            'nullable' => true,
                        ],
                    ],
                ],
                'last_login_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'Role' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                ],
                'label' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'level' => [
                    'type' => 'integer',
                    'description' => 'Higher outranks lower.',
                ],
                'is_assignable' => [
                    'type' => 'boolean',
                    'description' => 'False for `super_admin`, which the API never grants.',
                ],
                'permissions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'users_count' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'VerificationRequest' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'format' => 'uuid',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'national_id',
                        'passport',
                        'business',
                        'address',
                    ],
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'approved',
                        'rejected',
                    ],
                ],
                'document_number' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'document_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'nullable' => true,
                    'description' => 'A SIGNED URL valid for 10 minutes. Identity documents live on a private disk and never get a permanent public link.',
                ],
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'uuid' => [
                            'type' => 'string',
                            'format' => 'uuid',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                        'email' => [
                            'type' => 'string',
                            'format' => 'email',
                        ],
                        'phone_verified' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'reviewed_by' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'reviewed_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                ],
                'rejection_reason' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'Setting' => [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                ],
                'value' => [
                    'description' => 'Any JSON value.',
                ],
                'group' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'is_public' => [
                    'type' => 'boolean',
                    'description' => 'Read-only here; whether the setting is world-readable.',
                ],
            ],
        ],
        'AdminFaq' => [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'question' => [
                    'type' => 'string',
                ],
                'answer' => [
                    'type' => 'string',
                ],
                'group' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'position' => [
                    'type' => 'integer',
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'AdminPage' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'title' => [
                    'type' => 'string',
                ],
                'body' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'meta_title' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'meta_description' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'is_published' => [
                    'type' => 'boolean',
                ],
                'published_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                ],
            ],
        ],
        'UpdateUserStatusRequest' => [
            'type' => 'object',
            'required' => [
                'status',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'active',
                        'pending',
                        'suspended',
                        'banned',
                    ],
                    'description' => '`banned` requires the `user.ban` permission; the rest require `user.suspend`.',
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                    'nullable' => true,
                ],
            ],
        ],
        'UpdateUserRolesRequest' => [
            'type' => 'object',
            'required' => [
                'roles',
            ],
            'properties' => [
                'roles' => [
                    'type' => 'array',
                    'maxItems' => 6,
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'buyer',
                            'seller',
                            'moderator',
                            'admin',
                        ],
                    ],
                    'description' => 'Replaces the existing set. `super_admin` is not accepted.',
                ],
            ],
        ],
        'StoreCategoryRequest' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'slug' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'nullable' => true,
                    'description' => 'Derived from the name when omitted.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Create only; ignored on update.',
                ],
                'icon' => [
                    'type' => 'string',
                    'maxLength' => 30,
                    'nullable' => true,
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 2000,
                    'nullable' => true,
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'meta_title' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'meta_description' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'nullable' => true,
                ],
            ],
            'required' => [
                'name',
            ],
        ],
        'StoreAttributeRequest' => [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'maxLength' => 60,
                    'pattern' => '^[a-z][a-z0-9_]*$',
                    'description' => 'Required on create, PROHIBITED on update — it is a public filter key.',
                ],
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'input_type' => [
                    'type' => 'string',
                    'enum' => [
                        'text',
                        'number',
                        'select',
                        'multiselect',
                        'boolean',
                        'date',
                    ],
                ],
                'data_type' => [
                    'type' => 'string',
                    'enum' => [
                        'string',
                        'integer',
                        'decimal',
                        'boolean',
                        'date',
                    ],
                    'description' => 'Decides which typed EAV column stores the value.',
                ],
                'unit' => [
                    'type' => 'string',
                    'maxLength' => 20,
                    'nullable' => true,
                ],
                'is_filterable' => [
                    'type' => 'boolean',
                ],
                'is_searchable' => [
                    'type' => 'boolean',
                ],
                'is_required' => [
                    'type' => 'boolean',
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'min_value' => [
                    'type' => 'number',
                    'nullable' => true,
                ],
                'max_value' => [
                    'type' => 'number',
                    'nullable' => true,
                ],
                'options' => [
                    'type' => 'array',
                    'maxItems' => 200,
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'label',
                        ],
                        'properties' => [
                            'value' => [
                                'type' => 'string',
                                'maxLength' => 120,
                                'description' => 'Slugged from the label when omitted.',
                            ],
                            'label' => [
                                'type' => 'string',
                                'maxLength' => 120,
                            ],
                            'position' => [
                                'type' => 'integer',
                                'minimum' => 0,
                            ],
                        ],
                    ],
                    'description' => 'Replaces the option set when present. Only meaningful for select/multiselect.',
                ],
            ],
            'required' => [
                'name',
                'input_type',
                'data_type',
            ],
        ],
        'StoreTaxonomyTermRequest' => [
            'type' => 'object',
            'required' => [
                'name',
            ],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'slug' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'nullable' => true,
                    'description' => 'Create only; immutable afterwards.',
                ],
                'icon' => [
                    'type' => 'string',
                    'maxLength' => 60,
                    'nullable' => true,
                ],
                'category_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Scopes the term to one vertical.',
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'FaqRequest' => [
            'type' => 'object',
            'required' => [
                'question',
                'answer',
            ],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'minLength' => 5,
                    'maxLength' => 500,
                ],
                'answer' => [
                    'type' => 'string',
                    'minLength' => 5,
                    'maxLength' => 5000,
                ],
                'group' => [
                    'type' => 'string',
                    'maxLength' => 50,
                    'nullable' => true,
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'AdminOverview' => [
            'type' => 'object',
            'properties' => [
                'users' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'integer',
                    ],
                ],
                'listings' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'integer',
                    ],
                ],
                'engagement' => [
                    'type' => 'object',
                ],
                'catalog' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'integer',
                    ],
                ],
                'verifications' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'integer',
                    ],
                ],
                'revenue' => [
                    'type' => 'object',
                    'properties' => [
                        'available' => [
                            'type' => 'boolean',
                            'description' => 'Always false until payments ship in v2.0.',
                        ],
                        'reason' => [
                            'type' => 'string',
                        ],
                    ],
                    'description' => 'Reported as UNAVAILABLE rather than as zero — "TZS 0" reads as a platform that has taken no money, rather than one that does not yet take money.',
                ],
            ],
        ],
        'DailyPoint' => [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'format' => 'date',
                ],
                'value' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'AdminGrowth' => [
            'type' => 'object',
            'description' => 'Every series has exactly one point per day in the range, including days with no activity.',
            'properties' => [
                'range' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => [
                            'type' => 'string',
                        ],
                        'to' => [
                            'type' => 'string',
                        ],
                        'days' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'listings' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'published_listings' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'users' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'vendors' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'inquiries' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'reviews' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'favorites' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
                'views' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPoint',
                    ],
                ],
            ],
        ],
        'CategoryPopularity' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                ],
                'slug' => [
                    'type' => 'string',
                ],
                'icon' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'listings' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'TopVendor' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'is_verified' => [
                    'type' => 'boolean',
                ],
                'listings' => [
                    'type' => 'integer',
                ],
                'views' => [
                    'type' => 'integer',
                ],
                'inquiries' => [
                    'type' => 'integer',
                ],
                'favorites' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'AuditEntry' => [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'action' => [
                    'type' => 'string',
                ],
                'actor' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'properties' => [
                        'uuid' => [
                            'type' => 'string',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                        'email' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'actor_label' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'description' => 'The actor email as recorded at the time. Denormalised so the entry survives deletion of the account — which is exactly when you need it.',
                ],
                'subject' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                        ],
                        'id' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'changes' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'description' => 'Attributes after the change. Credentials are never recorded.',
                ],
                'previous' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                ],
                'ip_address' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'request_id' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
            ],
        ],
        'AdminListingDetail' => [
            'allOf' => [
                [
                    '$ref' => '#/components/schemas/Listing',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'deleted_at' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                            'format' => 'date-time',
                        ],
                        'status_history' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'from' => [
                                        'type' => [
                                            'string',
                                            'null',
                                        ],
                                    ],
                                    'to' => [
                                        'type' => 'string',
                                    ],
                                    'reason' => [
                                        'type' => [
                                            'string',
                                            'null',
                                        ],
                                    ],
                                    'changed_by' => [
                                        'type' => [
                                            'integer',
                                            'null',
                                        ],
                                    ],
                                    'at' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'BulkResult' => [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                ],
                'succeeded' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'failed' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'uuid' => [
                                'type' => 'string',
                            ],
                            'reason' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'description' => 'Listings the action could not be applied to, each with why. The batch is NOT rolled back.',
                ],
                'summary' => [
                    'type' => 'object',
                    'properties' => [
                        'requested' => [
                            'type' => 'integer',
                        ],
                        'succeeded' => [
                            'type' => 'integer',
                        ],
                        'failed' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
            ],
        ],
        'Banner' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                ],
                'title' => [
                    'type' => 'string',
                ],
                'subtitle' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'link_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'link_label' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'placement' => [
                    'type' => 'string',
                ],
                'position' => [
                    'type' => 'integer',
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'starts_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'ends_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'image_media_id' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
                'is_live' => [
                    'type' => 'boolean',
                    'description' => 'Active AND inside its schedule. Distinct from is_active: a banner can be active but outside its window, and the list has to say why it is not showing.',
                ],
            ],
        ],
        'BannerRequest' => [
            'type' => 'object',
            'required' => [
                'title',
                'placement',
            ],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 191,
                ],
                'subtitle' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'link_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'maxLength' => 500,
                    'nullable' => true,
                    'description' => 'http or https only.',
                ],
                'link_label' => [
                    'type' => 'string',
                    'maxLength' => 60,
                    'nullable' => true,
                ],
                'image_media_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                ],
                'placement' => [
                    'type' => 'string',
                    'enum' => [
                        'hero',
                        'mid',
                        'footer',
                        'listings_top',
                        'sidebar',
                    ],
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'starts_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                ],
                'ends_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'nullable' => true,
                    'description' => 'Must be after starts_at.',
                ],
            ],
        ],
        'HomepageSection' => [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Binds the row to a frontend component. Read-only.',
                ],
                'title' => [
                    'type' => 'string',
                ],
                'subtitle' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'position' => [
                    'type' => 'integer',
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'item_limit' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
            ],
        ],
        'PlaceCategory' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'icon' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'position' => [
                    'type' => 'integer',
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'place_count' => [
                    'type' => 'integer',
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'PlaceCategoryRequest' => [
            'type' => 'object',
            'required' => [
                'name',
            ],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'icon' => [
                    'type' => 'string',
                    'maxLength' => 30,
                    'nullable' => true,
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'AdminPlace' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                ],
                'slug' => [
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'category' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                ],
                'region' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'district' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'address_line' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'latitude' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'longitude' => [
                    'type' => [
                        'number',
                        'null',
                    ],
                ],
                'phone' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'website' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'opening_hours' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'image_url' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'PlaceRequest' => [
            'type' => 'object',
            'required' => [
                'name',
                'public_place_category_id',
            ],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 191,
                ],
                'public_place_category_id' => [
                    'type' => 'integer',
                ],
                'description' => [
                    'type' => 'string',
                    'maxLength' => 2000,
                    'nullable' => true,
                ],
                'region_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                ],
                'district_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                ],
                'ward_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                ],
                'address_line' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'latitude' => [
                    'type' => 'number',
                    'minimum' => -90,
                    'maximum' => 90,
                    'nullable' => true,
                ],
                'longitude' => [
                    'type' => 'number',
                    'minimum' => -180,
                    'maximum' => 180,
                    'nullable' => true,
                ],
                'phone' => [
                    'type' => 'string',
                    'maxLength' => 20,
                    'nullable' => true,
                ],
                'website' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'maxLength' => 255,
                    'nullable' => true,
                    'description' => 'http or https only.',
                ],
                'opening_hours' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'nullable' => true,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
            ],
        ],
        'SystemInfo' => [
            'type' => 'object',
            'properties' => [
                'application' => [
                    'type' => 'object',
                ],
                'versions' => [
                    'type' => 'object',
                ],
                'drivers' => [
                    'type' => 'object',
                ],
                'queue' => [
                    'type' => 'object',
                ],
                'storage' => [
                    'type' => 'object',
                ],
            ],
        ],
        'BusinessType' => [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                ],
                'label' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => 'string',
                ],
                'category_slugs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Root categories to pre-filter the picker with. Empty means no filter.',
                ],
                'has_opening_hours' => [
                    'type' => 'boolean',
                    'description' => 'False for a landlord; true for a pharmacy. Drives whether the hours step exists at all.',
                ],
                'has_walk_in_address' => [
                    'type' => 'boolean',
                    'description' => 'False where the vendor works at the customer\'s location.',
                ],
                'expects_registration_number' => [
                    'type' => 'boolean',
                ],
                'listing_noun' => [
                    'type' => 'object',
                    'properties' => [
                        'singular' => [
                            'type' => 'string',
                        ],
                        'plural' => [
                            'type' => 'string',
                        ],
                    ],
                    'description' => 'What this trade calls a listing — rooms, vehicles, properties.',
                ],
            ],
        ],
        'VendorProfile' => [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                ],
                'display_name' => [
                    'type' => 'string',
                ],
                'business_name' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_type' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'bio' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'business_reg_no' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'tin' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'location' => [
                    'type' => 'object',
                ],
                'contact' => [
                    'type' => 'object',
                ],
                'branding' => [
                    'type' => 'object',
                ],
                'opening_hours' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                    'description' => 'Null means never configured; an empty array for a day means closed that day. Different things.',
                ],
                'social_links' => [
                    'type' => [
                        'object',
                        'null',
                    ],
                ],
                'verification' => [
                    'type' => 'object',
                ],
                'stats' => [
                    'type' => 'object',
                ],
                'onboarding_completed_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
        ],
        'VendorProfileRequest' => [
            'type' => 'object',
            'properties' => [
                'display_name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 120,
                ],
                'business_name' => [
                    'type' => 'string',
                    'maxLength' => 191,
                    'nullable' => true,
                ],
                'business_type' => [
                    'type' => 'string',
                ],
                'bio' => [
                    'type' => 'string',
                    'maxLength' => 2000,
                    'nullable' => true,
                ],
                'business_reg_no' => [
                    'type' => 'string',
                    'maxLength' => 60,
                    'nullable' => true,
                ],
                'tin' => [
                    'type' => 'string',
                    'maxLength' => 40,
                    'nullable' => true,
                ],
                'region_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                ],
                'region_slug' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'The slug published by /locations/regions. Resolved to region_id; use either.',
                ],
                'district_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Must belong to region_id.',
                ],
                'district_slug' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'ward_id' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'description' => 'Must belong to district_id.',
                ],
                'ward_slug' => [
                    'type' => 'string',
                    'nullable' => true,
                ],
                'street' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'latitude' => [
                    'type' => 'number',
                    'nullable' => true,
                    'description' => 'Must be sent with longitude.',
                ],
                'longitude' => [
                    'type' => 'number',
                    'nullable' => true,
                ],
                'public_email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'nullable' => true,
                ],
                'public_phone' => [
                    'type' => 'string',
                    'maxLength' => 20,
                    'nullable' => true,
                ],
                'whatsapp' => [
                    'type' => 'string',
                    'maxLength' => 20,
                    'nullable' => true,
                ],
                'website' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'nullable' => true,
                    'description' => 'http or https only.',
                ],
                'opening_hours' => [
                    'type' => 'object',
                    'nullable' => true,
                    'description' => 'Day => list of {open, close} in HH:MM. Ranges must not overlap and close must be after open. An empty list means closed that day.',
                ],
                'social_links' => [
                    'type' => 'object',
                    'nullable' => true,
                    'additionalProperties' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                ],
            ],
        ],
        'BrandingUpload' => [
            'type' => 'object',
            'properties' => [
                'kind' => [
                    'type' => 'string',
                ],
                'url' => [
                    'type' => 'string',
                ],
                'uuid' => [
                    'type' => 'string',
                ],
            ],
        ],
        'DailyPointVendor' => [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'format' => 'date',
                ],
                'value' => [
                    'type' => 'integer',
                ],
            ],
        ],
        'VendorAnalytics' => [
            'type' => 'object',
            'description' => 'Every series has exactly one point per day in the range.',
            'properties' => [
                'range' => [
                    'type' => 'object',
                ],
                'views' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPointVendor',
                    ],
                ],
                'favorites' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPointVendor',
                    ],
                ],
                'inquiries' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPointVendor',
                    ],
                ],
                'reviews' => [
                    'type' => 'array',
                    'items' => [
                        '$ref' => '#/components/schemas/DailyPointVendor',
                    ],
                ],
            ],
        ],
        'VendorVerification' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                ],
                'type' => [
                    'type' => 'string',
                ],
                'status' => [
                    'type' => 'string',
                ],
                'document_number' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'reviewed_at' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
                'reviewer_note' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'description' => 'Rejection reason, or what a reviewer asked for when requesting more information.',
                ],
                'created_at' => [
                    'type' => 'string',
                ],
            ],
        ],
        'InquiryStatus' => [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                ],
                'status' => [
                    'type' => 'string',
                ],
            ],
        ],
    ],
    'responses' => [
        'Unauthenticated' => [
            'description' => 'Missing or invalid token (`UNAUTHENTICATED`).',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'Forbidden' => [
            'description' => 'Authenticated but not permitted (`FORBIDDEN`).',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'NotFound' => [
            'description' => 'Not found, or not visible to this caller (`NOT_FOUND`). Returned instead of 403 so existence is never disclosed.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'Conflict' => [
            'description' => 'Conflicts with current state (`CONFLICT`, `PHONE_ALREADY_REGISTERED`, ...).',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'InvalidTransition' => [
            'description' => 'The requested status change is not allowed from the current state (`INVALID_STATE_TRANSITION`). `error.details.allowed` lists the permitted targets.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'InvalidCredentials' => [
            'description' => 'Credentials rejected (`INVALID_CREDENTIALS`). Identical for an unknown account and a wrong password.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'AccountBlocked' => [
            'description' => 'Account suspended or banned (`ACCOUNT_SUSPENDED`, `ACCOUNT_BANNED`).',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'ValidationFailed' => [
            'description' => 'Validation failed (`VALIDATION_FAILED`). Carries an `errors` map.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/ValidationError',
                    ],
                ],
            ],
        ],
        'RateLimited' => [
            'description' => 'Rate limit exceeded (`RATE_LIMITED`). See `Retry-After`.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
        'ServerError' => [
            'description' => 'Unexpected error (`SERVER_ERROR`). No internal detail is disclosed.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ],
    ],
];

const OPENAPI_OPERATIONS = [
    'api.v1.businesses.index' => [
        'tag' => 'Businesses',
        'summary' => 'Search, browse and find businesses near a point',
        'description' => 'Keyword search, trade filter and radius search in one endpoint, because a map needs all three from a single call — panning is a radius search, typing is a keyword search, and the two combine.

Only businesses that have COMPLETED onboarding, or have at least one publicly visible listing, appear here. A profile row is created the moment a vendor opens the portal, so "has a profile" is not the same as "is a business".

When `lat`/`lng`/`radius` are given, each result carries `distance_km` and the default sort is nearest-first.',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Matches display name, business name and bio.',
            ],
            [
                'name' => 'business_type',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'One of the values from /business-types.',
            ],
            [
                'name' => 'region',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'district',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'verified',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
            ],
            [
                'name' => 'featured',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Verified businesses that currently have live listings.',
            ],
            [
                'name' => 'lat',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'number',
                ],
                'description' => 'Centre latitude. Required with radius.',
            ],
            [
                'name' => 'lng',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'number',
                ],
                'description' => 'Centre longitude. Required with radius.',
            ],
            [
                'name' => 'radius',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'number',
                    'minimum' => 0.1,
                    'maximum' => 500,
                ],
                'description' => 'Kilometres, 0.1–500. Capped so a geo search cannot become a full scan.',
            ],
            [
                'name' => 'sort',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'relevance | rating | listings | newest | distance',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Business',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.businesses.show' => [
        'tag' => 'Businesses',
        'summary' => 'One business, in full',
        'description' => 'The public view of a seller profile: contact details, opening hours, social links, verification and rating. Registration number and TIN are absent by construction — they belong to the owner\'s own view, not this one.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/BusinessDetail',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.businesses.listings' => [
        'tag' => 'Businesses',
        'summary' => 'A business\'s own listings',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Listing',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.businesses.reviews' => [
        'tag' => 'Businesses',
        'summary' => 'Approved reviews about a business',
        'description' => 'Across all of its listings. Only approved reviews are public.',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Review',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.businesses.similar' => [
        'tag' => 'Businesses',
        'summary' => 'Businesses a customer would also consider',
        'description' => 'Same trade first, then nearest, then best rated. Deliberately not "customers also viewed" — there is no view history on businesses, and inventing one would rank by id.',
        'query' => [
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 24,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Business',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.search.suggestions' => [
        'tag' => 'Search',
        'summary' => 'Type-ahead suggestions',
        'description' => 'Drawn from four sources — listings, businesses, categories and places — because someone typing "masaki" means a neighbourhood and someone typing "toyota" means stock. Returning only listing titles makes the box feel broken for half of what people search for.',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'At least 2 characters.',
            ],
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
                'description' => 'Per source, not in total.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'listings' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/SearchSuggestion',
                                            ],
                                        ],
                                        'businesses' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/SearchSuggestion',
                                            ],
                                        ],
                                        'categories' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/SearchSuggestion',
                                            ],
                                        ],
                                        'places' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/SearchSuggestion',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.search.popular' => [
        'tag' => 'Search',
        'summary' => 'What everyone is searching for',
        'description' => 'Recorded searches from the last 30 days that actually FOUND something — suggesting a query that returns an empty page is worse than suggesting nothing. Cached hourly.',
        'query' => [
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'query' => [
                                                'type' => 'string',
                                            ],
                                            'searches' => [
                                                'type' => 'integer',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.favorites.listings' => [
        'tag' => 'Account',
        'summary' => 'Saved listings',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Listing',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.favorites.businesses' => [
        'tag' => 'Account',
        'summary' => 'Saved businesses',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Business',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.favorites.history' => [
        'tag' => 'Account',
        'summary' => 'Everything ever saved, including what was removed',
        'description' => 'Un-saving stamps `removed_at` rather than deleting the row, which is what makes "I saved a flat last month and can\'t find it again" answerable. `target` is null when the listing or business has since been removed.',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/FavoriteHistoryEntry',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.favorites.businesses.store' => [
        'tag' => 'Account',
        'summary' => 'Save a business',
        'description' => 'Idempotent: `created` is false when it was already saved.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'favorited' => [
                                            'type' => 'boolean',
                                        ],
                                        'created' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.favorites.businesses.destroy' => [
        'tag' => 'Account',
        'summary' => 'Un-save a business',
        'description' => 'Keeps the row and stamps `removed_at`, so it stays in favourite history.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'favorited' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.reviews.update' => [
        'tag' => 'Account',
        'summary' => 'Edit a review you wrote',
        'description' => 'An edited APPROVED review goes straight back to pending when moderation is on — otherwise a review could be published as something innocuous and rewritten into abuse afterwards. `meta.pending_remoderation` says whether that happened.

The seller\'s reply is left in place: removing their answer because the customer fixed a typo would be worse than the mismatch.',
        'body' => [
            'type' => 'object',
            'properties' => [
                'rating' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'title' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'maxLength' => 200,
                ],
                'body' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'maxLength' => 2000,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Review',
                                ],
                                'meta' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'pending_remoderation' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '403' => [
                '$ref' => '#/components/responses/Forbidden',
            ],
        ],
    ],
    'api.v1.account.reviews.report' => [
        'tag' => 'Account',
        'summary' => 'Report someone else\'s review',
        'description' => 'Does NOT hide the review — reporting is a request for a moderator to look, not a way to remove criticism. Reporting your own review is a 409: edit or delete it instead.',
        'body' => [
            'type' => 'object',
            'required' => [
                'reason',
                'details',
            ],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => [
                        'spam',
                        'offensive',
                        'false_information',
                        'personal_information',
                        'other',
                    ],
                ],
                'details' => [
                    'type' => 'string',
                    'minLength' => 10,
                    'maxLength' => 1000,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Reported',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.account.inquiries.index' => [
        'tag' => 'Account',
        'summary' => 'Inquiries you have sent',
        'description' => 'Scoped by `sender_user_id`, so a message sent while signed out is not listed. Matching on email address instead would let anyone claim another person\'s history by registering their address.',
        'query' => [
            [
                'name' => 'status',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'new | read | replied | closed',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Inquiry',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.inquiries.show' => [
        'tag' => 'Account',
        'summary' => 'One inquiry, with its timeline',
        'description' => 'The timeline is DERIVED from the row\'s own timestamps rather than stored as events. An inquiry is one message and at most one reply, so a fabricated event log would imply a conversation that cannot happen: it reports only when it was sent, seen, answered and resolved.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Inquiry',
                                ],
                                'meta' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'timeline' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'event' => [
                                                        'type' => 'string',
                                                    ],
                                                    'label' => [
                                                        'type' => 'string',
                                                    ],
                                                    'at' => [
                                                        'type' => [
                                                            'string',
                                                            'null',
                                                        ],
                                                        'format' => 'date-time',
                                                    ],
                                                ],
                                            ],
                                        ],
                                        'business' => [
                                            'type' => [
                                                'object',
                                                'null',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.notifications.index' => [
        'tag' => 'Account',
        'summary' => 'The notification centre',
        'description' => '`meta.unread_count` rides on every page so the bell badge never needs a second request and can never disagree with the list beneath it.',
        'query' => [
            [
                'name' => 'unread',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
            ],
            [
                'name' => 'type',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Notification',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.notifications.unread' => [
        'tag' => 'Account',
        'summary' => 'Unread notification count',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'unread_count' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.notifications.preferences' => [
        'tag' => 'Account',
        'summary' => 'Notification preferences',
        'description' => 'Four coarse switches rather than one per notification type — a customer cannot meaningfully choose between "price dropped" and "back on sale". Moderation outcomes have no switch: being told your review was rejected is not marketing, and silencing it would make the removal look like content vanishing for no reason.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'key' => [
                                                'type' => 'string',
                                            ],
                                            'enabled' => [
                                                'type' => 'boolean',
                                            ],
                                            'default' => [
                                                'type' => 'boolean',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.notifications.preferences.update' => [
        'tag' => 'Account',
        'summary' => 'Change notification preferences',
        'description' => 'Merged, not replaced: sending one switch must not silently reset the others to their defaults.',
        'body' => [
            'type' => 'object',
            'required' => [
                'preferences',
            ],
            'properties' => [
                'preferences' => [
                    'type' => 'object',
                    'properties' => [
                        'favorite_alerts' => [
                            'type' => 'boolean',
                        ],
                        'inquiry_replies' => [
                            'type' => 'boolean',
                        ],
                        'review_replies' => [
                            'type' => 'boolean',
                        ],
                        'listing_updates' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.notifications.read_all' => [
        'tag' => 'Account',
        'summary' => 'Mark every notification read',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'marked' => [
                                            'type' => 'integer',
                                        ],
                                        'unread_count' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.notifications.read' => [
        'tag' => 'Account',
        'summary' => 'Mark one notification read',
        'description' => 'Scoped to the caller — 404 for anyone else\'s notification, so a uuid cannot be marked read on someone\'s behalf.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'read' => [
                                            'type' => 'boolean',
                                        ],
                                        'unread_count' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.notifications.destroy' => [
        'tag' => 'Account',
        'summary' => 'Dismiss a notification',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.activity.recently_viewed' => [
        'tag' => 'Account',
        'summary' => 'Listings you looked at recently',
        'description' => 'Read from `listing_views`, which already records who viewed what. A second table would be the same data written twice and would disagree with the view counters the moment one of them failed.',
        'query' => [
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 40,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Listing',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.activity.search_history' => [
        'tag' => 'Account',
        'summary' => 'Your recent searches',
        'description' => 'Deduplicated, most recent first. `query` is what you actually typed, not the normalised form used for grouping.',
        'query' => [
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'query' => [
                                                'type' => 'string',
                                            ],
                                            'results_count' => [
                                                'type' => 'integer',
                                            ],
                                            'searched_at' => [
                                                'type' => 'string',
                                                'format' => 'date-time',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.activity.search_history.clear' => [
        'tag' => 'Account',
        'summary' => 'Clear your search history',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'cleared' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.avatar.store' => [
        'tag' => 'Account',
        'summary' => 'Upload or replace your avatar',
        'description' => 'Multipart, and separate from the profile PATCH because it goes through the media pipeline — validation, EXIF stripping, variant generation. The previous avatar is deleted so iterating on a photo does not accumulate dead files.',
        'bodyType' => 'multipart/form-data',
        'body' => [
            'type' => 'object',
            'required' => [
                'avatar',
            ],
            'properties' => [
                'avatar' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/User',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.avatar.destroy' => [
        'tag' => 'Account',
        'summary' => 'Remove your avatar',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/User',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.account.destroy' => [
        'tag' => 'Account',
        'summary' => 'Close your account',
        'description' => 'A SOFT delete. Hard-deleting would cascade through listings, reviews and inquiries — taking with it reviews other people rely on and inquiries a business still has open. Instead this releases the email and phone so the person can register again, revokes every token, and archives live listings so nothing stays on sale with no one behind it.

The password is re-checked even though the caller is authenticated: a stolen session must not be enough to destroy an account.',
        'body' => [
            'type' => 'object',
            'properties' => [
                'password' => [
                    'type' => 'string',
                    'description' => 'Required unless the account has no password (OAuth-only).',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Closed',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '401' => [
                '$ref' => '#/components/responses/Unauthorized',
            ],
        ],
    ],
    'api.v1.health' => [
        'tag' => 'System',
        'summary' => 'Liveness probe (alias of /health/live)',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Health',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.health.live' => [
        'tag' => 'System',
        'summary' => 'Liveness probe',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Health',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Touches no dependency. A failing database must not cause the orchestrator to restart healthy containers.',
    ],
    'api.v1.health.ready' => [
        'tag' => 'System',
        'summary' => 'Readiness probe',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Readiness',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '503' => [
                'description' => 'One or more dependencies are unavailable',
            ],
        ],
        'description' => 'Checks database, cache, Redis and queue connectivity.',
    ],
    'api.v1.metrics' => [
        'tag' => 'System',
        'summary' => 'Prometheus metrics',
        'responses' => [
            '200' => [
                'description' => 'Prometheus text exposition format',
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Requires the `X-Metrics-Token` header. Returns 404 when the token is absent or wrong, so the endpoint is indistinguishable from a non-existent route.',
    ],
    'api.v1.auth.register' => [
        'tag' => 'Authentication',
        'summary' => 'Register with email and password',
        'responses' => [
            '201' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AuthResult',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            '$ref' => '#/components/schemas/RegisterRequest',
        ],
    ],
    'api.v1.auth.login' => [
        'tag' => 'Authentication',
        'summary' => 'Sign in',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AuthResult',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '401' => [
                '$ref' => '#/components/responses/InvalidCredentials',
            ],
            '403' => [
                '$ref' => '#/components/responses/AccountBlocked',
            ],
        ],
        'description' => 'An unknown email and a wrong password return byte-identical responses to prevent account enumeration.',
        'body' => [
            '$ref' => '#/components/schemas/LoginRequest',
        ],
    ],
    'api.v1.auth.oauth.google' => [
        'tag' => 'Authentication',
        'summary' => 'Sign in with Google',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AuthResult',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '401' => [
                '$ref' => '#/components/responses/InvalidCredentials',
            ],
        ],
        'description' => 'The `id_token` is verified server-side against Google\'s JWKS. Handles both sign-up and sign-in.',
        'body' => [
            '$ref' => '#/components/schemas/GoogleSignInRequest',
        ],
    ],
    'api.v1.auth.password.forgot' => [
        'tag' => 'Authentication',
        'summary' => 'Request a password reset link',
        'responses' => [
            '202' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Always returns 202 whether or not the address is registered.',
        'body' => [
            '$ref' => '#/components/schemas/ForgotPasswordRequest',
        ],
    ],
    'api.v1.auth.password.reset' => [
        'tag' => 'Authentication',
        'summary' => 'Reset a password with a token',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Revokes every existing token for the account.',
        'body' => [
            '$ref' => '#/components/schemas/ResetPasswordRequest',
        ],
    ],
    'api.v1.auth.me' => [
        'tag' => 'Authentication',
        'summary' => 'Current user',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/User',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.auth.refresh' => [
        'tag' => 'Authentication',
        'summary' => 'Rotate the access token',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AuthResult',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.auth.logout' => [
        'tag' => 'Authentication',
        'summary' => 'Revoke the current token',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.auth.logout.all' => [
        'tag' => 'Authentication',
        'summary' => 'Revoke every token',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.auth.phone.request' => [
        'tag' => 'Authentication',
        'summary' => 'Send a phone verification code',
        'responses' => [
            '202' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/OtpRequested',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
        'description' => 'Codes are stored hashed. Requesting a new code invalidates the previous one.',
        'body' => [
            '$ref' => '#/components/schemas/RequestOtpRequest',
        ],
    ],
    'api.v1.auth.phone.verify' => [
        'tag' => 'Authentication',
        'summary' => 'Verify a phone code',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/PhoneVerified',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'A verified phone is required before a seller may publish a listing.',
        'body' => [
            '$ref' => '#/components/schemas/VerifyOtpRequest',
        ],
    ],
    'api.v1.account.profile.show' => [
        'tag' => 'Account',
        'summary' => 'Read own profile',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/User',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.profile.update' => [
        'tag' => 'Account',
        'summary' => 'Update own profile',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/User',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Changing the email clears its verified state.',
        'body' => [
            '$ref' => '#/components/schemas/UpdateProfileRequest',
        ],
    ],
    'api.v1.account.password.update' => [
        'tag' => 'Account',
        'summary' => 'Change password',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Requires the current password. Revokes all other sessions.',
        'body' => [
            '$ref' => '#/components/schemas/UpdatePasswordRequest',
        ],
    ],
    'api.v1.account.favorites.index' => [
        'tag' => 'Favorites',
        'summary' => 'List favorited listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/ListingSummary',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.favorites.store' => [
        'tag' => 'Favorites',
        'summary' => 'Favorite a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/FavoriteState',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Idempotent. Returns 404 for a listing the caller cannot see.',
    ],
    'api.v1.account.favorites.destroy' => [
        'tag' => 'Favorites',
        'summary' => 'Remove a favorite',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/FavoriteState',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.reviews.mine' => [
        'tag' => 'Reviews',
        'summary' => 'Reviews written by the current user',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Review',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.reviews.store' => [
        'tag' => 'Reviews',
        'summary' => 'Review a listing',
        'responses' => [
            '201' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Review',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
        'description' => 'One review per user per listing. Awaits moderation before it affects the seller rating.',
        'body' => [
            '$ref' => '#/components/schemas/StoreReviewRequest',
        ],
    ],
    'api.v1.account.reviews.destroy' => [
        'tag' => 'Reviews',
        'summary' => 'Delete own review',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.account.reviews.reply' => [
        'tag' => 'Reviews',
        'summary' => 'Seller replies to a review',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Review',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'One public response per review.',
        'body' => [
            '$ref' => '#/components/schemas/ReplyRequest',
        ],
    ],
    'api.v1.listings.index' => [
        'tag' => 'Listings',
        'summary' => 'Browse, search and filter listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/ListingSummary',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Open to guests. Supports offset pagination (`page`) and cursor pagination (`cursor`).',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                    'maxLength' => 200,
                ],
                'description' => 'Full-text keyword search.',
            ],
            [
                'name' => 'category',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Category slug; includes the whole subtree.',
            ],
            [
                'name' => 'subcategory',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'region',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'district',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'ward',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'place',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'maxLength' => 120,
                ],
                'description' => 'Free-text place search, for a single "where?" input. Prefix-matches a ward, district or region name, and also the seller-typed address line — so "Masaki" (a ward), "Ilala" (a district) and "Posta" (neither, just an address) all work without the caller knowing which is which. Ignored when a more specific `ward`, `district` or `region` slug is supplied.',
            ],
            [
                'name' => 'min_price',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'description' => 'Minor units.',
            ],
            [
                'name' => 'max_price',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
            ],
            [
                'name' => 'purpose',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'rent',
                        'sale',
                        'lease',
                    ],
                ],
            ],
            [
                'name' => 'condition',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'new',
                        'used',
                        'refurbished',
                    ],
                ],
            ],
            [
                'name' => 'verified',
                'in' => 'query',
                'schema' => [
                    'type' => 'boolean',
                ],
            ],
            [
                'name' => 'featured',
                'in' => 'query',
                'schema' => [
                    'type' => 'boolean',
                ],
            ],
            [
                'name' => 'amenities',
                'in' => 'query',
                'style' => 'form',
                'explode' => true,
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'description' => 'Amenity slugs, AND-combined.',
            ],
            [
                'name' => 'facilities',
                'in' => 'query',
                'style' => 'form',
                'explode' => true,
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'name' => 'attributes',
                'in' => 'query',
                'style' => 'deepObject',
                'explode' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'description' => 'Category-specific EAV filters, e.g. attributes[beds][min]=2 or attributes[fuel_type]=diesel.',
            ],
            [
                'name' => 'lat',
                'in' => 'query',
                'schema' => [
                    'type' => 'number',
                ],
                'description' => 'Required with radius.',
            ],
            [
                'name' => 'lng',
                'in' => 'query',
                'schema' => [
                    'type' => 'number',
                ],
            ],
            [
                'name' => 'radius',
                'in' => 'query',
                'schema' => [
                    'type' => 'number',
                    'minimum' => 0.1,
                    'maximum' => 500,
                ],
                'description' => 'Kilometres. Capped so the bounding-box prefilter stays effective.',
            ],
            [
                'name' => 'sort',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'newest',
                        'oldest',
                        'price_asc',
                        'price_desc',
                        'popularity',
                        'distance',
                        'relevance',
                    ],
                ],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            [
                'name' => 'page',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
            ],
            [
                'name' => 'cursor',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
        ],
    ],
    'api.v1.listings.show' => [
        'tag' => 'Listings',
        'summary' => 'Listing detail',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Listing',
                                ],
                                'meta' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'is_favorited' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Records a view asynchronously, deduplicated per client per day.',
    ],
    'api.v1.listings.similar' => [
        'tag' => 'Listings',
        'summary' => 'Similar listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/ListingSummary',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.listings.trending' => [
        'tag' => 'Listings',
        'summary' => 'Trending listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/ListingSummary',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.listings.featured' => [
        'tag' => 'Listings',
        'summary' => 'Featured listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/ListingSummary',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.listings.recommended' => [
        'tag' => 'Listings',
        'summary' => 'Recommended listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/ListingSummary',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Personalised from favourited categories when authenticated; popularity-ranked otherwise.',
    ],
    'api.v1.listings.reviews' => [
        'tag' => 'Reviews',
        'summary' => 'Approved reviews for a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Review',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.categories.index' => [
        'tag' => 'Catalog',
        'summary' => 'Category tree',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Category',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.categories.show' => [
        'tag' => 'Catalog',
        'summary' => 'Category detail',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Category',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.categories.attributes' => [
        'tag' => 'Catalog',
        'summary' => 'Attributes for a category',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Attribute',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Includes attributes inherited from ancestor categories. Drives the dynamic filter UI.',
    ],
    'api.v1.locations.regions' => [
        'tag' => 'Catalog',
        'summary' => 'Regions',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Location',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.locations.districts' => [
        'tag' => 'Catalog',
        'summary' => 'Districts in a region',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Location',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.locations.wards' => [
        'tag' => 'Catalog',
        'summary' => 'Wards in a district',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Location',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.amenities.index' => [
        'tag' => 'Catalog',
        'summary' => 'Amenities',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Taxonomy',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.facilities.index' => [
        'tag' => 'Catalog',
        'summary' => 'Facilities',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Taxonomy',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.places.index' => [
        'tag' => 'Public places',
        'summary' => 'Browse public places',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/PublicPlace',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'query' => [
            [
                'name' => 'category',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'region',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'q',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
    ],
    'api.v1.places.categories' => [
        'tag' => 'Public places',
        'summary' => 'Public place categories',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/PublicPlaceCategory',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.places.show' => [
        'tag' => 'Public places',
        'summary' => 'Public place detail',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/PublicPlace',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.faqs' => [
        'tag' => 'Content',
        'summary' => 'FAQs',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Faq',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.pages.show' => [
        'tag' => 'Content',
        'summary' => 'CMS page',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Page',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Unpublished pages return 404. Terms and Privacy are unpublished pending legal copy.',
    ],
    'api.v1.settings.public' => [
        'tag' => 'Content',
        'summary' => 'Public settings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.inquiries.store' => [
        'tag' => 'Inquiries',
        'summary' => 'Send an inquiry',
        'responses' => [
            '201' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Inquiry',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
        'description' => 'Serves both \'Contact seller\' (with `listing_slug`) and the general contact form. Guests may submit. Includes a honeypot field.',
        'body' => [
            '$ref' => '#/components/schemas/StoreInquiryRequest',
        ],
    ],
    'api.v1.seller.dashboard' => [
        'tag' => 'Seller',
        'summary' => 'Seller dashboard',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/SellerDashboard',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.profile.show' => [
        'tag' => 'Seller',
        'summary' => 'Read seller profile',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/SellerProfile',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.profile.update' => [
        'tag' => 'Seller',
        'summary' => 'Update seller profile',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/SellerProfile',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'body' => [
            '$ref' => '#/components/schemas/UpdateSellerProfileRequest',
        ],
    ],
    'api.v1.seller.listings.index' => [
        'tag' => 'Seller',
        'summary' => 'List own listings',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/ListingSummary',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.listings.store' => [
        'tag' => 'Seller',
        'summary' => 'Create a listing (draft)',
        'responses' => [
            '201' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Listing',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Created as a draft. Required EAV attributes are enforced from the category. Grants the seller role on first listing.',
        'body' => [
            '$ref' => '#/components/schemas/StoreListingRequest',
        ],
    ],
    'api.v1.seller.listings.show' => [
        'tag' => 'Seller',
        'summary' => 'Read own listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Listing',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Returns 404, not 403, for a listing the caller does not own.',
    ],
    'api.v1.seller.listings.update' => [
        'tag' => 'Seller',
        'summary' => 'Update own listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Listing',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Editing a published listing returns it to pending review.',
        'body' => [
            '$ref' => '#/components/schemas/StoreListingRequest',
        ],
    ],
    'api.v1.seller.listings.destroy' => [
        'tag' => 'Seller',
        'summary' => 'Delete own listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Soft delete; inquiries and history are retained.',
    ],
    'api.v1.seller.listings.submit' => [
        'tag' => 'Seller',
        'summary' => 'Submit a listing for review',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
        'description' => 'Requires a verified phone and at least one image.',
    ],
    'api.v1.seller.listings.pause' => [
        'tag' => 'Seller',
        'summary' => 'Pause a published listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
    ],
    'api.v1.seller.listings.resume' => [
        'tag' => 'Seller',
        'summary' => 'Resume a paused listing',
        'description' => 'Only from `paused`. The status machine also allows Pending review -> Published, because that is how a moderator approves — so this endpoint refuses anything but a paused listing, otherwise a seller could publish their own listing while it awaited review.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
        'description' => 'Requires a verified phone.',
    ],
    'api.v1.seller.listings.sold' => [
        'tag' => 'Seller',
        'summary' => 'Mark a listing sold',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
    ],
    'api.v1.seller.listings.archive' => [
        'tag' => 'Seller',
        'summary' => 'Archive a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
    ],
    'api.v1.seller.media.store' => [
        'tag' => 'Media',
        'summary' => 'Upload a listing image',
        'responses' => [
            '201' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Media',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'MIME is derived from magic bytes, not the filename. SVG is refused. Variants are generated asynchronously.',
        'body' => [
            'type' => 'object',
            'required' => [
                'image',
            ],
            'properties' => [
                'image' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
                'alt_text' => [
                    'type' => 'string',
                    'maxLength' => 255,
                ],
            ],
        ],
        'bodyType' => 'multipart/form-data',
    ],
    'api.v1.seller.media.reorder' => [
        'tag' => 'Media',
        'summary' => 'Reorder listing images',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Media',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            'type' => 'object',
            'required' => [
                'order',
            ],
            'properties' => [
                'order' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'format' => 'uuid',
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.media.destroy' => [
        'tag' => 'Media',
        'summary' => 'Delete a listing image',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Deleting the primary image promotes the next one.',
    ],
    'api.v1.seller.media.primary' => [
        'tag' => 'Media',
        'summary' => 'Set the primary image',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Media',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.seller.media.replace' => [
        'tag' => 'Media',
        'summary' => 'Replace an image',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Media',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
        'description' => 'Keeps the original\'s position and primary flag.',
        'body' => [
            'type' => 'object',
            'required' => [
                'image',
            ],
            'properties' => [
                'image' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
            ],
        ],
        'bodyType' => 'multipart/form-data',
    ],
    'api.v1.seller.inquiries.index' => [
        'tag' => 'Seller',
        'summary' => 'Inquiries received',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Inquiry',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'query' => [
            [
                'name' => 'status',
                'in' => 'query',
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'new',
                        'read',
                        'replied',
                        'spam',
                        'closed',
                    ],
                ],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ],
    ],
    'api.v1.seller.inquiries.show' => [
        'tag' => 'Seller',
        'summary' => 'Read an inquiry',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Inquiry',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Marks the inquiry read.',
    ],
    'api.v1.seller.inquiries.reply' => [
        'tag' => 'Seller',
        'summary' => 'Reply to an inquiry',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Inquiry',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            '$ref' => '#/components/schemas/ReplyRequest',
        ],
    ],
    'api.v1.admin.listings.pending' => [
        'tag' => 'Moderation',
        'summary' => 'Listings awaiting review',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/ListingSummary',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.listings.approve' => [
        'tag' => 'Moderation',
        'summary' => 'Approve a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
    ],
    'api.v1.admin.listings.reject' => [
        'tag' => 'Moderation',
        'summary' => 'Reject a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                '$ref' => '#/components/responses/InvalidTransition',
            ],
        ],
        'body' => [
            'type' => 'object',
            'required' => [
                'reason',
            ],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'minLength' => 5,
                    'maxLength' => 1000,
                ],
            ],
        ],
    ],
    'api.v1.admin.listings.verify' => [
        'tag' => 'Moderation',
        'summary' => 'Set the verified badge',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            'type' => 'object',
            'properties' => [
                'verified' => [
                    'type' => 'boolean',
                ],
            ],
        ],
    ],
    'api.v1.admin.listings.feature' => [
        'tag' => 'Moderation',
        'summary' => 'Feature or unfeature a listing',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Requires the `listing.feature` permission (admin, not moderator).',
        'body' => [
            'type' => 'object',
            'required' => [
                'featured',
            ],
            'properties' => [
                'featured' => [
                    'type' => 'boolean',
                ],
                'until' => [
                    'type' => 'string',
                    'format' => 'date-time',
                ],
                'boost_score' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 1000,
                ],
            ],
        ],
    ],
    'api.v1.admin.reviews.pending' => [
        'tag' => 'Moderation',
        'summary' => 'Reviews awaiting moderation',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Review',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.reviews.moderate' => [
        'tag' => 'Moderation',
        'summary' => 'Approve or reject a review',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Review',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'description' => 'Approving recalculates the seller\'s aggregate rating.',
        'body' => [
            'type' => 'object',
            'required' => [
                'status',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'approved',
                        'rejected',
                    ],
                ],
                'note' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                ],
            ],
        ],
    ],
    'api.v1.admin.users.index' => [
        'tag' => 'Administration',
        'summary' => 'List users',
        'description' => 'Requires `user.view_any`. Never returns password hashes or tokens.',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'maxLength' => 191,
                ],
                'description' => 'Partial match on email, first name, last name or phone.',
            ],
            [
                'name' => 'status',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'active',
                        'pending',
                        'suspended',
                        'banned',
                    ],
                ],
                'description' => 'Filter by account status.',
            ],
            [
                'name' => 'role',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Filter by role name, e.g. `seller`.',
            ],
            [
                'name' => 'verified_phone',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Only users with (or without) a verified phone.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/AdminUser',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.users.show' => [
        'tag' => 'Administration',
        'summary' => 'Show a user',
        'description' => 'Requires `user.view_any`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminUser',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.users.status' => [
        'tag' => 'Administration',
        'summary' => 'Suspend, ban or restore a user',
        'description' => 'Requires `user.suspend`; setting `banned` additionally requires `user.ban`.

A status that cannot authenticate revokes every token immediately, so the session ends now rather than when the token happens to expire.

Returns 403 when the target is yourself (an organisation must not be able to lock itself out) or a super administrator.',
        'body' => [
            '$ref' => '#/components/schemas/UpdateUserStatusRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminUser',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.users.roles' => [
        'tag' => 'Administration',
        'summary' => 'Replace a user\'s roles',
        'description' => 'Requires `user.assign_role`. The set is REPLACED, not merged.

`super_admin` is rejected at the validation layer and re-checked against `roles.is_assignable`, so it can never be granted through the API. Acting on yourself or on a super administrator returns 403.',
        'body' => [
            '$ref' => '#/components/schemas/UpdateUserRolesRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminUser',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.roles.index' => [
        'tag' => 'Administration',
        'summary' => 'List roles and their permissions',
        'description' => 'Requires `user.assign_role`. Read-only by design: the role/permission MATRIX lives in `Permission::forRole()` and is applied by a seeder, so an environment\'s authorization is always reconstructable from the repository. Which roles a USER holds is fully manageable via `PATCH /admin/users/{uuid}/roles`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Role',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.permissions.index' => [
        'tag' => 'Administration',
        'summary' => 'The permission catalogue, grouped by domain',
        'description' => 'Requires `user.assign_role`. Keys are the prefix before the first dot (`listing`, `user`, `category`, ...).',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'examples' => [
                                        [
                                            'listing' => [
                                                'listing.create',
                                                'listing.publish',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.verifications.index' => [
        'tag' => 'Administration',
        'summary' => 'Seller verification queue',
        'description' => 'Requires `verification.review`. Ordered oldest-first — a review queue is a FIFO, not a stack.',
        'query' => [
            [
                'name' => 'status',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'approved',
                        'rejected',
                    ],
                ],
                'description' => 'Filter by review status.',
            ],
            [
                'name' => 'type',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'national_id',
                        'passport',
                        'business',
                        'address',
                    ],
                ],
                'description' => 'Filter by document type.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/VerificationRequest',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.verifications.approve' => [
        'tag' => 'Administration',
        'summary' => 'Approve a verification request',
        'description' => 'Requires `verification.review`. Raises the seller\'s verification LEVEL rather than setting a boolean, and never downgrades: an ID-verified seller who later submits an address proof keeps the higher standing. Returns 409 when the request has already been reviewed.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VerificationRequest',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.verifications.reject' => [
        'tag' => 'Administration',
        'summary' => 'Reject a verification request',
        'description' => 'Requires `verification.review`. Returns 409 when the request has already been reviewed.',
        'body' => [
            'type' => 'object',
            'required' => [
                'reason',
            ],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'minLength' => 5,
                    'maxLength' => 1000,
                    'description' => 'Shown to the seller.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VerificationRequest',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.categories.store' => [
        'tag' => 'Administration',
        'summary' => 'Create a category',
        'description' => 'Requires `category.manage`. Depth, materialised `path` and `is_leaf` are maintained by the server; a parent that gains a child stops being a leaf and can no longer hold listings directly.',
        'body' => [
            '$ref' => '#/components/schemas/StoreCategoryRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Category',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.categories.update' => [
        'tag' => 'Administration',
        'summary' => 'Update a category',
        'description' => 'Requires `category.manage`. `parent_id` is ignored: reparenting would invalidate the materialised path of every descendant, so it is a data migration rather than an API call.',
        'body' => [
            '$ref' => '#/components/schemas/StoreCategoryRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Category',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.categories.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete a category',
        'description' => 'Requires `category.manage`. Returns 409 when the category still has subcategories or listings — deactivate it instead.',
        'responses' => [
            '200' => [
                'description' => 'Deleted',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.categories.attributes' => [
        'tag' => 'Administration',
        'summary' => 'Replace a category\'s attribute bindings',
        'description' => 'Requires `attribute.manage`. The list is REPLACED. Descendant categories inherit these bindings through the materialised path, so binding `beds` to Property makes it available to Apartments without a second call.',
        'body' => [
            'type' => 'object',
            'required' => [
                'attributes',
            ],
            'properties' => [
                'attributes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'code',
                        ],
                        'properties' => [
                            'code' => [
                                'type' => 'string',
                                'description' => 'An existing attribute code.',
                            ],
                            'is_required' => [
                                'type' => 'boolean',
                                'default' => false,
                            ],
                            'position' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'Defaults to the array order.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Attribute',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.attributes.index' => [
        'tag' => 'Administration',
        'summary' => 'List every attribute definition',
        'description' => 'Requires `attribute.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Attribute',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.attributes.store' => [
        'tag' => 'Administration',
        'summary' => 'Create an attribute',
        'description' => 'Requires `attribute.manage`. `options` is a nested payload, not a column, and is synced separately.',
        'body' => [
            '$ref' => '#/components/schemas/StoreAttributeRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Attribute',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.attributes.update' => [
        'tag' => 'Administration',
        'summary' => 'Update an attribute',
        'description' => 'Requires `attribute.manage`. `code` is **prohibited** on update: it is a public filter key (`?attributes[beds]=3`), so changing it would silently break every saved search and bookmarked URL. Omitting `options` leaves them untouched; sending it replaces the set.',
        'body' => [
            '$ref' => '#/components/schemas/StoreAttributeRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Attribute',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.attributes.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete an attribute',
        'description' => 'Requires `attribute.manage`. Returns 409 when listings still hold values for it.',
        'responses' => [
            '200' => [
                'description' => 'Deleted',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.taxonomy.store' => [
        'tag' => 'Administration',
        'summary' => 'Create an amenity or facility',
        'description' => 'Requires `amenity.manage`. Amenities and facilities share one shape and one set of routes, discriminated by `{type}`.',
        'body' => [
            '$ref' => '#/components/schemas/StoreTaxonomyTermRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Taxonomy',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.taxonomy.update' => [
        'tag' => 'Administration',
        'summary' => 'Update an amenity or facility',
        'description' => 'Requires `amenity.manage`. The slug is immutable — it is a public filter value.',
        'body' => [
            '$ref' => '#/components/schemas/StoreTaxonomyTermRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Taxonomy',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.taxonomy.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete an amenity or facility',
        'description' => 'Requires `amenity.manage`. Returns 409 when listings still reference the term — deactivate it instead.',
        'responses' => [
            '200' => [
                'description' => 'Deleted',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.faqs.index' => [
        'tag' => 'Administration',
        'summary' => 'List FAQs (including inactive)',
        'description' => 'Requires `cms.manage`. Unlike the public endpoint, this returns inactive entries too.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/AdminFaq',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.faqs.store' => [
        'tag' => 'Administration',
        'summary' => 'Create an FAQ',
        'description' => 'Requires `cms.manage`.',
        'body' => [
            '$ref' => '#/components/schemas/FaqRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminFaq',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.faqs.update' => [
        'tag' => 'Administration',
        'summary' => 'Update an FAQ',
        'description' => 'Requires `cms.manage`.',
        'body' => [
            '$ref' => '#/components/schemas/FaqRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminFaq',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.faqs.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete an FAQ',
        'description' => 'Requires `cms.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Deleted',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.pages.index' => [
        'tag' => 'Administration',
        'summary' => 'List CMS pages (including drafts)',
        'description' => 'Requires `cms.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/AdminPage',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.pages.store' => [
        'tag' => 'Administration',
        'summary' => 'Create a CMS page',
        'description' => 'Requires `cms.manage`. Created as a DRAFT — publishing is a separate, explicit call.',
        'body' => [
            'type' => 'object',
            'required' => [
                'slug',
                'title',
            ],
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'pattern' => '^[a-z0-9-]+$',
                ],
                'title' => [
                    'type' => 'string',
                    'maxLength' => 191,
                ],
                'body' => [
                    'type' => 'string',
                    'maxLength' => 200000,
                    'nullable' => true,
                ],
                'meta_title' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'meta_description' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'nullable' => true,
                ],
            ],
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminPage',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.pages.update' => [
        'tag' => 'Administration',
        'summary' => 'Update a CMS page',
        'description' => 'Requires `cms.manage`. The slug is immutable — it is the public URL.',
        'body' => [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'maxLength' => 191,
                ],
                'body' => [
                    'type' => 'string',
                    'maxLength' => 200000,
                    'nullable' => true,
                ],
                'meta_title' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'nullable' => true,
                ],
                'meta_description' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'nullable' => true,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminPage',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.pages.publish' => [
        'tag' => 'Administration',
        'summary' => 'Publish or unpublish a CMS page',
        'description' => 'Requires `cms.manage`. Publishing is explicit so a draft cannot go live by accident, and an empty body cannot be published at all (409).

Terms and Privacy ship unpublished on purpose: their bodies are a legal deliverable, not generated content.',
        'body' => [
            'type' => 'object',
            'properties' => [
                'published' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'False unpublishes, returning the page to draft.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminPage',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.settings.index' => [
        'tag' => 'Administration',
        'summary' => 'List every setting',
        'description' => 'Requires `settings.manage`. Includes private settings, which the public endpoint omits.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Setting',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.settings.update' => [
        'tag' => 'Administration',
        'summary' => 'Update settings in bulk',
        'description' => 'Requires `settings.manage`. Applied in one transaction, and returns the full settings list.

Only the VALUE is writable. `is_public` decides whether a setting is world-readable, so exposing it here would let an admin leak a private key by accident. Unknown keys are rejected — settings are declared by a seeder, not invented at runtime.',
        'body' => [
            'type' => 'object',
            'required' => [
                'settings',
            ],
            'properties' => [
                'settings' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'required' => [
                            'key',
                            'value',
                        ],
                        'properties' => [
                            'key' => [
                                'type' => 'string',
                                'maxLength' => 100,
                                'description' => 'Must already exist.',
                            ],
                            'value' => [
                                'description' => 'Any JSON scalar, array or object.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Setting',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.stats.overview' => [
        'tag' => 'Administration',
        'summary' => 'Dashboard counters',
        'description' => 'Requires `analytics.view`. Cached for 60 seconds — short enough that a moderator sees the pending count move after acting on it, long enough that a wall display does not make this the heaviest query on the platform.

Counters that come from the same table are gathered in one query with conditional aggregates, and every value is an INTEGER (MySQL returns COUNT/SUM as strings; the cast happens server-side so clients can do arithmetic).',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminOverview',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.stats.growth' => [
        'tag' => 'Administration',
        'summary' => 'Daily growth series',
        'description' => 'Requires `analytics.view`. Cached for 10 minutes.

Every series is GAP-FILLED to exactly one point per day. A `GROUP BY date` returns no row for a day with no activity, and a chart built from that draws a straight line across the gap — which reads as steady traffic rather than none.',
        'query' => [
            [
                'name' => 'days',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 7,
                    'maximum' => 365,
                    'default' => 30,
                ],
                'description' => 'Window length. Capped at a year: the series is one point per day.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminGrowth',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.stats.categories' => [
        'tag' => 'Administration',
        'summary' => 'Listings per top-level category',
        'description' => 'Requires `analytics.view`. Reads the denormalised `listing_count`, which `saka:taxonomy:recount` maintains hourly — it is subtree-aware and already excludes unpublished listings.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/CategoryPopularity',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.stats.vendors' => [
        'tag' => 'Administration',
        'summary' => 'Most active vendors',
        'description' => 'Requires `analytics.view`. Ranked by published listing count, with lifetime views, inquiries and favourites.',
        'query' => [
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                ],
                'description' => 'How many vendors to return.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/TopVendor',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.activity.index' => [
        'tag' => 'Administration',
        'summary' => 'Audit trail',
        'description' => 'Requires `activity_log.view` — deliberately a different permission from `analytics.view`, because this is who did what to whom rather than a chart of signups.

Never cached. Entries are append-only and hash-chained: each row\'s `prev_hash` is its predecessor\'s `hash`, so a row edited or deleted directly in the database breaks the chain from that point on.',
        'query' => [
            [
                'name' => 'action',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'maxLength' => 100,
                ],
                'description' => 'Exact action, e.g. `listing.status_changed`.',
            ],
            [
                'name' => 'actor',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Prefix match on the actor email recorded at the time.',
            ],
            [
                'name' => 'subject_type',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Suffix match on the subject class, e.g. `Listing`.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/AuditEntry',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.listings.index' => [
        'tag' => 'Administration',
        'summary' => 'Search every listing, any status',
        'description' => 'Requires `listing.moderate`. Unlike `GET /listings`, which is scoped to publicly-visible listings, this sees drafts, rejections and archives.

Search is a plain infix LIKE rather than the public FULLTEXT index: moderators search a fragment of a title they were sent, and FULLTEXT cannot do infix matching. That is a table scan, accepted because this surface has a small concurrent audience.',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'maxLength' => 200,
                ],
                'description' => 'Infix match on title, slug or uuid.',
            ],
            [
                'name' => 'status',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'draft',
                        'pending_review',
                        'published',
                        'rejected',
                        'paused',
                        'expired',
                        'sold',
                        'archived',
                    ],
                ],
                'description' => 'Exact status.',
            ],
            [
                'name' => 'category',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Category slug.',
            ],
            [
                'name' => 'seller',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Seller uuid.',
            ],
            [
                'name' => 'featured',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Only featured, or only not.',
            ],
            [
                'name' => 'verified',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Only verified, or only not.',
            ],
            [
                'name' => 'trashed',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Return SOFT-DELETED listings instead of live ones.',
            ],
            [
                'name' => 'sort',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'newest',
                        'oldest',
                        'updated',
                        'price_asc',
                        'price_desc',
                        'views',
                    ],
                    'default' => 'updated',
                ],
                'description' => 'Ordering.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/ListingSummary',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.listings.show' => [
        'tag' => 'Administration',
        'summary' => 'One listing in full, including deleted',
        'description' => 'Requires `listing.moderate`. Returns the detailed shape plus `deleted_at` and the moderation `status_history`. Resolves soft-deleted listings — route-model binding is deliberately not used here, since it would 404 exactly the listing a moderator is trying to inspect.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminListingDetail',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.listings.transition' => [
        'tag' => 'Administration',
        'summary' => 'Move a listing to another status',
        'description' => 'Requires `listing.moderate`. Goes through the status service, so the move is validated, written to `listing_status_histories` and re-indexed.

A 409 carries `details.allowed` — the statuses reachable from the current one — so a client can render the right buttons instead of guessing.',
        'body' => [
            'type' => 'object',
            'required' => [
                'status',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'draft',
                        'pending_review',
                        'published',
                        'rejected',
                        'paused',
                        'expired',
                        'sold',
                        'archived',
                    ],
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                    'description' => 'Recorded in the status history and shown to the seller.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.listings.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Soft delete a listing',
        'description' => 'Requires `listing.delete_any`. REVERSIBLE — the row remains with `deleted_at` set and the listing leaves every index. Use `POST /admin/listings/{uuid}/restore` to undo.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.listings.restore' => [
        'tag' => 'Administration',
        'summary' => 'Restore a soft-deleted listing',
        'description' => 'Requires `listing.delete_any`. Returns 409 when the listing is not deleted.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/ListingStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.listings.force' => [
        'tag' => 'Administration',
        'summary' => 'Permanently delete a listing',
        'description' => 'Requires `listing.delete_any` AND the `super_admin` role. Irreversible.

Everything else on this controller can be undone; this cannot, so it is gated on the one role the API will never grant. The audit entry is written BEFORE the row is destroyed and carries the title, slug and owner — afterwards there is nothing left to describe what was removed. It is also deliberately absent from bulk actions.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.listings.bulk' => [
        'tag' => 'Administration',
        'summary' => 'Apply one action to many listings',
        'description' => 'Requires `listing.moderate`, plus `listing.delete_any` for `delete` and `listing.feature` for feature/unfeature.

PARTIAL SUCCESS BY DESIGN: each listing is processed independently and one that cannot make the transition is reported in `failed` rather than aborting the batch. A moderator clearing a queue of 50 wants the 49 that worked plus a list of what did not, not a rollback.

`force_delete` is not an allowed action.',
        'body' => [
            'type' => 'object',
            'required' => [
                'action',
                'uuids',
            ],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'approve',
                        'reject',
                        'archive',
                        'feature',
                        'unfeature',
                        'verify',
                        'delete',
                    ],
                ],
                'uuids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => 1000,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/BulkResult',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.users.password_reset' => [
        'tag' => 'Administration',
        'summary' => 'Send a password reset link',
        'description' => 'Requires `user.update`. Sends the standard reset link to the account\'s own email.

Note what this does NOT do: set a password, or return one. No endpoint anywhere lets an administrator choose another account\'s password — an admin who can do that can sign in as that user, and no audit trail can tell that apart from the real person.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.users.activity' => [
        'tag' => 'Administration',
        'summary' => 'Audit entries for one user',
        'description' => 'Requires `activity_log.view`. Returns entries in BOTH directions — `direction: performed` where the user acted, `received` where an administrator acted on them.',
        'query' => [
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/AuditEntry',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.verifications.request_info' => [
        'tag' => 'Administration',
        'summary' => 'Ask a seller for more information',
        'description' => 'Requires `verification.review`. Keeps the request PENDING and leaves `reviewed_at` null, so it stays in the queue and can still be approved or rejected.

The queue previously had only two exits. Most real submissions need neither — a cut-off ID photo is not grounds for rejection, and approving it is worse — so reviewers were choosing between two wrong answers.',
        'body' => [
            'type' => 'object',
            'required' => [
                'message',
            ],
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'minLength' => 10,
                    'maxLength' => 1000,
                    'description' => 'Shown to the seller.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VerificationRequest',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.banners.index' => [
        'tag' => 'Administration',
        'summary' => 'List homepage banners',
        'description' => 'Requires `cms.manage`. `meta.placements` lists the slots the frontend can render.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/Banner',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.banners.store' => [
        'tag' => 'Administration',
        'summary' => 'Create a banner',
        'description' => 'Requires `cms.manage`. `link_url` is restricted to http/https — a `javascript:` href stored here would execute for every visitor to the homepage.',
        'body' => [
            '$ref' => '#/components/schemas/BannerRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Banner',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.banners.update' => [
        'tag' => 'Administration',
        'summary' => 'Update a banner',
        'description' => 'Requires `cms.manage`.',
        'body' => [
            '$ref' => '#/components/schemas/BannerRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Banner',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.banners.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete a banner',
        'description' => 'Requires `cms.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.banners.reorder' => [
        'tag' => 'Administration',
        'summary' => 'Reorder banners',
        'description' => 'Requires `cms.manage`. Applies the whole arrangement in one transaction — sending a PATCH per banner leaves the list visibly wrong between requests if one fails.',
        'body' => [
            'type' => 'object',
            'required' => [
                'order',
            ],
            'properties' => [
                'order' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Banner uuids in the desired order.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.sections.index' => [
        'tag' => 'Administration',
        'summary' => 'List homepage sections',
        'description' => 'Requires `cms.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/HomepageSection',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.sections.update' => [
        'tag' => 'Administration',
        'summary' => 'Retitle, resize or hide a section',
        'description' => 'Requires `cms.manage`.

Sections cannot be CREATED or DELETED, and `key` is `prohibited`: it binds the row to a React component, so changing it orphans the section rather than renaming it. This is deliberately not a page builder — everything editable here is data the existing design already accounts for.',
        'body' => [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 191,
                ],
                'subtitle' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'nullable' => true,
                ],
                'position' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 65535,
                ],
                'is_active' => [
                    'type' => 'boolean',
                ],
                'item_limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 48,
                    'nullable' => true,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/HomepageSection',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.sections.reorder' => [
        'tag' => 'Administration',
        'summary' => 'Reorder sections',
        'description' => 'Requires `cms.manage`.',
        'body' => [
            'type' => 'object',
            'required' => [
                'order',
            ],
            'properties' => [
                'order' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Section keys in the desired order.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.place_categories.index' => [
        'tag' => 'Administration',
        'summary' => 'List public-place categories',
        'description' => 'Requires `location.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/PlaceCategory',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.place_categories.store' => [
        'tag' => 'Administration',
        'summary' => 'Create a place category',
        'description' => 'Requires `location.manage`.',
        'body' => [
            '$ref' => '#/components/schemas/PlaceCategoryRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/PlaceCategory',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.place_categories.update' => [
        'tag' => 'Administration',
        'summary' => 'Update a place category',
        'description' => 'Requires `location.manage`. The slug is immutable — it is a public URL segment.',
        'body' => [
            '$ref' => '#/components/schemas/PlaceCategoryRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/PlaceCategory',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.place_categories.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete a place category',
        'description' => 'Requires `location.manage`. Returns 409 while the category still holds places.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.admin.places.index' => [
        'tag' => 'Administration',
        'summary' => 'Search public places',
        'description' => 'Requires `location.manage`.',
        'query' => [
            [
                'name' => 'q',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Infix match on name.',
            ],
            [
                'name' => 'category',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Category slug.',
            ],
            [
                'name' => 'region',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                ],
                'description' => 'Region slug.',
            ],
            [
                'name' => 'active',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Filter by active flag.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 25,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/AdminPlace',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.places.store' => [
        'tag' => 'Administration',
        'summary' => 'Create a public place',
        'description' => 'Requires `location.manage`. Recomputes the category\'s `place_count`, which is denormalised. `website` is restricted to http/https for the same reason banner links are.',
        'body' => [
            '$ref' => '#/components/schemas/PlaceRequest',
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminPlace',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.places.update' => [
        'tag' => 'Administration',
        'summary' => 'Update a public place',
        'description' => 'Requires `location.manage`. Moving a place between categories recomputes BOTH — recounting only the destination would leave the origin overstated.',
        'body' => [
            '$ref' => '#/components/schemas/PlaceRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/AdminPlace',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.places.destroy' => [
        'tag' => 'Administration',
        'summary' => 'Delete a public place',
        'description' => 'Requires `location.manage`.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.admin.system.info' => [
        'tag' => 'Administration',
        'summary' => 'Environment and storage report',
        'description' => 'Requires `settings.manage`. Deliberately narrow: versions, driver names, queue depth and disk usage. NOT phpinfo(), not the resolved config, not environment variables — an admin surface is a credential-harvesting target, and knowing the cache driver does not justify exposing the rest.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/SystemInfo',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.system.cache' => [
        'tag' => 'Administration',
        'summary' => 'Clear a cache group',
        'description' => 'Requires `settings.manage`. Targeted rather than global: discarding the whole cache on a warm instance sends every subsequent request to the database at once.

There is no `flush` target. Cache and queue can share a Redis instance, so a FLUSHDB from a settings screen would discard queued jobs.',
        'body' => [
            'type' => 'object',
            'required' => [
                'target',
            ],
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'enum' => [
                        'application',
                        'taxonomy',
                        'content',
                        'discovery',
                        'config',
                    ],
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.admin.system.maintenance' => [
        'tag' => 'Administration',
        'summary' => 'Toggle maintenance mode',
        'description' => 'Requires `settings.manage`. Refuses when `APP_MAINTENANCE_DRIVER=file` on a deployment flagged multi-node — the file driver only takes down the node that handled the request, leaving the platform half-up in a way that is very hard to diagnose.

No bypass secret is generated: it would have to be returned in this response and would then live in browser history and every proxy log in between.',
        'body' => [
            'type' => 'object',
            'required' => [
                'enabled',
            ],
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                ],
                'message' => [
                    'type' => 'string',
                    'maxLength' => 255,
                ],
                'retry_after' => [
                    'type' => 'integer',
                    'minimum' => 10,
                    'maximum' => 86400,
                    'description' => 'Retry-After header, in seconds.',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.business_types' => [
        'tag' => 'Catalog',
        'summary' => 'Business types and the rules each implies',
        'description' => 'Public — the vendor registration screen needs this before an account exists.

This is the axis the multi-vertical design turns on. Each entry says which catalogue verticals the business posts into, whether opening hours and a walk-in address are meaningful, and what that trade CALLS a listing (a hotel has rooms, a dealer has vehicles). Clients render their forms from this rather than carrying a second copy of the rules.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/BusinessType',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.vendor_profile.show' => [
        'tag' => 'Seller',
        'summary' => 'The vendor\'s own business profile',
        'description' => 'Creates the profile on first access — a user becomes a seller when they publish, so the row may not exist yet and every screen can assume one.

`meta.progress` drives onboarding: which steps are done, which are NOT APPLICABLE to this business type, and where a wizard should resume. `meta.business_type` carries that type\'s rules.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VendorProfile',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.vendor_profile.update' => [
        'tag' => 'Seller',
        'summary' => 'Update the business profile',
        'description' => 'Onboarding and settings are the SAME endpoint. A wizard writing through a separate API is a second code path that drifts from the settings screen and validates every field twice, slightly differently — here the wizard is a UI over a partial PATCH.

Every field is optional. Enforced beyond the field rules: a district must belong to its region and a ward to its district (`exists:` proves an id is real, not that they belong together); latitude and longitude must arrive together; and opening hours must not overlap or close before they open.',
        'body' => [
            '$ref' => '#/components/schemas/VendorProfileRequest',
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VendorProfile',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.vendor_profile.branding.upload' => [
        'tag' => 'Seller',
        'summary' => 'Upload a logo or cover image',
        'description' => 'Multipart. Goes through the media pipeline (validation, EXIF stripping, variants). Replacing existing branding deletes the previous file rather than orphaning it.',
        'bodyType' => 'multipart/form-data',
        'body' => [
            'type' => 'object',
            'required' => [
                'file',
            ],
            'properties' => [
                'file' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/BrandingUpload',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.seller.vendor_profile.branding.destroy' => [
        'tag' => 'Seller',
        'summary' => 'Remove a logo or cover image',
        'description' => 'Deletes the file as well as clearing the reference.',
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.seller.analytics' => [
        'tag' => 'Seller',
        'summary' => 'Daily series for the vendor\'s own listings',
        'description' => 'Views, favourites, inquiries and reviews, one point per day and GAP-FILLED — a chart built straight from a GROUP BY draws a straight line across days with no activity, which reads as steady traffic rather than none.

Scoped to the caller in the query itself, not filtered afterwards: no parameter can widen this into platform-wide analytics. A vendor with no listings gets empty series, not an error.

Views come from the daily rollup table, so this reads tens of rows rather than millions.',
        'query' => [
            [
                'name' => 'days',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 7,
                    'maximum' => 365,
                    'default' => 30,
                ],
                'description' => 'Window length.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VendorAnalytics',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.reviews.index' => [
        'tag' => 'Seller',
        'summary' => 'Reviews RECEIVED on this vendor\'s listings',
        'description' => 'Distinct from `/account/reviews`, which returns reviews the user WROTE — the opposite of what a vendor needs.',
        'query' => [
            [
                'name' => 'status',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => [
                        'pending',
                        'approved',
                        'rejected',
                    ],
                ],
                'description' => 'Moderation status.',
            ],
            [
                'name' => 'rating',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 5,
                ],
                'description' => 'Exact star rating.',
            ],
            [
                'name' => 'replied',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'boolean',
                ],
                'description' => 'Only answered, or only unanswered.',
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 20,
                ],
                'description' => 'Results per page.',
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'allOf' => [
                                [
                                    '$ref' => '#/components/schemas/PaginatedEnvelope',
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/Review',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.reviews.reply' => [
        'tag' => 'Seller',
        'summary' => 'Answer a review publicly',
        'description' => 'One reply per review, editable afterwards. `replied_at` is kept at the FIRST reply, so editing an answer does not misreport how quickly the vendor responded.

Only approved reviews may be answered — a reply to a review still in moderation would have nothing to appear under. 404 on someone else\'s review.',
        'body' => [
            'type' => 'object',
            'required' => [
                'body',
            ],
            'properties' => [
                'body' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 2000,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'The review, with the reply attached',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Review',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                'description' => 'The review is not published yet',
            ],
        ],
    ],
    'api.v1.seller.reviews.report' => [
        'tag' => 'Seller',
        'summary' => 'Report a review for moderation',
        'description' => 'Does NOT hide the review. A vendor who could remove criticism by reporting it would make the whole rating system worthless — this records the objection and routes it to moderation while the review stays exactly as visible as it was.

404 on someone else\'s review: whether it exists is not this vendor\'s business unless it is about them.',
        'body' => [
            'type' => 'object',
            'required' => [
                'reason',
                'details',
            ],
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'enum' => [
                        'spam',
                        'offensive',
                        'false_information',
                        'not_a_customer',
                        'other',
                    ],
                ],
                'details' => [
                    'type' => 'string',
                    'minLength' => 10,
                    'maxLength' => 1000,
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Done',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Message',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.seller.inquiries.update' => [
        'tag' => 'Seller',
        'summary' => 'Move an inquiry through the inbox',
        'description' => 'Resolve (`closed`), file as spam, or mark read.

`replied` is deliberately NOT settable: it means the vendor answered, and it feeds the public response-rate signal on their profile. Setting it by hand would let a vendor manufacture a reputation for responsiveness.',
        'body' => [
            'type' => 'object',
            'required' => [
                'status',
            ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'read',
                        'closed',
                        'spam',
                    ],
                ],
            ],
        ],
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/InquiryStatus',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
    'api.v1.seller.listings.duplicate' => [
        'tag' => 'Seller',
        'summary' => 'Copy a listing into a new draft',
        'description' => 'COPIED: title, description, price, category, location, attributes, amenities, facilities.

NOT COPIED: identity (uuid, slug), lifecycle (status, published_at), engagement (views, favourites, inquiries), moderation (verified, featured) and MEDIA. A copy inheriting 4,000 views and a verified badge would be a fabricated reputation; copying the photos produces listings that all show the same room.

Ownership is checked explicitly rather than through the `view` policy, which returns true for any published listing and would let a seller clone a competitor\'s. Works on a SOLD or archived listing — relisting last season\'s stock is the main use case. Does not require a verified phone, because the result is a draft.',
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/Listing',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '404' => [
                '$ref' => '#/components/responses/NotFound',
            ],
        ],
    ],
    'api.v1.seller.verifications.index' => [
        'tag' => 'Seller',
        'summary' => 'The vendor\'s verification history',
        'description' => '`reviewer_note` doubles as what a reviewer asked for when they requested more information rather than rejecting.',
        'responses' => [
            '200' => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/VendorVerification',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'api.v1.seller.verifications.store' => [
        'tag' => 'Seller',
        'summary' => 'Submit a document for verification',
        'description' => 'Milestone 9 built the moderator\'s review queue but nothing that could put a request into it — this is the missing half.

Documents go to a PRIVATE disk and are only ever served through short-lived signed URLs, which is why this cannot reuse the listing media endpoints.

One pending request per type: without that a vendor can queue twenty copies of the same ID and fill an oldest-first moderation queue with duplicates of one person.',
        'bodyType' => 'multipart/form-data',
        'body' => [
            'type' => 'object',
            'required' => [
                'type',
                'document',
            ],
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => [
                        'phone',
                        'national_id',
                        'business',
                        'address',
                    ],
                ],
                'document_number' => [
                    'type' => 'string',
                    'maxLength' => 60,
                ],
                'document' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
            ],
        ],
        'responses' => [
            '201' => [
                'description' => 'Created',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    '$ref' => '#/components/schemas/VendorVerification',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '409' => [
                '$ref' => '#/components/responses/Conflict',
            ],
        ],
    ],
];
