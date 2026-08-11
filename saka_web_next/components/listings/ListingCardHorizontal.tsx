import Link from "next/link";
import { ArrowUpRight, Bath, Bed, Car, Check, DoorOpen, LayoutGrid, MapPin, Ruler } from "lucide-react";

import { FavoriteButton } from "@/components/listings/FavoriteButton";
import type { ListingView } from "@/lib/view-models";
import { IMAGE_SIZES, imageSrcSet } from "@/lib/media";
import { SafeImage } from "@/components/ui/SafeImage";

/** Ported unchanged from the original `ListingCardHorizontal`. */

const badgeColors: Record<NonNullable<ListingView["purpose"]>, string> = {
  Rent: "bg-sky-500",
  Sale: "bg-teal",
  Lease: "bg-orange",
  Hire: "bg-navy",
};

export function ListingCardHorizontal({ p }: { p: ListingView }) {
  return (
    <div className="bg-white rounded-[5px] border border-border overflow-hidden group flex flex-col md:flex-row animate-fade-up">
      <Link
        href={`/listings/${p.slug}`}
        className="relative overflow-hidden block m-3 rounded-[5px] md:w-[42%] shrink-0"
      >
        <SafeImage
          src={p.image}
          srcSet={imageSrcSet(p.imageMedia)}
          sizes={IMAGE_SIZES.card}
          alt={p.imageAlt}
          className="w-full h-56 md:h-full min-h-[220px] object-cover transition-transform duration-700 ease-out group-hover:scale-110"
          fallbackClassName="h-56 md:h-full min-h-[220px] w-full text-sm text-muted-foreground"
          fallback="No photo yet"
        />

        {p.purpose && (
          <span
            className={`absolute top-3 left-3 ${badgeColors[p.purpose]} text-white text-xs font-semibold px-3 py-1 rounded-[5px]`}
          >
            {p.purpose}
          </span>
        )}

        <FavoriteButton
          kind="listing"
          slug={p.slug}
          label={p.title}
          className="absolute top-3 right-3 h-9 w-9 rounded-full bg-white/90 flex items-center justify-center text-orange hover:scale-110 active:scale-90 transition"
        />

        {p.verified && (
          <span className="absolute bottom-3 left-3 bg-teal text-white text-[10px] font-bold px-2 py-1 rounded-[5px] flex items-center gap-1 uppercase tracking-wide">
            <Check className="h-3 w-3" /> Verified
          </span>
        )}
      </Link>

      <div className="p-5 md:py-6 md:pr-6 flex flex-col flex-1">
        <Link href={`/listings/${p.slug}`}>
          <h3 className="text-base font-bold text-navy line-clamp-2 mb-2 transition-colors duration-200 group-hover:text-teal">
            {p.title}
          </h3>
        </Link>

        <p className="text-sm text-muted-foreground flex items-center gap-1 mb-4">
          <MapPin className="h-4 w-4 text-teal" /> {p.location}
        </p>

        <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground mb-4">
          {p.beds !== null && (
            <span className="flex items-center gap-1">
              <Bed className="h-4 w-4 text-teal" /> {p.beds} Bed{p.beds !== 1 && "s"}
            </span>
          )}
          {p.bathrooms !== null && (
            <span className="flex items-center gap-1">
              <Bath className="h-4 w-4 text-teal" /> {p.bathrooms} Bathroom{p.bathrooms !== 1 && "s"}
            </span>
          )}
          {p.sqft !== null && (
            <span className="flex items-center gap-1">
              <Ruler className="h-4 w-4 text-teal" /> {p.sqft.toLocaleString()}sqft
            </span>
          )}
          {p.balconies !== null && (
            <span className="flex items-center gap-1">
              <LayoutGrid className="h-4 w-4 text-teal" /> {p.balconies} Balcon
              {p.balconies !== 1 ? "ies" : "y"}
            </span>
          )}
          {p.doors !== null && (
            <span className="flex items-center gap-1">
              <DoorOpen className="h-4 w-4 text-teal" /> {p.doors} Door{p.doors !== 1 && "s"}
            </span>
          )}
          {p.parkings !== null && (
            <span className="flex items-center gap-1">
              <Car className="h-4 w-4 text-teal" /> {p.parkings} Parking{p.parkings !== 1 && "s"}
            </span>
          )}
        </div>

        <div className="mt-auto border-t border-dashed border-border pt-4 flex items-center justify-between">
          <span className="text-teal font-extrabold text-xl">
            {p.price === null ? "Price on request" : `${p.currency} ${p.price.toLocaleString()}`}
          </span>
          <Link
            href={`/listings/${p.slug}`}
            className="inline-flex min-h-11 items-center gap-1 text-teal font-semibold hover:gap-2 transition-all"
          >
            Explore Now <ArrowUpRight className="h-4 w-4" />
          </Link>
        </div>
      </div>
    </div>
  );
}
