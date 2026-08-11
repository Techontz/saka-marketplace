import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

/**
 * The seller invitation, near the bottom of the homepage.
 *
 * Placed after the discovery rails on purpose: someone who has just scrolled
 * past six sections of other people's listings is the person most likely to
 * think "I have something like that". Putting it above the fold would ask a
 * first-time visitor to sell before they have seen what SAKA is.
 *
 * It links to the vendor portal that already exists at /vendor — no new
 * onboarding, no invented seller features.
 */
export function SellOnSaka() {
  return (
    <section className="bg-navy">
      <div className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-14 sm:py-16 md:flex-row md:items-center md:justify-between">
        <div className="max-w-xl">
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-teal">
            Sell on SAKA
          </p>
          <h2 className="mt-2 text-2xl font-extrabold leading-tight text-white sm:text-3xl">
            Have something to sell?
          </h2>
          <p className="mt-3 text-base leading-relaxed text-white/70">
            List property, vehicles, electronics or your services, and reach
            buyers searching across Tanzania.
          </p>
        </div>

        <Link
          href="/vendor"
          className="inline-flex min-h-12 shrink-0 items-center gap-2 self-start rounded-full bg-teal pl-6 pr-2 py-2 text-base font-bold text-white transition-shadow hover:shadow-xl md:self-auto"
        >
          Start selling
          <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
            <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
          </span>
        </Link>
      </div>
    </section>
  );
}
