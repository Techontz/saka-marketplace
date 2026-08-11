"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Bell, Heart, Inbox, Star, User } from "lucide-react";

import { DEFAULT_HERO_IMAGE } from "@/components/search/SearchHero";
import { SafeImage } from "@/components/ui/SafeImage";
import { apiGet } from "@/lib/api/browser";
import type { ApiInquiry, Paginated } from "@/lib/types";
import { useAuth } from "@/providers/AuthProvider";
import { useFavorites } from "@/providers/FavoritesProvider";

/**
 * The account area's header.
 *
 * Listings, Businesses and Public Places all open with the same photographic
 * hero: a title, a breadcrumb, white type over a dark gradient. The account
 * area opened with a bare `<h1>` on a grey background, which made a signed-in
 * customer feel like they had left the site.
 *
 * This is the same hero with the things only a signed-in page can show — the
 * avatar, and the four numbers that are the entire reason to visit the account
 * area at all. Those numbers are also the navigation, so "3 unread" is a
 * button rather than a fact you then have to go and find.
 *
 * The counts come from the queries the pages themselves already run, under the
 * same query keys, so React Query serves them from cache rather than doubling
 * the requests.
 */

const TABS = [
  { href: "/account", label: "Overview", exact: true },
  { href: "/account/favorites", label: "Saved" },
  { href: "/account/inquiries", label: "Inquiries" },
  { href: "/account/bookings", label: "Bookings" },
  { href: "/account/reviews", label: "Reviews" },
  { href: "/account/notifications", label: "Notifications" },
  { href: "/account/settings", label: "Settings" },
];

export function AccountHeader() {
  const { user } = useAuth();
  const favorites = useFavorites();
  const pathname = usePathname();

  const notifications = useQuery({
    queryKey: ["notifications", "unread-count"],
    queryFn: () => apiGet<{ data: { unread_count: number } }>("/account/notifications/unread-count"),
    staleTime: 60 * 1000,
  });

  /*
   * The SAME key and parameters the header's messages bell already uses.
   *
   * A different key here — `["account-inquiries", "summary"]` with per_page:3 —
   * meant every account page fetched /account/inquiries twice: once for the
   * badge in the site header and once for the count in this one. React Query
   * dedupes by key, so matching the key is all it takes to make that one
   * request; the total comes off the same payload.
   */
  const inquiries = useQuery({
    queryKey: ["account-inquiries", "unread"],
    queryFn: () => apiGet<Paginated<ApiInquiry>>("/account/inquiries", { per_page: 100 }),
    staleTime: 60 * 1000,
  });

  const current = TABS.find((tab) =>
    tab.exact ? pathname === tab.href : pathname === tab.href || pathname.startsWith(`${tab.href}/`),
  );

  const saved = favorites.listingSlugs.size + favorites.businessSlugs.size;
  const unread = notifications.data?.data.unread_count ?? 0;

  const stats = [
    { href: "/account/favorites", icon: Heart, label: "Saved", value: saved },
    {
      href: "/account/inquiries",
      icon: Inbox,
      label: "Inquiries",
      value: inquiries.data?.meta.total ?? 0,
    },
    { href: "/account/notifications", icon: Bell, label: "Unread", value: unread },
    { href: "/account/reviews", icon: Star, label: "Reviews", value: null },
  ];

  return (
    <section className="relative isolate overflow-hidden bg-navy">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={DEFAULT_HERO_IMAGE}
        alt=""
        aria-hidden="true"
        className="absolute inset-0 -z-10 h-full w-full object-cover"
      />
      <div
        aria-hidden="true"
        className="absolute inset-0 -z-10 bg-gradient-to-r from-navy/95 via-navy/85 to-navy/60"
      />

      <div className="relative mx-auto max-w-7xl px-4 pb-0 pt-10 sm:px-6">
        <p className="text-sm text-white/70">
          <Link href="/" className="transition-colors hover:text-teal">
            Home
          </Link>
          <span className="mx-2">›</span>
          <Link href="/account" className="transition-colors hover:text-teal">
            Account
          </Link>
          {current && !current.exact && (
            <>
              <span className="mx-2">›</span>
              {current.label}
            </>
          )}
        </p>

        <div className="mt-4 flex flex-wrap items-center gap-4">
          <span className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-teal ring-4 ring-white/20">
            <SafeImage
              src={user?.avatar_url ?? null}
              alt=""
              className="h-full w-full object-cover"
              fallbackClassName="h-full w-full bg-teal text-white"
              fallback={<User className="h-7 w-7" />}
            />
          </span>

          <div className="min-w-0">
            <h1 className="truncate text-3xl font-extrabold text-white md:text-4xl">
              {current?.exact === true || !current
                ? `Hello, ${user?.first_name ?? "there"}`
                : current.label}
            </h1>
            <p className="truncate text-sm text-white/70">{user?.email}</p>
          </div>
        </div>

        {/* Quick stats. Every one is a link — a count you cannot act on is
            decoration. */}
        <dl className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {stats.map((stat) => (
            <Link
              key={stat.href}
              href={stat.href}
              className="rounded-xl bg-white/10 px-4 py-3 backdrop-blur-sm transition hover:bg-white/20"
            >
              <dt className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-white/70">
                <stat.icon className="h-3.5 w-3.5" />
                {stat.label}
              </dt>
              <dd className="mt-0.5 text-xl font-extrabold text-white">
                {/* A null value means "no number worth showing" rather than
                    zero — the reviews list has no cheap count endpoint, and a
                    hardcoded 0 there would be wrong for anyone who has left
                    one. */}
                {stat.value === null ? "View" : stat.value.toLocaleString()}
              </dd>
            </Link>
          ))}
        </dl>

        {/*
          The section nav sits in the header on every width. It used to be a
          sidebar that, on a phone, pushed the page's actual content below two
          screens of links.
        */}
        <nav aria-label="Account sections" className="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <ul className="flex min-w-max gap-1 border-b border-white/15">
            {TABS.map((tab) => {
              const active = tab.exact
                ? pathname === tab.href
                : pathname === tab.href || pathname.startsWith(`${tab.href}/`);

              return (
                <li key={tab.href}>
                  <Link
                    href={tab.href}
                    aria-current={active ? "page" : undefined}
                    className={`inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition ${
                      active
                        ? "border-teal text-white"
                        : "border-transparent text-white/70 hover:text-white"
                    }`}
                  >
                    {tab.label}
                    {tab.href === "/account/notifications" && unread > 0 && (
                      <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-orange px-1.5 text-[10px] font-bold text-white">
                        {unread > 9 ? "9+" : unread}
                      </span>
                    )}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      </div>
    </section>
  );
}
