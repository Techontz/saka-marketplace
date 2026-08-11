"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Bell, Heart, Inbox, Star } from "lucide-react";

import { apiGet } from "@/lib/api/browser";
import type { ApiInquiry, ApiListing, Paginated } from "@/lib/types";
import { useFavorites } from "@/providers/FavoritesProvider";
import { SafeImage } from "@/components/ui/SafeImage";

/** The account overview: what is waiting, and shortcuts to everything else. */
export default function AccountOverviewPage() {
  const favorites = useFavorites();

  const notifications = useQuery({
    queryKey: ["notifications", "unread-count"],
    queryFn: () => apiGet<{ data: { unread_count: number } }>("/account/notifications/unread-count"),
  });

  /*
   * Shares the key the site header's messages bell and <AccountHeader> use, so
   * this page adds no second request for the same list. The three shown below
   * are sliced from it rather than fetched separately.
   */
  const inquiries = useQuery({
    queryKey: ["account-inquiries", "unread"],
    queryFn: () => apiGet<Paginated<ApiInquiry>>("/account/inquiries", { per_page: 100 }),
  });

  const recentlyViewed = useQuery({
    queryKey: ["recently-viewed", "account"],
    queryFn: () => apiGet<{ data: ApiListing[] }>("/account/recently-viewed", { limit: 4 }),
  });

  const cards = [
    {
      href: "/account/favorites",
      icon: Heart,
      label: "Saved",
      value: favorites.listingSlugs.size + favorites.businessSlugs.size,
    },
    {
      href: "/account/inquiries",
      icon: Inbox,
      label: "Inquiries sent",
      value: inquiries.data?.meta.total ?? 0,
    },
    {
      href: "/account/notifications",
      icon: Bell,
      label: "Unread",
      value: notifications.data?.data.unread_count ?? 0,
    },
    { href: "/account/reviews", icon: Star, label: "My reviews", value: null },
  ];

  return (
    <>
      {/* The greeting and the counts are in <AccountHeader>; this page
          starts with what is actually waiting. */}
      <p className="text-muted-foreground">Everything you have saved, sent and been told about.</p>

      <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {cards.map((card) => (
          <Link
            key={card.href}
            href={card.href}
            className="rounded-xl border border-border bg-white p-5 transition hover:border-teal"
          >
            <card.icon className="h-5 w-5 text-teal" />
            <p className="mt-3 text-2xl font-extrabold text-navy">
              {card.value === null ? "—" : card.value}
            </p>
            <p className="text-sm text-muted-foreground">{card.label}</p>
          </Link>
        ))}
      </div>

      <section className="mt-8">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-xl font-bold text-navy">Recent inquiries</h2>
          <Link href="/account/inquiries" className="text-sm font-semibold text-teal">
            See all
          </Link>
        </div>

        {(inquiries.data?.data.length ?? 0) === 0 ? (
          <p className="rounded-xl border border-dashed border-border bg-white p-8 text-center text-sm text-muted-foreground">
            You haven&apos;t messaged anyone yet.
          </p>
        ) : (
          <ul className="space-y-3">
            {/* The shared query fetches the full list for the header badge;
                this panel is a preview, so it shows the three most recent. */}
            {(inquiries.data?.data ?? []).slice(0, 3).map((inquiry) => (
              <li key={inquiry.uuid}>
                <Link
                  href={`/account/inquiries/${inquiry.uuid}`}
                  className="block rounded-xl border border-border bg-white p-4 transition hover:border-teal"
                >
                  <p className="truncate font-semibold text-navy">
                    {inquiry.listing?.title ?? "General enquiry"}
                  </p>
                  <p className="mt-1 truncate text-sm text-muted-foreground">{inquiry.message}</p>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>

      {(recentlyViewed.data?.data.length ?? 0) > 0 && (
        <section className="mt-8">
          <h2 className="mb-4 text-xl font-bold text-navy">Recently viewed</h2>
          <ul className="space-y-3">
            {(recentlyViewed.data?.data ?? []).map((listing) => (
              <li key={listing.slug}>
                <Link
                  href={`/listings/${listing.slug}`}
                  className="flex items-center gap-3 rounded-xl border border-border bg-white p-3 transition hover:border-teal"
                >
                  <SafeImage
                    src={listing.primary_image?.url}
                    alt=""
                    className="h-14 w-20 shrink-0 rounded object-cover"
                    fallbackClassName="h-14 w-20 shrink-0 rounded"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-semibold text-navy">{listing.title}</span>
                    <span className="block text-sm text-muted-foreground">
                      {listing.price ? `${listing.price.currency} ${listing.price.amount.toLocaleString()}` : "Price on request"}
                    </span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
