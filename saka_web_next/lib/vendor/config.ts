/**
 * Public marketplace origin, for "view on the marketplace" links.
 *
 * NEXT_PUBLIC_ because it is only ever used to build a link the browser
 * follows — unlike SAKA_API_URL, which is server-side so the API can sit on a
 * private network.
 *
 * Optional now. The vendor portal used to be its own deployment, where an
 * unset value genuinely left it with no way to build a marketplace link — so
 * the button was hidden rather than pointed at `undefined/listings/x`. It now
 * ships alongside the storefront on one origin, so a relative path is always
 * correct and the variable is only needed to link at a DIFFERENT origin than
 * the one serving the portal.
 */
export const MARKETPLACE_URL = (process.env.NEXT_PUBLIC_MARKETPLACE_URL ?? "").replace(/\/$/, "");

/**
 * Never null any more, but the return type stays nullable: the call sites
 * already branch on it, and widening a contract is not what this change is for.
 */
export function marketplaceListingUrl(slug: string): string | null {
  return `${MARKETPLACE_URL}/listings/${slug}`;
}
