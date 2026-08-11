import Link from "next/link";
import { MapPin } from "lucide-react";

import type { ApiPlace } from "@/lib/types";

/**
 * A public place.
 *
 * Deliberately not a listing card: a place has no price, no seller and nothing
 * to buy, and dressing one in listing chrome invites a tap that leads nowhere
 * a buyer expected. So it leads with the photograph and the area, and carries
 * its category as a quiet label rather than a purpose badge.
 *
 * Taller crop than a listing card too — places are landmarks and beaches, and
 * a 4:3 product crop wastes what makes them worth showing.
 */
export function PlaceCard({ place }: { place: ApiPlace }) {
  const area = [place.location?.district, place.location?.region]
    .filter(Boolean)
    .join(", ");

  return (
    <Link
      href={`/public-places/${place.slug}`}
      className="group block overflow-hidden rounded-2xl border border-navy/10 bg-white transition-shadow hover:shadow-lg"
    >
      <div className="relative aspect-[3/4] overflow-hidden bg-page">
        {place.image_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- the API returns
          // absolute URLs on hosts that are allow-listed in the CSP, not in the
          // Next image loader's remotePatterns.
          <img
            src={place.image_url}
            alt={place.name}
            loading="lazy"
            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-navy/20">
            <MapPin className="h-8 w-8" aria-hidden="true" />
          </div>
        )}

        {/* Bottom scrim: the caption sits on an unknown photograph. */}
        <div
          aria-hidden="true"
          className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-navy/90 to-transparent"
        />

        <div className="absolute inset-x-0 bottom-0 p-3">
          <h3 className="line-clamp-2 text-sm font-bold leading-snug text-white">
            {place.name}
          </h3>
          {area && (
            <p className="mt-0.5 flex items-center gap-1 text-xs text-white/75">
              <MapPin className="h-3 w-3 shrink-0" aria-hidden="true" />
              <span className="truncate">{area}</span>
            </p>
          )}
        </div>
      </div>

      {place.category?.name && (
        <p className="truncate px-3 py-2 text-xs font-semibold uppercase tracking-wide text-teal">
          {place.category.name}
        </p>
      )}
    </Link>
  );
}
