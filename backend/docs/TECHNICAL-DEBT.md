# Technical debt

Known gaps, deliberate deferrals and things a future maintainer should not be
surprised by. Ordered by how much they will hurt, not by how easy they are.

Nothing here is a bug in shipped behaviour — those are fixed, not listed.

---

## 1. Deferred by product decision (not debt, but track it)

| Item | Milestone | Note |
|---|---|---|
| Payments, orders, checkout, escrow, wallet, delivery | v2.0 | The schema was designed with these in mind (`listings.price`/`currency`/`price_unit` are already normalised, and every id is a stable UUID) but nothing is implemented. |
| Meilisearch | v1.1 | `SearchDriver` is an interface; `MySqlFullTextDriver` is the only implementation. `search_document` is maintained on every write specifically so a second driver is a drop-in. |
| SMS gateway for phone OTP | v1.1 | `PhoneOtpNotification` ships the `log` channel only. Switching to Africa's Talking or Twilio is a change to the channel list and nothing else. |
| Saved searches, storefronts, promotions, analytics dashboards | v1.1 | Tables not created. |
| Terms & Privacy page bodies | — | Seeded as **unpublished** placeholders on purpose. Real legal copy is a business deliverable; the publish endpoint refuses to publish an empty body. |

---

## 2. Real debt, in priority order

### 2.1 Ward-level location data is incomplete — HIGH

`LocationSeeder` has all 31 regions, districts for every region, and wards only
for Dar es Salaam plus the largest urban districts elsewhere. Radius search and
ward filters therefore work well in the launch market and thinly outside it.

This is a **data import task, not a code task** — the schema and API already
handle the full hierarchy. Source: the NBS administrative dataset.

### 2.2 `listing_views` is the fastest-growing table — MEDIUM

Pruned at 90 days by `saka:views:prune`, which keeps it bounded, but it is
still the table that will need partitioning first. Partition by `viewed_on`
month before it reaches tens of millions of rows; the prune command becomes a
`DROP PARTITION` at that point instead of a batched delete.

### 2.3 No integration test runs a real queue worker — MEDIUM

`QueueReliabilityTest` asserts the *contract* (jobs reach the right queue, every
queue has a supervisor, no `queue()` trap, handlers are idempotent) and job
handlers are called directly. Nothing boots an actual worker against Redis.

The two production defects found in this area — a `queue()` method silently
discarding jobs, and Horizon supervising only `default` — were both invisible to
`Queue::fake()`, which is why the contract tests exist. A smoke test that runs
`queue:work --once` against a real Redis in CI would close the remaining gap.

### 2.4 Media variant generation is synchronous per image — MEDIUM

`GenerateImageVariants` processes one `Media` row per job. A 20-image listing
dispatches 20 jobs that each decode and re-encode independently. Batching them
per listing would cut the queue churn, and `Bus::batch()` would give a real
"processing" progress signal to the seller instead of a per-row status column.

Not urgent: the `media` supervisor is isolated, so a burst delays only images.

### 2.5 `search_document` is rebuilt in PHP on every listing write — LOW

Correct and cheap today. It becomes worth moving to a queued job when listings
carry many EAV values, because the rebuild happens inside the write
transaction.

### 2.6 No API-level pagination cursor — LOW

Everything uses offset pagination (`?page=`). Deep pages on a large listing
table get progressively slower, and a listing inserted mid-scroll shifts
results. Cursor pagination is the fix; it is an additive API change (`?cursor=`
alongside `?page=`), not a breaking one.

### 2.7 Caching an Eloquent model is still easy to do by accident — MEDIUM

Fixed everywhere it occurred (Milestone 10), but nothing stops it recurring.

Caching a model or an Eloquent collection serialises the object graph. Read
back in another process it becomes `__PHP_Incomplete_Class`, and the failure is
worse than a crash: `Cache::remember()` returns the closure's value directly on
the COLD request, so the first hit is always correct, and the warm hit returns
**HTTP 200 with a corrupt body**. The category tree silently lost every
subcategory once its cache warmed.

Two habits hid it: manual checks hit the cold path, and `phpunit.xml` pins
`CACHE_STORE=array`, which never serialises. `CachedEndpointsTest` now hits each
cached endpoint twice against a serialising store and compares the bodies.
`App\Support\Cache\CacheableResource` is the sanctioned way to cache a
resource. A static-analysis rule forbidding `Cache::*` on an Eloquent type would
close the hole properly.

### 2.8 Horizon failure notifications are unrouted — LOW

`config/horizon.waits` defines thresholds, but nothing is wired to mail or
Slack, so a growing queue is visible only to someone who opens the dashboard.
One method call in `HorizonServiceProvider::boot()`; left to the operator
because the destination is environment-specific. See `docs/DEPLOYMENT.md`.

### 2.9 `larastan` over-narrows two framework types — LOW

`nullsafe.neverNull` is ignored by identifier in `phpstan.neon`. larastan types
`$request->user()` and Eloquent `HasOne` relations as non-nullable; both are
null at runtime (verified, not assumed — a guest request and a user with no
seller profile). The null guards are load-bearing, so the report is wrong rather
than the code. Revisit when larastan models these accurately.

### 2.10 `docs/openapi-metadata.php` is 4,000 lines of array literal — LOW

It is machine-checked (the generator fails on an undocumented route, and
`validate-openapi.php` fails on a stale or drifted spec), so it cannot silently
rot. But it is unpleasant to hand-edit. If it grows much further, generate the
request-body schemas from the FormRequest rules rather than restating them.

### 2.11 Demo listings are seeded, not imported — LOW

`DemoSeeder` holds the 15 listings the frontend used to hardcode, so a local or
preview environment renders the catalogue it always did. It refuses to run in
production. Real listings arrive through the seller API; nothing here is a
migration path for production data.

---

## 3. Operational caveats worth writing down

**Counters are eventually consistent.** View/favourite/inquiry counts buffer in
Redis and flush once a minute. A UI that writes then immediately reads back will
see the old number. Tests call `flushCounters()` explicitly rather than hiding
this.

**`saka:counters:flush` bypasses Eloquent.** It issues a bulk `UPDATE`, so no
model events fire and the cache observer never sees it. That is intentional —
the alternative is loading and saving thousands of models per minute — but it
means anything that must react to a counter change has to be wired explicitly.

**Wildcard cache invalidation is Redis-only.** `CacheKeys::forgetPattern()`
returns early on stores that cannot enumerate keys (array, file, database). On
those stores TTL is the only safety net. `phpunit.xml` pins the array store, so
`CacheInvalidationTest` switches itself to Redis — without that, its assertions
would pass against a completely broken implementation.

**Tests require MySQL and Redis.** Not a preference. The schema uses `FULLTEXT`,
`CHECK` constraints and generated columns, and SQLite silently ignores or
rejects all three.

**FULLTEXT indexes update at COMMIT.** Keyword-search tests use
`DatabaseTruncation`, not `RefreshDatabase`, and truncate explicitly in
`tearDown()` because truncation commits and would otherwise leak across suites.

**The super-admin role is not grantable through the API** — by anyone, including
another super-admin. Seeder or database only.

**`listing_count` is denormalised and recomputed, not incremented.**
`saka:taxonomy:recount` rebuilds it hourly from published listings. A listing
published now can therefore show in the results but not yet in its category's
count. Incrementing on write was rejected: a listing changes category, region
and status over its life, which is four places for a counter to drift with no
way to notice.
