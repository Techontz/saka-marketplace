import type { MetadataRoute } from "next";

import {
  getBusinesses,
  getCategoryTaxonomy,
  getListings,
  getPlaceCategoryTaxonomy,
} from "@/lib/api/public";
import { SITE_URL } from "@/lib/config";

/**
 * The sitemap.
 *
 * Generated from the API rather than hand-written, so a new listing is
 * discoverable without a deploy. Capped at 5,000 listings: a sitemap may hold
 * 50,000 URLs, but this is regenerated hourly and the cap keeps that cheap —
 * splitting into an index is the next step when the catalogue outgrows it.
 *
 * Every fetch is guarded. A sitemap that throws returns a 500 to the crawler,
 * which is worse than a sitemap missing one section.
 */
export const revalidate = 3600;

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const staticRoutes: MetadataRoute.Sitemap = [
    { url: SITE_URL, changeFrequency: "daily", priority: 1 },
    { url: `${SITE_URL}/listings`, changeFrequency: "hourly", priority: 0.9 },
    { url: `${SITE_URL}/businesses`, changeFrequency: "daily", priority: 0.8 },
    { url: `${SITE_URL}/public-places`, changeFrequency: "weekly", priority: 0.6 },
    { url: `${SITE_URL}/about`, changeFrequency: "monthly", priority: 0.3 },
    { url: `${SITE_URL}/contact`, changeFrequency: "monthly", priority: 0.3 },
  ];

  const [listings, businesses, categories, placeCategories] = await Promise.all([
    getListings({ per_page: 100, sort: "newest" }).catch(() => null),
    getBusinesses({ per_page: 100 }).catch(() => null),
    // Slugs only. The cached readers keep this route's hourly page cache
    // meaningful instead of a no-store fetch silently defeating it.
    getCategoryTaxonomy().catch(() => null),
    getPlaceCategoryTaxonomy().catch(() => null),
  ]);

  const listingUrls: MetadataRoute.Sitemap = (listings?.data ?? []).map((listing) => ({
    url: `${SITE_URL}/listings/${listing.slug}`,
    lastModified: listing.published_at ? new Date(listing.published_at) : undefined,
    changeFrequency: "weekly",
    priority: 0.7,
  }));

  const businessUrls: MetadataRoute.Sitemap = (businesses?.data ?? []).map((business) => ({
    url: `${SITE_URL}/businesses/${business.slug}`,
    changeFrequency: "weekly",
    priority: 0.6,
  }));

  const categoryUrls: MetadataRoute.Sitemap = (categories?.data ?? []).flatMap((category) => [
    { url: `${SITE_URL}/listings?category=${category.slug}`, changeFrequency: "daily" as const, priority: 0.5 },
    ...(category.children ?? []).map((child) => ({
      url: `${SITE_URL}/listings?category=${category.slug}&subcategory=${child.slug}`,
      changeFrequency: "daily" as const,
      priority: 0.4,
    })),
  ]);

  const placeUrls: MetadataRoute.Sitemap = (placeCategories?.data ?? []).map((category) => ({
    url: `${SITE_URL}/public-places/${category.slug}`,
    changeFrequency: "monthly",
    priority: 0.4,
  }));

  return [...staticRoutes, ...listingUrls, ...businessUrls, ...categoryUrls, ...placeUrls];
}
