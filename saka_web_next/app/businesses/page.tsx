import type { Metadata } from "next";
import { Suspense } from "react";

import { BusinessBrowser } from "@/components/businesses/BusinessBrowser";
import { SearchHero } from "@/components/search/SearchHero";
import { getBusinessTypes, getCategoryTaxonomy } from "@/lib/api/public";

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
  alternates: { canonical: "/businesses" },
  title: "Businesses",
  description: "Find shops, landlords, hotels, clinics and service providers near you on SAKA.",
};

export default async function BusinessesPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const [types, categories, params] = await Promise.all([
    getBusinessTypes().catch(() => ({ data: [] })),
    getCategoryTaxonomy().catch(() => ({ data: [] })),
    searchParams,
  ]);

  const typeValue = typeof params.business_type === "string" ? params.business_type : undefined;
  const type = types.data.find((item) => item.value === typeValue);

  /*
   * Business types carry no artwork of their own, but each one declares the
   * listing verticals it trades in — so a shop borrows the Electronics photo
   * and a landlord borrows Property. Still backend-driven; just one hop.
   */
  const heroImage =
    categories.data.find((category) => category.slug === type?.category_slugs[0])?.image_url ?? null;

  return (
    <>
      {/* Server-rendered so the h1 and the description are in the HTML. */}
      <SearchHero
        title={type ? type.label : "Businesses on SAKA"}
        trail={type ? ["Businesses", type.label] : ["Businesses"]}
        image={heroImage}
        description={
          type?.description ??
          "Shops, landlords, hotels, clinics and services — with their hours, location and reviews."
        }
      />

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
        <BusinessBrowser />
      </Suspense>
    </>
  );
}
