import Link from "next/link";
import { ArrowUpRight, Building2, MapPin, ShieldCheck, Star } from "lucide-react";

import { FavoriteButton } from "@/components/listings/FavoriteButton";
import type { ApiBusiness } from "@/lib/types";
import { formatDistance } from "@/lib/view-models";
import { SafeImage } from "@/components/ui/SafeImage";

/** A business tile, in the same visual language as the listing card. */
export function BusinessCard({ business }: { business: ApiBusiness }) {
  const location = [business.location.district, business.location.region]
    .filter(Boolean)
    .join(", ");
  const distance = formatDistance(business.distance_km ?? null);

  return (
    <div className="group relative flex h-full flex-col rounded-[8px] border border-[#DCE6EF] bg-white p-5 transition-all duration-300 hover:-translate-y-1 hover:border-[#0B8E95]/40 hover:shadow-[0_18px_40px_-18px_rgba(6,28,63,0.28)] focus-within:ring-2 focus-within:ring-[#0B8E95]/30">
      <FavoriteButton
        kind="business"
        slug={business.slug}
        label={business.display_name}
        className="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white text-[#F07F7F] shadow transition-all duration-300 hover:bg-[#0B8E95] hover:text-white"
      />

      <Link href={`/businesses/${business.slug}`} className="flex items-center gap-3 pr-10">
        <SafeImage
          src={business.logo_url}
          alt={`${business.display_name} logo`}
          className="h-14 w-14 shrink-0 rounded-full border border-border object-cover"
          fallbackClassName="h-14 w-14 shrink-0 rounded-full bg-teal/10 text-teal"
          fallback={<Building2 className="h-6 w-6" />}
        />

        <span className="min-w-0">
          <span className="flex items-center gap-1.5 text-[17px] font-bold text-[#17233C] transition-colors group-hover:text-[#0B8E95]">
            <span className="truncate">{business.display_name}</span>
            {business.is_verified && <ShieldCheck className="h-4 w-4 shrink-0 text-teal" />}
          </span>
          {business.business_type_label && (
            <span className="block text-[13px] text-muted-foreground">
              {business.business_type_label}
            </span>
          )}
        </span>
      </Link>

      {location && (
        <p className="mt-4 flex items-center gap-1 truncate text-[14px] text-[#6B7280]">
          <MapPin className="h-4 w-4 shrink-0 text-[#0B8E95]" />
          <span className="truncate">{distance ? `${location} · ${distance}` : location}</span>
        </p>
      )}

      <div className="mt-3 flex items-center gap-4 text-[13px] text-[#6B7280]">
        {business.rating.count > 0 ? (
          <span className="flex items-center gap-1">
            <Star className="h-4 w-4 fill-orange text-orange" />
            {business.rating.average?.toFixed(1)} ({business.rating.count})
          </span>
        ) : (
          <span className="text-muted-foreground">No reviews yet</span>
        )}
        <span>
          {business.listing_count} listing{business.listing_count !== 1 && "s"}
        </span>
      </div>

      <div className="mt-auto flex items-center justify-end border-t border-dashed border-[#D8E2EB] pt-3">
        <Link
          href={`/businesses/${business.slug}`}
          className="group/explore inline-flex min-h-11 items-center gap-1 text-[15px] font-semibold text-[#17233C] transition-colors hover:text-[#0B8E95]"
        >
          View business
          <ArrowUpRight className="h-4 w-4 transition-all duration-300 group-hover/explore:translate-x-1 group-hover/explore:-translate-y-1" />
        </Link>
      </div>
    </div>
  );
}
