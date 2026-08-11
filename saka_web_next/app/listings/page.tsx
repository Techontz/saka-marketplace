import type { Metadata } from "next";
import { Suspense } from "react";

import { AdSlot } from "@/components/ads/AdSlot";
import { ListingsBrowser } from "@/components/listings/ListingsBrowser";
import { SearchHero } from "@/components/search/SearchHero";
import { getAds, getCategories } from "@/lib/api/public";

export const metadata: Metadata = {
  /*
   * Canonical URL.
   *
   * `metadataBase` in the root layout supplies the origin, so a relative path
   * is enough. Without this, every filtered and sorted variant of a listing
   * page — ?category=…&sort=…&page=2 — is a separate indexable URL competing
   * with the same content, which is how a catalogue site dilutes its own
   * ranking.
   */
  alternates: { canonical: "/listings" },
  title: "Property Listings",
  description: "Browse SAKA property listings for rent, sale, and lease.",
};

/*
 * ROUTE-LEVEL CACHE — the second freeze layer, and the one that is easy to miss.
 *
 * `export const revalidate` caches the RENDERED PAGE independently of the
 * per-fetch policy in lib/api/public.ts. An hour here would have re-frozen the
 * category counts even after those fetches were made live.
 *
 * Dynamic because the filter dropdowns show a live count per category
 * (Class A). Only three routes in the app are dynamic for this reason; every
 * other page uses `getCategoryTaxonomy()` and stays statically rendered.
 */
export const revalidate = 0;

/**
 * The hero — and with it the h1 and the breadcrumb — is rendered on the SERVER
 * from `searchParams`, so a crawler and a screen reader both get a real page
 * title instead of an empty shell. The filter card, sidebar and grid stay
 * client-side because they are interactive and URL-driven.
 */
export default async function ListingsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const [categories, params] = await Promise.all([
    getCategories().catch(() => ({ data: [] })),
    searchParams,
  ]);

  const categorySlug = typeof params.category === "string" ? params.category : undefined;
  const subcategorySlug = typeof params.subcategory === "string" ? params.subcategory : undefined;
  const query = typeof params.q === "string" ? params.q : undefined;
  const regionSlug = typeof params.region === "string" ? params.region : undefined;

  /*
   * Inline advertisements, fetched HERE rather than inside the browser.
   *
   * `ListingsBrowser` is client-side, so an ad query living in it would resolve
   * on its own schedule and push strips into a grid somebody is already
   * reading. Fetched on the server they arrive with the first paint.
   *
   * Targeted with the most specific category the visitor chose, so a campaign
   * bought against vehicles appears on a vehicle search and not on a property
   * one. The `.catch` matters: an advert must never take the marketplace down
   * with it.
   */
  const inlineAds = await getAds("listings_inline", {
    category: subcategorySlug ?? categorySlug,
    region: regionSlug,
  })
    .then((response) => response.data)
    .catch(() => []);

  const category = categories.data.find((item) => item.slug === categorySlug);
  const subcategory = category?.children?.find((child) => child.slug === subcategorySlug);

  /*
   * The original titled the page with the most specific thing the visitor
   * chose \u2014 subcategory over category over the generic heading \u2014 and its
   * breadcrumb read "Home \u203a Property \u203a Apartments". Both are reproduced here
   * rather than collapsing everything to the vertical.
   */
  const title =
    subcategory?.name ?? category?.name ?? (query ? `\u201c${query}\u201d` : "Marketplace Listings");

  const trail = [
    ...(category ? [category.name] : []),
    subcategory?.name ?? "Listings",
  ];

  /*
   * Most specific image wins. Only the verticals carry one today, so a
   * subcategory inherits its parent's photo rather than dropping straight to
   * the generic hero — "Apartments" under "Property" should still look like
   * property.
   */
  const heroImage = subcategory?.image_url ?? category?.image_url ?? null;

  return (
    <>
      <SearchHero title={title} trail={trail} image={heroImage} />

      <div className="mx-auto w-full max-w-7xl px-6 pt-6">
        {/* Below the filters, above the results. One strip, never more. */}
        <AdSlot placement="listings_top" category={subcategorySlug ?? categorySlug} region={regionSlug} />
      </div>

      {/*
        * A fallback that RESERVES HEIGHT, not `null`.
        *
        * These browsers are client components behind Suspense, so the
        * server-rendered HTML was the hero and nothing else — the footer
        * painted at y≈443, then hydration injected ~2,600px of results and
        * shoved it down. Measured at CLS 0.54, five times the "needs
        * improvement" threshold, and the single worst layout metric on the site.
        *
        * Reserving a screenful means the footer starts below the fold, so the
        * content arriving underneath it shifts nothing the visitor can see.
        * `min-h-screen` rather than a pixel count: the right reservation is
        * "at least a viewport", whatever the viewport happens to be.
        */}
      <Suspense fallback={<div className="min-h-screen" aria-hidden="true" />}>
        <ListingsBrowser categories={categories.data} inlineAds={inlineAds} />
      </Suspense>
    </>
  );
}
