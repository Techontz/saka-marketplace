"use client";

import { useEffect, useRef } from "react";
import { ArrowUpRight } from "lucide-react";

import { SafeImage } from "@/components/ui/SafeImage";
import { imageSrc, imageSrcSet } from "@/lib/media";
import type { AdPlacement, ApiAdCreative } from "@/lib/types";

/**
 * One SAKA-sold advertisement, rendered as part of the marketplace.
 *
 * The design brief for this component was "must never make SAKA look cheap",
 * so it borrows the card system wholesale — the same 1px #DCE6EF border, the
 * same 8px radius, the same navy headline and teal accent as a listing card —
 * and adds exactly one thing a listing card does not have: the word Sponsored,
 * first, where it cannot be missed.
 *
 * Three things it deliberately does NOT do:
 *
 *   - render advertiser copy as HTML. `headline` and `body` are text nodes.
 *     An advertiser-supplied string in `dangerouslySetInnerHTML` is stored XSS
 *     with an invoice attached;
 *   - animate, flash, expand, or move anything on the page;
 *   - hide where the click goes. The anchor's href IS the advertiser's URL, so
 *     the destination shows in the status bar before anyone commits. Tracking
 *     is a beacon fired alongside, not a redirect the link passes through.
 */

/** Fires once the unit is genuinely on screen. */
const VIEWABILITY_THRESHOLD = 0.5;

export function SponsoredBanner({
  creative,
  placement,
  variant,
}: {
  creative: ApiAdCreative;
  placement: AdPlacement;
  /** `hero` is the tall homepage unit; `strip` is everything else. */
  variant: "hero" | "strip";
}) {
  const ref = useRef<HTMLDivElement | null>(null);

  /*
   * A VIEWABLE impression, not a served one.
   *
   * The server deliberately does not count on serve — a page renders its slots
   * including the ones below the fold that nobody scrolls to, and billing for
   * those produces a number indistinguishable from a real one. This fires when
   * at least half the unit has actually been on screen.
   *
   * `once` guards the obvious double-count: an observer fires again every time
   * the element re-enters, so scrolling past an ad four times would otherwise
   * be four impressions of one placement on one page view.
   */
  useEffect(() => {
    const element = ref.current;
    if (!element) return;

    // No IntersectionObserver — an old browser, or a test environment. Skip
    // rather than fall back to counting on mount: an uncounted impression is a
    // smaller lie than an unviewed one.
    if (typeof IntersectionObserver === "undefined") return;

    let fired = false;

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting || fired) continue;

          fired = true;
          observer.disconnect();

          void beacon(`/api/saka/ads/${creative.uuid}/impression`, placement);
        }
      },
      { threshold: VIEWABILITY_THRESHOLD },
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, [creative.uuid, placement]);

  const onClick = () => {
    // Fire-and-forget. Nothing awaits it and nothing blocks the navigation —
    // an ad click that felt slower than a normal link would be a worse product
    // than one that occasionally goes uncounted.
    void beacon(`/api/saka/ads/${creative.uuid}/click`, placement);
  };

  const desktop = creative.image ?? null;
  // Falls back to the desktop artwork rather than rendering nothing: an
  // advertiser who supplied one image gets it on both, scaled. Worse, but
  // theirs to choose.
  const mobile = creative.mobile_image ?? desktop;

  if (variant === "hero") {
    return (
      <div ref={ref} className="@container w-full">
        <a
          href={creative.click_url}
          onClick={onClick}
          // `sponsored` is the correct rel for paid placement and `noopener`
          // is what stops the destination reaching back through window.opener.
          rel="sponsored noopener noreferrer"
          target="_blank"
          className="group relative block overflow-hidden rounded-[8px] border border-[#DCE6EF] bg-white transition-all duration-300 hover:border-[#0B8E95]/40 hover:shadow-[0_18px_40px_-18px_rgba(6,28,63,0.28)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0B8E95]/40"
        >
          <div className="relative aspect-[720/480] w-full @[640px]:aspect-[1600/420]">
            <SafeImage
              src={imageSrc(desktop, "full") ?? imageSrc(mobile, "full")}
              srcSet={imageSrcSet(desktop ?? mobile)}
              sizes="100vw"
              alt={creative.alt_text}
              className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-[1.03]"
              fallbackClassName="h-full w-full"
            />

            {/* A gradient, not a flat scrim: the headline sits at the bottom and
                the top of the artwork stays as the advertiser supplied it. */}
            <div
              aria-hidden="true"
              className="absolute inset-0 bg-gradient-to-t from-[#0B1B33]/85 via-[#0B1B33]/25 to-transparent"
            />

            <div className="absolute inset-x-0 bottom-0 flex flex-col gap-2 p-5 @[640px]:p-8">
              <SponsoredLabel advertiser={creative.advertiser?.name} tone="light" />

              <h3 className="max-w-2xl text-[20px] font-bold leading-tight text-white @[640px]:text-[30px]">
                {creative.headline}
              </h3>

              {creative.body && (
                <p className="max-w-xl text-[13px] text-white/85 @[640px]:text-[15px]">
                  {creative.body}
                </p>
              )}

              {creative.cta_label && (
                <span className="mt-1 inline-flex w-fit items-center gap-1.5 rounded-[5px] bg-[#0B8E95] px-4 py-2 text-[13px] font-semibold text-white transition group-hover:bg-[#0a7d83]">
                  {creative.cta_label}
                  <ArrowUpRight className="h-4 w-4 transition-transform duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                </span>
              )}
            </div>
          </div>
        </a>
      </div>
    );
  }

  return (
    <div ref={ref} className="@container w-full">
      <a
        href={creative.click_url}
        onClick={onClick}
        rel="sponsored noopener noreferrer"
        target="_blank"
        className="group flex items-stretch gap-0 overflow-hidden rounded-[8px] border border-[#DCE6EF] bg-white transition-all duration-300 hover:border-[#0B8E95]/40 hover:shadow-[0_10px_28px_-16px_rgba(6,28,63,0.28)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0B8E95]/40"
      >
        {(desktop || mobile) && (
          /*
           * A fixed-width rail, not a percentage.
           *
           * The strip is short and very wide; a percentage image column would
           * be 500px on a desktop and would swallow the headline. Hidden below
           * 420px of container width, where there is only room for the words.
           */
          <div className="hidden w-[120px] shrink-0 overflow-hidden @[420px]:block @[640px]:w-[180px]">
            <SafeImage
              src={imageSrc(desktop ?? mobile, "card")}
              srcSet={imageSrcSet(desktop ?? mobile)}
              sizes="180px"
              alt=""
              className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
              fallbackClassName="h-full w-full"
            />
          </div>
        )}

        <div className="flex min-w-0 flex-1 items-center gap-4 px-4 py-3 @[640px]:px-5 @[640px]:py-4">
          <div className="min-w-0 flex-1">
            <SponsoredLabel advertiser={creative.advertiser?.name} tone="dark" />

            <h3 className="mt-1 truncate text-[14px] font-bold text-[#17233C] transition-colors duration-300 group-hover:text-[#0B8E95] @[640px]:text-[16px]">
              {creative.headline}
            </h3>

            {creative.body && (
              <p className="mt-0.5 truncate text-[12px] text-[#6B7280] @[640px]:text-[13px]">
                {creative.body}
              </p>
            )}
          </div>

          {creative.cta_label && (
            <span className="hidden shrink-0 items-center gap-1.5 rounded-[5px] border border-[#0B8E95] px-3.5 py-1.5 text-[13px] font-semibold text-[#0B8E95] transition group-hover:bg-[#0B8E95] group-hover:text-white @[420px]:inline-flex">
              {creative.cta_label}
              <ArrowUpRight className="h-3.5 w-3.5" />
            </span>
          )}
        </div>
      </a>
    </div>
  );
}

/**
 * The disclosure.
 *
 * Always rendered, always first in the reading order, and never smaller than
 * 10px. The advertiser's name is appended when there is one, because
 * "Sponsored — NMB Bank" is a more honest label than "Sponsored" alone.
 */
function SponsoredLabel({ advertiser, tone }: { advertiser?: string; tone: "light" | "dark" }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider @[640px]:text-[11px] ${
        tone === "light" ? "text-white/80" : "text-[#8B95A7]"
      }`}
    >
      Sponsored
      {advertiser && (
        <>
          <span aria-hidden="true" className="opacity-50">
            ·
          </span>
          <span className="normal-case tracking-normal">{advertiser}</span>
        </>
      )}
    </span>
  );
}

/**
 * Send a tracking beacon.
 *
 * `sendBeacon` where available: it survives the page unload that a click on a
 * target=_blank link can still trigger in some browsers, and it is explicitly
 * not allowed to delay navigation. `fetch(keepalive)` is the fallback, which
 * gives the same guarantee through a different door.
 *
 * Both are wrapped: a blocked beacon — an ad blocker, an offline phone — must
 * never surface as an unhandled rejection in a visitor's console.
 */
async function beacon(url: string, placement: AdPlacement): Promise<void> {
  const payload = JSON.stringify({ placement });

  try {
    if (typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function") {
      const blob = new Blob([payload], { type: "application/json" });

      if (navigator.sendBeacon(url, blob)) return;
    }

    await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: payload,
      keepalive: true,
    });
  } catch {
    // Counting is best-effort by design. See the class note on undercounting.
  }
}
