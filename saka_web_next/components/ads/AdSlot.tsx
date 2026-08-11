import { AdSenseUnit } from "@/components/ads/AdSenseUnit";
import { SponsoredBanner } from "@/components/ads/SponsoredBanner";
import { getAds } from "@/lib/api/public";
import { ADSENSE_ENABLED, ADSENSE_SLOTS } from "@/lib/config";
import type { AdPlacement, ApiAdCreative, ApiAdPlacementMeta } from "@/lib/types";

/**
 * An advertising slot.
 *
 * A SERVER component. It resolves what to show while the page is being built,
 * so the HTML that reaches the browser is final and nothing appears late and
 * pushes content down. That is the whole reason this is not a hook.
 *
 * THE WATERFALL, in order:
 *
 *   1. SAKA's own sold inventory. Worth more per impression than a network
 *      unit, and it looks like the product rather than like an advert bolted
 *      onto it.
 *   2. Google AdSense, if a publisher id AND a slot id for this placement are
 *      both configured.
 *   3. Nothing at all.
 *
 * (3) IS THE COMMON CASE and must stay cheap and silent. A marketplace with no
 * campaigns sold against a slot renders no box, no border, no "advertisement"
 * caption and no reserved whitespace — the sections either side simply sit next
 * to each other as though this component were not in the tree. Anything else
 * turns an unsold slot into visible clutter on every page.
 *
 * NO DEVELOPMENT PLACEHOLDER IS EVER RENDERED IN PRODUCTION. The dev-only box
 * below is gated on NODE_ENV, which is compiled to a constant, so the branch is
 * removed from the production bundle entirely rather than merely not taken.
 */
export async function AdSlot({
  placement,
  category,
  region,
  className = "",
}: {
  placement: AdPlacement;
  /** The category being browsed, for targeting. Slug, not id. */
  category?: string | null;
  /** The region being filtered on, for targeting. Slug, not id. */
  region?: string | null;
  className?: string;
}) {
  let creatives: ApiAdCreative[] = [];
  let meta: ApiAdPlacementMeta | null = null;

  try {
    const response = await getAds(placement, { category, region });
    creatives = response.data ?? [];
    meta = response.meta?.placement ?? null;
  } catch {
    /*
     * An advert must never take a page down with it.
     *
     * This runs during server render, so an unhandled throw here is a 500 on
     * the marketplace itself — the homepage failing because the ad service is
     * slow. Swallowed, and the slot renders nothing.
     */
    return null;
  }

  if (creatives.length > 0) {
    const variant = placement === "homepage_hero" ? "hero" : "strip";

    return (
      <div className={className}>
        {/*
          * A LIST, not a stack of divs. Several inline units on a long results
          * page are a set of related items, and a screen-reader user gets to
          * know how many there are and skip past them.
          */}
        <ul className="flex flex-col gap-4">
          {creatives.map((creative) => (
            <li key={creative.uuid}>
              <SponsoredBanner creative={creative} placement={placement} variant={variant} />
            </li>
          ))}
        </ul>
      </div>
    );
  }

  const slotId = ADSENSE_SLOTS[placement];

  if (ADSENSE_ENABLED && slotId && meta) {
    return (
      <div className={className}>
        <AdSenseUnit slotId={slotId} aspectRatio={meta.aspect_ratio} />
      </div>
    );
  }

  /*
   * Development only. Makes an unsold slot visible while building a page, so a
   * placement that is in the tree but never fills is obvious rather than
   * invisible. Compiled out of production by the NODE_ENV constant.
   */
  if (process.env.NODE_ENV === "development") {
    return (
      <div className={className}>
        <div className="flex h-12 w-full items-center justify-center rounded-[8px] border border-dashed border-[#DCE6EF] text-[11px] font-semibold uppercase tracking-wider text-[#B4BECC]">
          Ad slot · {placement} · unsold
        </div>
      </div>
    );
  }

  return null;
}
