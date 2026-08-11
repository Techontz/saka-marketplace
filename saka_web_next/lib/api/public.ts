import "server-only";

import { API_BASE, buildQuery, request, type QueryValue } from "./http";
import type {
  AdPlacement,
  ApiSlotDay,
  ApiSpecialistService,
  ApiAdCreative,
  ApiAdPlacementMeta,
  ApiBusiness,
  ApiCategory,
  ApiListing,
  ApiPlace,
  ApiReview,
  Envelope,
  Paginated,
} from "@/lib/types";

/**
 * Public reads, straight from Laravel on the server.
 *
 * These are the only requests that may be CACHED: they carry no token and are
 * identical for every visitor. Anything per-customer — favourites, inquiries,
 * notifications — goes through the browser proxy instead, so a signed-in
 * response can never be served to someone else.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CACHE POLICY — read this before adding an endpoint
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Every call below is classified. The classification is not decoration: it is
 * the thing that stops a repeat of the bug that produced it.
 *
 * WHAT WENT WRONG. `/categories` carries `listing_count` for all 86 categories
 * and was cached at `revalidate: 3600`. Every subcategory on the homepage read
 * "0 Listings" against a database holding two hundred. Two things made it hard
 * to see and hard to fix:
 *
 *   1. A Next data-cache entry STORES THE TTL IT WAS WRITTEN WITH. Lowering
 *      the number in this file does nothing to entries already on disk.
 *   2. There are TWO such caches — `.next/cache/fetch-cache` for a production
 *      build and `.next/dev/cache/fetch-cache` for `next dev`. Both survive a
 *      rebuild and a restart. Clearing one leaves the other lying, which is
 *      exactly how this looked fixed in production and stayed broken in dev.
 *
 * So the rule is about WHAT the payload contains, not how expensive it is:
 *
 *   Class A — LIVE. Anything carrying a COUNT or an OPERATIONAL SETTING.
 *             `no-store`. Never cached on this side at any TTL. The API caches
 *             these itself (60s, server-side), where the TTL is not frozen
 *             into a file and can be invalidated with `cache:clear`.
 *
 *   Class B — SHORT. Listing and business content: prices, availability,
 *             reviews, curated rails. Wrong for minutes is tolerable; wrong
 *             for an hour is not.
 *
 *   Class C — LONG. Editorial and reference data that changes when someone
 *             deliberately edits it.
 *
 * If you cannot decide, it is Class A. A redundant request costs 20ms; a
 * frozen business number costs a support ticket and an afternoon.
 */

/**
 * Class A. Never cached by Next — the backend owns the caching.
 *
 * Not a TTL of zero: `no-store` skips the data cache entirely, so there is no
 * entry on disk to go stale, and no second cache to remember to clear.
 */
const LIVE = { live: true } as const;

/** Class B. Content that changes on its own; minutes, not hours. */
const SHORT = { revalidate: 60 } as const;

/** Class B. Curated rails, recomputed on a schedule rather than per write. */
const RAIL = { revalidate: 300 } as const;

/** Class C. Editorial and reference data, changed only by a deliberate edit. */
const LONG = { revalidate: 3600 } as const;

type PublicOptions = {
  revalidate?: number;
  /** Class A: skip Next's data cache entirely. */
  live?: boolean;
};

async function publicGet<T>(
  path: string,
  query?: Record<string, QueryValue | QueryValue[]>,
  options: PublicOptions = {},
): Promise<T> {
  if (options.live) {
    return request<T>(`${API_BASE}${path}${buildQuery(query)}`, { cache: "no-store" });
  }

  return request<T>(`${API_BASE}${path}${buildQuery(query)}`, {
    cache: "force-cache",
    // @ts-expect-error — `next` is a Next.js extension to RequestInit.
    next: { revalidate: options.revalidate ?? SHORT.revalidate },
  });
}

export function getListings(
  query: Record<string, QueryValue | QueryValue[]>,
  options?: PublicOptions,
): Promise<Paginated<ApiListing>> {
  // Class B. Search results: a listing that sold is worse than a slow page.
  return publicGet<Paginated<ApiListing>>("/listings", query, options ?? SHORT);
}

export function getListing(slug: string): Promise<Envelope<ApiListing>> {
  // Class B, at the short end: this payload IS the price and the
  // availability, and it is the page a buyer decides on.
  return publicGet<Envelope<ApiListing>>(`/listings/${slug}`, undefined, { revalidate: 30 });
}

export function getSimilarListings(slug: string): Promise<{ data: ApiListing[] }> {
  // Class B — "nearby listings".
  return publicGet<{ data: ApiListing[] }>(`/listings/${slug}/similar`, undefined, SHORT);
}

export function getListingReviews(slug: string): Promise<Paginated<ApiReview>> {
  // Class B. A seller answering a review wants to see it published.
  return publicGet<Paginated<ApiReview>>(`/listings/${slug}/reviews`, { per_page: 10 }, SHORT);
}

/**
 * A cached discovery rail that refuses to serve a cached EMPTY result.
 *
 * This is the fix for a homepage that rendered three sections instead of six.
 * Next's data cache lives in `.next/cache` and SURVIVES A REBUILD, so when the
 * API briefly returned `{"data":[]}` — the window before production's own cache
 * was cleared — that emptiness was written to disk and kept being served. A
 * redeploy did not shift it, and `revalidate` only refreshes after the window
 * expires and only on a request that happens to land after it.
 *
 * An empty rail is almost never a durable truth on a marketplace with stock. So
 * a cached empty is treated as a cache miss and re-fetched once, uncached. The
 * extra request happens ONLY in the broken case; a genuinely empty rail costs
 * one uncached round trip and still renders nothing, which is correct.
 */
async function railGet<T extends { data: unknown[] }>(
  path: string,
  query: Record<string, QueryValue | QueryValue[]>,
): Promise<T> {
  const cached = await publicGet<T>(path, query, RAIL);
  if (cached.data.length > 0) return cached;

  return publicGet<T>(path, query, LIVE);
}

export function getTrending(): Promise<{ data: ApiListing[] }> {
  // Class B — homepage rail, recomputed on a schedule rather than per write.
  return railGet<{ data: ApiListing[] }>("/listings/trending", { limit: 8 });
}

export function getFeatured(): Promise<{ data: ApiListing[] }> {
  // Class B — homepage rail.
  return railGet<{ data: ApiListing[] }>("/listings/featured", { limit: 8 });
}

export function getRecommended(): Promise<{ data: ApiListing[] }> {
  // Personalised for a signed-in caller; on the server there is no token, so
  // this is the popular fallback the API documents.
  return railGet<{ data: ApiListing[] }>("/listings/recommended", { limit: 4 });
}

/**
 * CLASS A — the category tree, with a live `listing_count` on every node.
 *
 * `options` exists for exactly one caller: the sitemap, which needs the slugs
 * and does not render a single count. Everything else takes the default and
 * gets live numbers. If you are reaching for the override, check whether your
 * page shows a count first.
 */
export function getCategories(options?: PublicOptions): Promise<{ data: ApiCategory[] }> {
  /*
   * NOT CACHED HERE. This one is worth reading before changing.
   *
   * The payload carries `listing_count` for all 86 categories, and that number
   * changes every time a listing is published, sold or moved. It was cached at
   * `revalidate: 3600`, and the result was every subcategory on the homepage
   * reading "0 Listings" against a database holding two hundred.
   *
   * Lowering the window did not fix it, for a reason worth remembering: a Next
   * data-cache entry stores the TTL IT WAS WRITTEN WITH. Entries already on
   * disk kept their hour no matter what this file said. And there are TWO such
   * caches — `.next/cache/fetch-cache` for a production build and
   * `.next/dev/cache/fetch-cache` for `next dev` — both of which survive a
   * rebuild and a restart. Clearing one leaves the other lying, which is how
   * this looked fixed in production while still showing zeroes in dev.
   *
   * So the counts are not cached on this side at all. They do not need to be:
   * `CategoryListingCounts` caches them for 60 seconds SERVER-side, where the
   * TTL is not frozen into a file and can be invalidated. The endpoint answers
   * from that cache in 15–25 ms.
   *
   * One cache, in one place, that can be cleared. Two caches with independent
   * lifetimes is what produced the bug.
   */
  return publicGet<{ data: ApiCategory[] }>("/categories", undefined, options ?? LIVE);
}

/**
 * CLASS C — the same tree, cached, for pages that show no counts.
 *
 * A separate FUNCTION rather than an argument to `getCategories`, because an
 * optional flag is exactly the kind of thing that gets copied onto a page that
 * DOES render counts, quietly reintroducing the bug. The name is the warning.
 *
 * ⚠ `listing_count` on this response may be up to an hour old. Do not render
 * it. Every current caller reads only `slug`, `name` and `image_url`.
 */
export function getCategoryTaxonomy(): Promise<{ data: ApiCategory[] }> {
  return publicGet<{ data: ApiCategory[] }>("/categories", undefined, LONG);
}

export function getBusinesses(
  query: Record<string, QueryValue | QueryValue[]>,
): Promise<Paginated<ApiBusiness>> {
  // Class B. Carries meta.total, which the About page renders.
  return publicGet<Paginated<ApiBusiness>>("/businesses", query, SHORT);
}

export function getBusiness(slug: string): Promise<Envelope<ApiBusiness>> {
  // Class B. Carries the rating and the active-listing count.
  return publicGet<Envelope<ApiBusiness>>(`/businesses/${slug}`, undefined, { revalidate: 120 });
}

export function getBusinessListings(slug: string, page = 1): Promise<Paginated<ApiListing>> {
  // Class B.
  return publicGet<Paginated<ApiListing>>(`/businesses/${slug}/listings`, { page, per_page: 12 }, SHORT);
}

export function getBusinessReviews(slug: string): Promise<Paginated<ApiReview>> {
  // Class B.
  return publicGet<Paginated<ApiReview>>(`/businesses/${slug}/reviews`, { per_page: 10 }, SHORT);
}

export function getSimilarBusinesses(slug: string): Promise<{ data: ApiBusiness[] }> {
  // Class B — "nearby businesses".
  return publicGet<{ data: ApiBusiness[] }>(`/businesses/${slug}/similar`, { limit: 6 }, RAIL);
}

export function getPlaces(
  query: Record<string, QueryValue | QueryValue[]>,
): Promise<Paginated<ApiPlace>> {
  // Class B. Carries meta.total, rendered on the About page.
  return publicGet<Paginated<ApiPlace>>("/public-places", query, SHORT);
}

export function getPlace(slug: string): Promise<Envelope<ApiPlace>> {
  // Class C. One curated place; changes only when an admin edits it.
  return publicGet<Envelope<ApiPlace>>(`/public-places/${slug}`, undefined, LONG);
}

export type ApiPlaceCategory = {
  slug: string;
  name: string;
  icon: string | null;
  /* Both are served by the API and both are what the original card rendered. */
  place_count: number;
  image_url: string | null;
};

/**
 * CLASS A — place categories, with a live `place_count` on each.
 *
 * For pages that render the count. See {@link getPlaceCategoryTaxonomy} for
 * the cached variant used by pages that only need names and artwork.
 */
export function getPlaceCategories(
  options?: PublicOptions,
): Promise<{ data: ApiPlaceCategory[] }> {
  /*
   * CLASS A. Carries `place_count`, which the directory renders as "N nearby"
   * directly above the list it describes. Same failure mode as the category
   * counts, and no reason to accept it twice.
   */
  return publicGet("/public-places/categories", undefined, options ?? LIVE);
}

/**
 * CLASS C — place categories, cached, for pages that show no count.
 *
 * ⚠ `place_count` here may be up to an hour old. Do not render it.
 */
export function getPlaceCategoryTaxonomy(): Promise<{ data: ApiPlaceCategory[] }> {
  return publicGet<{ data: ApiPlaceCategory[] }>(
    "/public-places/categories",
    undefined,
    LONG,
  );
}

/**
 * Site-wide settings the admin portal owns.
 *
 * A flat, dotted-key bag exactly as the API returns it. Every value is
 * nullable: a setting that has never been filled in comes back as null, and
 * each caller decides its own fallback rather than this layer inventing one.
 */
export type PublicSettings = Partial<Record<string, string | boolean | null>>;

export function getPublicSettings(): Promise<{ data: PublicSettings }> {
  /*
   * CLASS B, at 60 seconds — revised down from Class A, deliberately.
   *
   * Making this `no-store` was correct in isolation and wrong in context: the
   * footer reads it, the footer is in the ROOT LAYOUT, and so a live read here
   * made every page in the application dynamic — including /login, /register
   * and the editorial pages, none of which have any reason to be.
   *
   * A minute is the right window. An operator flipping a feature flag or
   * correcting the support number sees it inside a minute, which is well
   * within the time it takes them to reload and check; and the whole app keeps
   * static rendering. Trading the entire site's render strategy for 59 seconds
   * of settings freshness was not a trade worth making.
   */
  return publicGet("/settings/public", undefined, SHORT);
}

/** Reads a string setting, treating empty strings as absent. */
export function settingText(settings: PublicSettings, key: string): string | null {
  const value = settings[key];
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

export type ApiBusinessType = {
  value: string;
  label: string;
  description: string | null;
  /** Which listing verticals this trade sells into — used to borrow a hero image. */
  category_slugs: string[];
};

/**
 * CLASS C — revised down from Class A.
 *
 * This is a PHP enum with labels; it changes on a deploy, not on a write, and
 * it carries no count. Its only taxonomy-dependent field is `category_slugs`,
 * read beside `getCategoryTaxonomy()` — also Class C, so the two stay in step.
 * Holding it at Class A cost /businesses its ISR for no freshness anybody
 * could observe.
 */
export function getBusinessTypes(): Promise<{ data: ApiBusinessType[] }> {
  return publicGet("/business-types", undefined, LONG);
}

export function getFaqs(): Promise<{ data: { question: string; answer: string }[] }> {
  // Class C — editorial.
  return publicGet("/faqs", undefined, LONG);
}

export function getPopularSearches(): Promise<{ data: { query: string; searches: number }[] }> {
  // Class B — a homepage section, recomputed from search history.
  return publicGet("/search/popular", { limit: 8 }, RAIL);
}

/**
 * CLASS B — SAKA's own advertisements for one placement.
 *
 * Sixty seconds. Ads are commercial state that a human changes deliberately —
 * pausing a campaign because an advertiser called — and a stale slot keeps
 * serving something SAKA has agreed to stop showing. A minute is short enough
 * that a pause is honoured while somebody is still on the phone, and long
 * enough that the ad query does not run on every page view of a busy site.
 *
 * NOT Class A despite carrying commercial state: the impression beacon fires
 * from the browser per render, so a cached serve list does not cause a cached
 * impression count. What is cached is only WHICH ad to show.
 */
export function getAds(
  placement: AdPlacement,
  context: { category?: string | null; region?: string | null } = {},
): Promise<{ data: ApiAdCreative[]; meta: { placement: ApiAdPlacementMeta } }> {
  return publicGet<{ data: ApiAdCreative[]; meta: { placement: ApiAdPlacementMeta } }>(
    "/ads",
    {
      placement,
      ...(context.category ? { category: context.category } : {}),
      ...(context.region ? { region: context.region } : {}),
    },
    { revalidate: 60 },
  );
}

/**
 * CLASS A — a specialist's service menu.
 *
 * Never cached. A specialist deactivating a service has decided to stop taking
 * bookings for it, and a cached menu would keep offering something they have
 * withdrawn — which ends in an appointment they refuse.
 */
export function getSpecialistServices(
  slug: string,
): Promise<{ data: ApiSpecialistService[]; meta: { timezone: string } }> {
  return publicGet<{ data: ApiSpecialistService[]; meta: { timezone: string } }>(
    `/specialists/${slug}/services`,
    undefined,
    LIVE,
  );
}

/**
 * CLASS A — bookable times.
 *
 * The one payload on this API where a cache would be actively harmful: a slot
 * taken thirty seconds ago must not still be offered, or the customer fills in
 * the whole form and is refused at the last step.
 */
export function getSpecialistSlots(
  slug: string,
  serviceUuid: string,
  days = 14,
): Promise<{
  data: ApiSlotDay[];
  meta: { timezone: string; has_availability: boolean; service: ApiSpecialistService };
}> {
  return publicGet(
    `/specialists/${slug}/services/${serviceUuid}/slots`,
    { days },
    LIVE,
  );
}
