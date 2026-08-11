import Link from "next/link";
import { Search } from "lucide-react";

import type { ApiCategory } from "@/lib/types";

/**
 * The homepage's search entry point.
 *
 * There was none. `/listings` has had a full search and filter panel all along
 * and the API answers `?q=`, but nothing on the homepage led to it — a visitor
 * landing on saka.africa had to find their way into the listings page before
 * they could type anything. On a marketplace that is the primary action, so it
 * now sits above the fold at every width.
 *
 * A plain GET form, deliberately:
 *
 *  - it works with JavaScript still loading, which on a 3G connection in Dar is
 *    a real window rather than a hypothetical one;
 *  - it produces a normal, shareable, crawlable `/listings?q=…` URL rather than
 *    pushing state client-side;
 *  - `/listings` already reads `q` from searchParams and renders server-side,
 *    so there is nothing new to build behind it.
 */
export function HomeSearch({ categories }: { categories: ApiCategory[] }) {
  // The verticals worth a one-tap shortcut: those that actually have listings,
  // biggest first. Derived from the live taxonomy, never hardcoded — a vertical
  // added tomorrow appears here without a deploy.
  const shortcuts = [...categories]
    .filter((category) => (category.listing_count ?? 0) > 0)
    .sort((a, b) => (b.listing_count ?? 0) - (a.listing_count ?? 0))
    .slice(0, 6);

  return (
    <section className="border-b border-black/5 bg-navy">
      <div className="mx-auto max-w-7xl px-4 py-10 sm:py-14">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-teal">
          Find. Connect. Own.
        </p>
        <h2 className="mt-2 max-w-2xl text-3xl font-extrabold leading-tight text-white sm:text-4xl">
          Everything for sale and rent across Tanzania
        </h2>

        <form
          action="/listings"
          method="GET"
          role="search"
          className="mt-6 flex flex-col gap-3 sm:flex-row"
        >
          <div className="relative flex-1">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-navy/40"
            />
            <input
              type="search"
              name="q"
              // `q`, matching what /listings already reads and what the API
              // already accepts. No new contract.
              placeholder="Search property, vehicles, electronics…"
              aria-label="Search listings"
              autoComplete="off"
              className="h-14 w-full rounded-2xl border border-white/10 bg-white pl-12 pr-4 text-base text-navy placeholder:text-navy/40 focus:outline-none focus:ring-2 focus:ring-teal"
            />
          </div>
          <button
            type="submit"
            className="inline-flex h-14 min-w-[7rem] items-center justify-center gap-2 rounded-2xl bg-teal px-6 text-base font-bold text-white transition-colors hover:bg-teal/90 focus:outline-none focus:ring-2 focus:ring-white/60"
          >
            Search
          </button>
        </form>

        {shortcuts.length > 0 && (
          <div className="mt-5 flex flex-wrap items-center gap-2">
            <span className="text-sm text-white/50">Popular:</span>
            {shortcuts.map((category) => (
              <Link
                key={category.slug}
                href={`/listings?category=${category.slug}`}
                // 44px minimum target: these sit close together on a 360px
                // screen and are the most likely thing to be mis-tapped.
                className="inline-flex min-h-11 items-center rounded-full border border-white/15 px-4 text-sm font-medium text-white/85 transition-colors hover:border-teal hover:text-white"
              >
                {category.name}
              </Link>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}
