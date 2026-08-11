"use client";

import { useQuery } from "@tanstack/react-query";
import { useRef } from "react";

import { ListingCard } from "@/components/listings/ListingCard";
import { apiGet } from "@/lib/api/browser";
import type { ApiListing } from "@/lib/types";
import { toListingView } from "@/lib/view-models";
import { useAuth } from "@/providers/AuthProvider";

/**
 * "Pick up where you left off".
 *
 * Client-side and signed-in only: it is per-customer, so rendering it on the
 * server would either leak one visitor's history into another's cached page or
 * force the whole homepage to be dynamic. Renders nothing at all when there is
 * no history — an empty rail with a heading is worse than no rail.
 */
export function RecentlyViewed({ title = "Recently viewed" }: { title?: string }) {
  const { isAuthenticated } = useAuth();
  const scrollRef = useRef<HTMLDivElement>(null);

  const { data } = useQuery({
    queryKey: ["recently-viewed"],
    queryFn: () => apiGet<{ data: ApiListing[] }>("/account/recently-viewed", { limit: 8 }),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const listings = (data?.data ?? []).map(toListingView);

  if (!isAuthenticated || listings.length === 0) return null;

  return (
    <section className="bg-white pb-8">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mb-6 flex items-end justify-between">
          <div>
            <h2 className="text-2xl md:text-3xl font-extrabold text-navy">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">Listings you opened recently</p>
          </div>
        </div>

        <div
          ref={scrollRef}
          className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4"
        >
          {listings.slice(0, 4).map((listing) => (
            <ListingCard key={listing.slug} p={listing} />
          ))}
        </div>
      </div>
    </section>
  );
}
