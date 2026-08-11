import Link from "next/link";
import {
  ArrowUpRight,
  Bath,
  BadgeCheck,
  Bed,
  MapPin,
  Ruler,
} from "lucide-react";

import { FavoriteButton } from "@/components/listings/FavoriteButton";
import { formatDistance, type ListingView } from "@/lib/view-models";
import { IMAGE_SIZES, imageSrcSet } from "@/lib/media";
import { SafeImage } from "@/components/ui/SafeImage";

/**
 * The listing card, ported pixel-for-pixel from the original.
 *
 * Every class, size and colour is unchanged: the 435px card, the 205px image,
 * the two-line clamped title, the dashed divider, the teal price.
 *
 * The only differences are where the data comes from and two honesty fixes the
 * real API forced:
 *
 *   - the bed/bath/area row now omits a figure the listing does not have,
 *     rather than printing "0 Beds" for a plot of land;
 *   - the price falls back to "Price on request" instead of "TZS 0".
 *
 * Both were unreachable with the old hardcoded data, where every listing was
 * an apartment with a price.
 */
export function ListingCard({ p }: { p: ListingView }) {
  const distance = formatDistance(p.distanceKm);

  return (
    /*
     * A CONTAINER query, not a viewport one.
     *
     * The card's fixed 435px height and 18px type were designed for a
     * three-across desktop grid. Two-across on a phone puts the same card in a
     * ~160px column, where the title box, the "VERIFIED" pill and the price row
     * all collide. Sizing against the card's OWN width rather than the screen's
     * means one rule covers every place this card is used — the grid, the map
     * sidebar, "similar listings" — instead of a viewport breakpoint that is
     * wrong the moment the card appears in a narrower column on a wide screen.
     */
    <div className="@container w-full">
      <div className="group flex h-[380px] w-full flex-col overflow-hidden rounded-[8px] border border-[#DCE6EF] bg-white transition-all duration-300 hover:-translate-y-1 hover:border-[#0B8E95]/40 hover:shadow-[0_18px_40px_-18px_rgba(6,28,63,0.28)] focus-within:ring-2 focus-within:ring-[#0B8E95]/30 @[240px]:h-[435px]">
        <Link
          href={`/listings/${p.slug}`}
          className="relative block h-[150px] overflow-hidden @[240px]:h-[205px]"
        >
          <SafeImage
            src={p.image}
            /*
             * `src` stays the original so the placeholder path and any listing
             * whose variants are still being generated keep working unchanged.
             * The srcset is what the browser actually uses when it exists, and
             * it is the difference between a 435px card pulling a 1600px
             * original and pulling the 400px rendition already sitting on disk.
             */
            srcSet={imageSrcSet(p.imageMedia)}
            sizes={IMAGE_SIZES.card}
            alt={p.imageAlt}
            className="w-full h-full object-cover transition-transform duration-700 ease-out transform-gpu group-hover:scale-[1.10]"
            fallbackClassName="h-full w-full text-sm text-[#8B95A7]"
            fallback="No photo yet"
          />

          <FavoriteButton
            kind="listing"
            slug={p.slug}
            label={p.title}
            className="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-full bg-white text-[#F07F7F] transition-all duration-300 hover:bg-[#0B8E95] hover:text-white @[240px]:right-3 @[240px]:top-3 @[240px]:h-9 @[240px]:w-9"
          />

          {p.subcategory && (
            <span className="absolute bottom-2 left-2 max-w-[calc(100%-1rem)] truncate rounded-full bg-[#0B8E95] px-3 py-0.5 text-[11px] font-semibold text-white @[240px]:bottom-3 @[240px]:left-3 @[240px]:px-4 @[240px]:py-1 @[240px]:text-[12px]">
              {p.subcategory}
            </span>
          )}

          {p.verified && (
            <div className="absolute left-2 top-2 z-20 flex items-center gap-1.5 rounded-full border border-[#0B8E95] bg-white px-2 py-1 shadow-md @[240px]:bottom-3 @[240px]:left-auto @[240px]:right-3 @[240px]:top-auto @[240px]:gap-2 @[240px]:px-3 @[240px]:py-1.5">
              <BadgeCheck
                className="h-4 w-4 text-[#0B8E95] @[240px]:h-5 @[240px]:w-5"
                strokeWidth={2.5}
              />
              <span className="text-[10px] font-bold uppercase tracking-wide text-[#0B8E95] @[240px]:text-[11px]">
                VERIFIED
              </span>
            </div>
          )}
        </Link>

        <div className="flex flex-1 flex-col px-3 pb-3 pt-3 @[240px]:px-4 @[240px]:pb-4 @[240px]:pt-4">
          <Link href={`/listings/${p.slug}`}>
            <h3
              className="h-[44px] overflow-hidden text-[15px] font-bold leading-[22px] text-[#17233C] transition-colors duration-300 group-hover:text-[#0B8E95] @[240px]:h-[58px] @[240px]:text-[18px] @[240px]:leading-7"
              style={{
                display: "-webkit-box",
                WebkitLineClamp: 2,
                WebkitBoxOrient: "vertical",
              }}
            >
              {p.title}
            </h3>
          </Link>

          <p className="mt-2 flex items-center gap-1 truncate text-[12px] text-[#6B7280] @[240px]:text-[14px]">
            <MapPin className="h-4 w-4 shrink-0 text-[#0B8E95]" />
            <span className="truncate">
              {distance ? `${p.location} · ${distance}` : p.location}
            </span>
          </p>

          <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-[#6B7280] @[240px]:text-[13px]">
            {p.beds !== null && (
              <span className="flex items-center gap-1 whitespace-nowrap">
                <Bed className="h-4 w-4 text-[#0B8E95]" />
                {p.beds} Bed{p.beds !== 1 && "s"}
              </span>
            )}
            {p.bathrooms !== null && (
              <span className="flex items-center gap-1 whitespace-nowrap">
                <Bath className="h-4 w-4 text-[#0B8E95]" />
                {p.bathrooms} Bathroom{p.bathrooms !== 1 && "s"}
              </span>
            )}
            {p.sqft !== null && (
              <span className="flex items-center gap-1 whitespace-nowrap">
                <Ruler className="h-4 w-4 text-[#0B8E95]" />
                {p.sqft.toLocaleString()} sqft
              </span>
            )}
          </div>

          <div className="mt-auto flex flex-col">
            <div className="mt-2 border-t border-dashed border-[#D8E2EB]" />

            <div className="flex items-center justify-between pt-3">
              <span className="text-[14px] font-bold text-[#0B8E95] @[240px]:text-[18px]">
                {p.price === null
                  ? "Price on request"
                  : `${p.currency} ${p.price.toLocaleString()}`}
              </span>

              <Link
                href={`/listings/${p.slug}`}
                className="group/explore hidden min-h-11 items-center gap-1 text-[15px] font-semibold text-[#17233C] transition-colors duration-300 hover:text-[#0B8E95] @[240px]:inline-flex"
              >
                <span className="transition-colors duration-300 group-hover/explore:text-[#0B8E95]">
                  Explore Now
                </span>
                <ArrowUpRight className="h-4 w-4 transition-all duration-300 group-hover/explore:text-[#0B8E95] group-hover/explore:translate-x-1 group-hover/explore:-translate-y-1" />
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
