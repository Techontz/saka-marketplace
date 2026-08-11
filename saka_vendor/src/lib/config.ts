/**
 * Public marketplace origin, for "view on the marketplace" links.
 *
 * NEXT_PUBLIC_ because it is only ever used to build a link the browser
 * follows — unlike SAKA_API_URL, which is server-side so the API can sit on a
 * private network.
 *
 * Empty when unconfigured, and every call site checks: a "View" button that
 * navigates to `undefined/listings/x` is worse than no button.
 */
export const MARKETPLACE_URL = (process.env.NEXT_PUBLIC_MARKETPLACE_URL ?? "").replace(/\/$/, "");

export function marketplaceListingUrl(slug: string): string | null {
  return MARKETPLACE_URL ? `${MARKETPLACE_URL}/listings/${slug}` : null;
}
