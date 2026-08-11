"use client";

import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import { LazyMapView } from "@/components/map/LazyMapView";
import type { MapPin } from "@/components/map/MapView";
import type { ListingView } from "@/lib/view-models";

/**
 * "Explore around you" — the map, on the homepage.
 *
 * The map already existed as a tab inside the listings browser, which means a
 * visitor only found it after deciding to search. On a marketplace where half
 * the inventory is property and land, where something IS the question, so it
 * gets a section of its own.
 *
 * A preview, not the full browser: no area-search, no drawing, no filters.
 * Pins are the listings the page already fetched — this section costs no extra
 * request — and the real tool is one tap away at /listings?view=map.
 */
export function MapDiscovery({ listings }: { listings: ListingView[] }) {
  /*
   * Only listings the API gave real coordinates for.
   *
   * Never a fallback centre, never a jittered pin. A marker in the wrong place
   * on a property marketplace sends someone to the wrong side of Dar, and a
   * plausible-looking wrong answer is worse than an absent one.
   */
  const pins: MapPin[] = listings
    .filter((listing) => listing.latitude != null && listing.longitude != null)
    .slice(0, 40)
    .map((listing) => ({
      id: listing.slug,
      lat: listing.latitude as number,
      lng: listing.longitude as number,
      label: listing.title,
      sublabel:
        listing.price === null
          ? undefined
          : `${listing.currency} ${Intl.NumberFormat("en", { notation: "compact" }).format(listing.price)}`,
      meta: listing.location,
      image: listing.image,
      href: `/listings/${listing.slug}`,
      tone: "listing",
    }));

  // No coordinates, no section. An empty map is a grey rectangle that says
  // nothing and costs a tile fetch.
  if (pins.length === 0) return null;

  return (
    <section className="bg-page py-10 sm:py-14">
      <div className="mx-auto max-w-7xl px-4">
        <div className="flex items-end justify-between gap-4">
          <div className="min-w-0">
            <h2 className="text-xl font-extrabold tracking-tight text-navy sm:text-2xl">
              Explore around you
            </h2>
            <p className="mt-1 text-sm text-navy/55">
              {pins.length} listings on the map
            </p>
          </div>
          <Link
            href="/listings?view=map"
            className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm font-bold text-teal transition-colors hover:text-navy"
          >
            View full map
            <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        </div>

        <div className="mt-5 overflow-hidden rounded-2xl border border-navy/10">
          <LazyMapView
            pins={pins}
            // Framed to the pins rather than to a hardcoded centre on Dar:
            // the same section then works for a Mwanza or Arusha catalogue
            // without anyone remembering to change a constant.
            fitToPins
            height={380}
            // A preview. Panning must not fire searches the visitor did not ask
            // for, and there is no result list here for them to land in.
            autoSearch={false}
          />
        </div>
      </div>
    </section>
  );
}
