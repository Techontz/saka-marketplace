import Link from "next/link";
import { ArrowUpRight } from "lucide-react";
import type { ReactNode } from "react";

/**
 * The homepage's horizontal discovery section.
 *
 * One component for every rail — listings, businesses, specialists, places —
 * so the heading, the "see all" affordance and the scroll behaviour are
 * defined once. The rails differ in what they contain, not in how they frame
 * it; nine bespoke section headers is how a page stops looking designed.
 *
 * Renders NOTHING when it has no children. Every caller passes real API data,
 * and a heading above an empty strip tells a visitor the site is broken.
 *
 * Scroll rather than grid, at every width. A grid reflows to one column on a
 * phone and turns eight listings into eight screens of scrolling; a rail keeps
 * the section's shape and lets the next card peek in, which is the whole
 * signal that there is more.
 */
export function DiscoveryRail({
  title,
  subtitle,
  href,
  seeAllLabel = "See all",
  children,
  tone = "page",
}: {
  title: string;
  subtitle?: string;
  href?: string;
  seeAllLabel?: string;
  children: ReactNode;
  /** Alternating backgrounds give the page rhythm without extra chrome. */
  tone?: "page" | "white";
}) {
  const items = Array.isArray(children) ? children.filter(Boolean) : children;
  if (Array.isArray(items) && items.length === 0) return null;

  return (
    <section className={tone === "white" ? "bg-white py-10 sm:py-14" : "bg-page py-10 sm:py-14"}>
      <div className="mx-auto max-w-7xl">
        <div className="flex items-end justify-between gap-4 px-4">
          <div className="min-w-0">
            <h2 className="text-xl font-extrabold tracking-tight text-navy sm:text-2xl">
              {title}
            </h2>
            {subtitle && (
              <p className="mt-1 text-sm text-navy/55">{subtitle}</p>
            )}
          </div>
          {href && (
            <Link
              href={href}
              // min-h-11 so the target clears 44px on a phone, where this sits
              // a thumb's width from the first card.
              className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm font-bold text-teal transition-colors hover:text-navy"
            >
              {seeAllLabel}
              <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
            </Link>
          )}
        </div>

        {/*
          The padding lives on the scroller, not the section, so the first and
          last cards clear the screen edge while the strip itself still runs
          edge to edge. Without it the last card is flush against the bezel and
          reads as clipped.

          `snap-x` with `snap-start` on each child: a rail that stops
          mid-card looks like a rendering fault rather than a scroll position.
        */}
        <div className="mt-5 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          {children}
        </div>
      </div>
    </section>
  );
}

/**
 * A fixed-width slot inside a rail.
 *
 * The cards were built for a responsive grid and stretch to their container,
 * so inside a flex scroller they would each take their content's width and the
 * rail would be ragged. This pins them to one width per breakpoint.
 */
export function RailItem({ children, width = "listing" }: { children: ReactNode; width?: "listing" | "wide" | "narrow" }) {
  /*
   * These widths are chosen against ListingCard's CONTAINER QUERIES, not by eye.
   *
   * The card reveals its "Explore Now" link at `@[240px]`. A 240px slot is
   * therefore the one width that is guaranteed to look broken: the link
   * switches on at exactly the size where there is no room beside the price,
   * and the two run together as "TZS 180,000Explore Now". Every listing width
   * here sits clear of that boundary — comfortably above it, near the ~290px
   * the desktop grid gives the same card.
   *
   * `narrow` stays deliberately BELOW 240px. It carries PlaceCard, which has no
   * price row and no CTA to collide.
   */
  const w =
    width === "wide"
      ? "w-[300px] sm:w-[340px]"
      : width === "narrow"
        ? "w-[190px] sm:w-[210px]"
        : "w-[268px] sm:w-[296px]";

  return <div className={`${w} shrink-0 snap-start`}>{children}</div>;
}
