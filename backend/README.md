# SAKA — Backend API

Laravel 13 + MySQL + Sanctum. Greenfield; **independent of `../saka_api`**,
which is not used, imported from, or depended on.

Implements the architecture approved in Milestone 4.

- **Milestone 5** — foundation: config, domain enums, MVP schema, models, seeders.
- **Milestone 6** — platform prerequisites (spatie permissions, Redis), the HTTP
  foundation (error envelope, versioning, rate limiting, request correlation)
  and **complete authentication**.

Listings, catalog, seller and admin endpoints land in Milestone 7+.

## Requirements

PHP 8.3+ · Composer 2 · MySQL 8.0+ (9.x fine) · **Redis (required)**

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
mysql -u root -e "CREATE DATABASE saka; CREATE DATABASE saka_testing;"
brew services start redis        # or your platform's equivalent
php artisan migrate:fresh --seed
php artisan test
```

Redis backs cache, queue, sessions, rate limiting and the spatie permission
cache. Tests override cache/session/queue to array/sync so the suite does not
need Redis running.

## Local accounts (seeded in `local` only)

| Email | Password | Role | Notes |
| --- | --- | --- | --- |
| admin@saka.co.tz | `password` | super_admin | override with `SEED_ADMIN_*` |
| moderator@saka.test | `password` | moderator | |
| seller@saka.test | `password` | buyer + seller | phone **verified** — may publish |
| unverified@saka.test | `password` | buyer + seller | phone **unverified** — blocked from publishing |
| buyer@saka.test | `password` | buyer | |

Outside `local`/`testing` the seeder refuses to create accounts unless
`SEED_ADMIN_PASSWORD` is set — it will never silently create a known password.

## Architecture

```
app/
├── Domain/          framework-free enums, DTOs, invariants
│   ├── Listing/     ListingStatus (+transition table), Purpose, PriceUnit, Condition
│   ├── Identity/    RoleSlug, Permission, UserStatus, OAuthProvider
│   ├── Catalog/     AttributeInputType, AttributeDataType
│   ├── Engagement/  InquiryStatus, InquirySource, ReviewStatus
│   ├── Trust/       VerificationType, VerificationStatus, VerificationLevel
│   └── Media/       MediaCollection
├── Models/          Eloquent (persistence)
├── Exceptions/      ErrorCode enum + ApiException (the whole error contract)
├── Http/
│   ├── Controllers/Api/V1/{Auth,Account}/
│   ├── Middleware/  AssignRequestId, EnsureAccountIsActive, EnsurePhoneIsVerified
│   ├── Requests/V1/ one FormRequest per write endpoint
│   └── Resources/V1/ explicit field whitelists (never model->toArray())
├── Services/
│   ├── Identity/    AuthService, TokenService, PhoneVerificationService,
│   │                GoogleTokenVerifier
│   └── Search/      SearchService + SearchDriver contract + MySqlFullTextDriver
├── Notifications/   PhoneOtpNotification (+ LogChannel stand-in for SMS)
└── Providers/       RateLimitServiceProvider (11 named limiters)
config/saka.php      all domain configuration in one place
database/
├── migrations/      15 migrations, 48 tables
└── seeders/         reference data + baseline accounts
```

Planned next: `Controllers/Api/V1/{Public,Seller,Admin}`, `Repositories/`,
`Policies/`, the filter pipeline and media uploads.

## API surface (v1)

`/api/v1` — URI-path versioning; `routes/api.php` only mounts versions.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/health` | liveness + version |
| POST | `/auth/register` | 3/hour per IP |
| POST | `/auth/login` | 5/min per email+IP |
| POST | `/auth/oauth/google` | `id_token` verified against Google JWKS |
| POST | `/auth/forgot-password` | always 202 (no enumeration) |
| POST | `/auth/reset-password` | revokes every token |
| GET | `/auth/me` | |
| POST | `/auth/refresh` | rotates the token |
| DELETE | `/auth/logout` · `/auth/logout-all` | |
| POST | `/auth/phone/request-otp` | 1/min, 5/hour per phone |
| POST | `/auth/phone/verify-otp` | unlocks publishing |
| GET/PATCH | `/account/profile` | |
| PATCH | `/account/password` | requires current password |

### Error envelope

Every failure, without exception:

```json
{ "error": { "code": "OTP_EXPIRED", "message": "…", "request_id": "01K…" } }
```

Validation adds Laravel's `errors` map alongside. Clients switch on
`error.code` (see `App\Exceptions\ErrorCode`), never on the message.
`X-Request-Id` is set on every response and included in every error.

## Key design decisions

**Search behind a driver.** Controllers depend on `SearchService`, never on an
engine. MVP is `MySqlFullTextDriver` (FULLTEXT, boolean-mode sanitised);
introducing Meilisearch is a new class plus `SAKA_SEARCH_DRIVER=meilisearch` —
no controller, route or response change. `listings.search_document` is populated
from MVP so that switch is a reindex, not a backfill.

**Multi-vertical EAV.** `listings` carries only globally-filtered columns
(price, category, location, status). Category-specific facets live in
`attributes` + `listing_attribute_values` with **typed value columns**, so
`beds >= 2` is an index range scan, not a string cast. Attributes bind to a root
category and inherit down the materialised path. Adding a tenth vertical is an
entry in `CatalogSeeder`, not a migration. Covered by
`tests/Feature/Catalog/MultiVerticalEavTest.php`.

**Phone gate.** `User::canPublishListings()` requires `phone_verified_at`.
Browsing stays open to guests. Toggle with `SAKA_REQUIRE_PHONE_VERIFICATION`.

**Permissions, not roles.** `spatie/laravel-permission` 8.x, with its cache
pinned to Redis so a revocation takes effect fleet-wide rather than per process.
Roles are bundles defined in `Permission::forRole()`; application code passes
the `Permission`/`RoleSlug` **enum**, so a typo is a fatal error at the call
site instead of a silently-denied check.

**Tokens carry abilities.** Sanctum tokens are issued with the user's permission
set as abilities, so a stolen buyer token cannot call seller endpoints even if
the account is later granted the seller role.

**Auth cannot enumerate accounts.** Unknown email and wrong password return a
byte-identical response; `forgot-password` always returns 202. Both are covered
by tests.

**Money in minor units.** `price` is `BIGINT UNSIGNED`; `currency` is per-listing
from day one, so multi-currency is configuration rather than a migration.

**Media disk per row.** `media.disk` records where each file was written, so
moving to S3 is a backfill plus an env change — old and new files both resolve.

**v2.0 seams already present.** `is_featured` / `featured_until` / `boost_score`
(promotions), `reviews.is_verified_purchase` (orders), `seller_profiles.slug` +
`bio` + `logo` (storefronts). These become services and UI, never an `ALTER` on
a large table.

## Testing

Tests run against **MySQL** (`saka_testing`), not SQLite — the schema uses
FULLTEXT indexes, CHECK constraints and a STORED generated column that SQLite
cannot express. Testing against a different engine than production is not
testing.

```bash
php artisan test
./vendor/bin/pint --test
```

## Not yet implemented (by design)

Listings/catalog/seller/admin endpoints, media uploads, the filter pipeline,
policies, email notification templates, Meilisearch, and any frontend wiring.

`PhoneOtpNotification` writes to the log rather than sending an SMS — an SMS
gateway is a v1.1 integration with a real per-message cost. Swapping it in is a
one-line change to `via()`.
