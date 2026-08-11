"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Heart } from "lucide-react";

import { BusinessCard } from "@/components/businesses/BusinessCard";
import { ListingCard } from "@/components/listings/ListingCard";
import { CardSkeleton, EmptyState, ErrorState } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiBusiness, ApiListing, Paginated } from "@/lib/types";
import { toListingView } from "@/lib/view-models";
import { SafeImage } from "@/components/ui/SafeImage";

/**
 * Saved listings, saved businesses, and the history of both.
 *
 * The history tab exists because un-saving stamps a row rather than deleting
 * it — "I saved a flat last month and can't find it again" is a real problem
 * and this is the only screen that can answer it.
 */

type Tab = "listings" | "businesses" | "history";

type HistoryEntry = {
  type: "listing" | "business";
  saved_at: string | null;
  removed_at: string | null;
  still_saved: boolean;
  target: { slug: string; title: string; image_url?: string | null } | null;
};

export default function FavoritesPage() {
  const [tab, setTab] = useState<Tab>("listings");

  const listings = useQuery({
    queryKey: ["favorites", "listings", "page"],
    queryFn: () => apiGet<Paginated<ApiListing>>("/account/favorites/listings", { per_page: 24 }),
    enabled: tab === "listings",
  });

  const businesses = useQuery({
    queryKey: ["favorites", "businesses", "page"],
    queryFn: () => apiGet<Paginated<ApiBusiness>>("/account/favorites/businesses", { per_page: 24 }),
    enabled: tab === "businesses",
  });

  const history = useQuery({
    queryKey: ["favorites", "history"],
    queryFn: () =>
      apiGet<{ data: HistoryEntry[]; meta: { total: number } }>("/account/favorites/history", {
        per_page: 50,
      }),
    enabled: tab === "history",
  });

  return (
    <>
      <h2 className="text-2xl font-extrabold text-navy">Saved</h2>
      <p className="mt-1 text-muted-foreground">Listings and businesses you want to come back to.</p>

      <div className="mt-6 mb-6 flex gap-2 border-b border-border">
        {([
          ["listings", "Listings"],
          ["businesses", "Businesses"],
          ["history", "History"],
        ] as [Tab, string][]).map(([value, label]) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            aria-current={tab === value ? "true" : undefined}
            className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition ${
              tab === value ? "border-teal text-teal" : "border-transparent text-navy hover:text-teal"
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "listings" &&
        (listings.isPending ? (
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
            <CardSkeleton count={3} />
          </div>
        ) : listings.error ? (
          <ErrorState error={listings.error} onRetry={() => void listings.refetch()} />
        ) : (listings.data?.data.length ?? 0) === 0 ? (
          <EmptyState
            title="Nothing saved yet"
            description="Tap the heart on any listing and it will be waiting here."
            icon={<Heart className="h-6 w-6" />}
            action={
              <Link
                href="/listings"
                className="inline-flex items-center rounded-full bg-teal px-5 py-2 font-semibold text-white"
              >
                Browse listings
              </Link>
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
            {(listings.data?.data ?? []).map((listing) => (
              <ListingCard key={listing.slug} p={toListingView(listing)} />
            ))}
          </div>
        ))}

      {tab === "businesses" &&
        (businesses.isPending ? (
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
            <CardSkeleton count={2} height={220} />
          </div>
        ) : businesses.error ? (
          <ErrorState error={businesses.error} onRetry={() => void businesses.refetch()} />
        ) : (businesses.data?.data.length ?? 0) === 0 ? (
          <EmptyState
            title="No saved businesses"
            description="Save a business to keep its contact details and opening hours to hand."
            icon={<Heart className="h-6 w-6" />}
            action={
              <Link
                href="/businesses"
                className="inline-flex items-center rounded-full bg-teal px-5 py-2 font-semibold text-white"
              >
                Browse businesses
              </Link>
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
            {(businesses.data?.data ?? []).map((business) => (
              <BusinessCard key={business.slug} business={business} />
            ))}
          </div>
        ))}

      {tab === "history" &&
        (history.isPending ? (
          <ErrorStateless />
        ) : history.error ? (
          <ErrorState error={history.error} onRetry={() => void history.refetch()} />
        ) : (history.data?.data.length ?? 0) === 0 ? (
          <EmptyState title="Nothing here yet" description="Anything you save or un-save is listed here." />
        ) : (
          <ul className="space-y-3">
            {(history.data?.data ?? []).map((entry, index) => (
              <li
                key={`${entry.target?.slug ?? "gone"}-${index}`}
                className="flex items-center gap-3 rounded-xl border border-border bg-white p-4"
              >
                <SafeImage
                  src={entry.target?.image_url}
                  alt=""
                  className="h-12 w-16 shrink-0 rounded object-cover"
                  fallbackClassName="h-12 w-16 shrink-0 rounded"
                />

                <div className="min-w-0 flex-1">
                  {entry.target ? (
                    <Link
                      href={
                        entry.type === "listing"
                          ? `/listings/${entry.target.slug}`
                          : `/businesses/${entry.target.slug}`
                      }
                      className="block truncate font-semibold text-navy hover:text-teal"
                    >
                      {entry.target.title}
                    </Link>
                  ) : (
                    // The target can vanish under a favourite; the history row
                    // survives, and saying so beats a link to nowhere.
                    <span className="block truncate font-semibold text-muted-foreground">
                      This {entry.type} is no longer available
                    </span>
                  )}

                  <p className="text-xs text-muted-foreground">
                    Saved {entry.saved_at ? new Date(entry.saved_at).toLocaleDateString() : "—"}
                    {entry.removed_at &&
                      ` · removed ${new Date(entry.removed_at).toLocaleDateString()}`}
                  </p>
                </div>

                <span
                  className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold ${
                    entry.still_saved ? "bg-teal/10 text-teal" : "bg-page text-muted-foreground"
                  }`}
                >
                  {entry.still_saved ? "Saved" : "Removed"}
                </span>
              </li>
            ))}
          </ul>
        ))}
    </>
  );
}

function ErrorStateless() {
  return (
    <div className="space-y-3">
      {[1, 2, 3].map((index) => (
        <div key={index} className="h-20 animate-pulse rounded-xl border border-border bg-white" />
      ))}
    </div>
  );
}
