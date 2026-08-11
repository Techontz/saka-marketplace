"use client";

import { useEffect, useRef, useState } from "react";

import { ADSENSE_CLIENT } from "@/lib/config";

/**
 * One Google AdSense unit.
 *
 * Only ever reached when SAKA has no advertisement of its own for the slot —
 * see AdSlot. Our own inventory is worth more and looks like the product, so
 * AdSense is the backfill, never the first choice.
 *
 * THE LAYOUT-SHIFT PROBLEM, and why this component looks the way it does.
 *
 * An AdSense unit is an iframe of unknown height that arrives whenever Google
 * feels like it. Dropped into a page unreserved, it pushes everything below it
 * down mid-scroll — the single worst thing an advert can do to a marketplace,
 * and a large CLS penalty on the pages that matter most for search.
 *
 * So the box is reserved with `aspect-ratio` from the placement's own
 * declaration BEFORE anything loads, and the unit fills it. The reserved box
 * is kept even when the slot goes unfilled: collapsing it on no-fill would
 * reintroduce exactly the shift the reservation exists to prevent, just later
 * and less predictably. An empty reserved strip is a small amount of quiet
 * whitespace between sections; a collapsing one is content jumping under a
 * thumb.
 */

declare global {
  interface Window {
    adsbygoogle?: unknown[];
  }
}

export function AdSenseUnit({
  slotId,
  aspectRatio,
  className = "",
}: {
  slotId: string;
  /** Desktop and mobile ratios from the placement descriptor. */
  aspectRatio: { desktop: number; mobile: number };
  className?: string;
}) {
  const ref = useRef<HTMLModElement | null>(null);
  const [narrow, setNarrow] = useState(true);

  /*
   * Which ratio to reserve.
   *
   * Read in an effect, not during render: `matchMedia` does not exist on the
   * server, and branching on it while rendering would produce markup the client
   * disagrees with. Starting narrow is the safe default — a phone is the
   * majority of this traffic, and being wrong there costs a shift on desktop
   * only, for one frame.
   */
  useEffect(() => {
    const query = window.matchMedia("(min-width: 640px)");
    const sync = () => setNarrow(!query.matches);

    sync();
    query.addEventListener("change", sync);

    return () => query.removeEventListener("change", sync);
  }, []);

  /*
   * Hand the unit to AdSense exactly once.
   *
   * `adsbygoogle.push({})` is how the script is told a new slot exists. Pushing
   * twice for one <ins> makes AdSense throw "All ins elements in the DOM with
   * class=adsbygoogle already have ads in them" — which in React's development
   * double-invoke is the DEFAULT behaviour, not an edge case. The ref guard is
   * what stops that.
   */
  const pushed = useRef(false);

  useEffect(() => {
    if (pushed.current || !ref.current) return;
    if (!ADSENSE_CLIENT) return;

    pushed.current = true;

    try {
      window.adsbygoogle = window.adsbygoogle ?? [];
      window.adsbygoogle.push({});
    } catch {
      // A blocked script, an offline device, a policy-disabled account. The
      // reserved box simply stays empty; nothing here is worth a console error
      // on a visitor's screen.
    }
  }, []);

  if (!ADSENSE_CLIENT) return null;

  return (
    <div
      className={`w-full overflow-hidden ${className}`}
      style={{ aspectRatio: narrow ? aspectRatio.mobile : aspectRatio.desktop }}
    >
      <ins
        ref={ref}
        className="adsbygoogle"
        style={{ display: "block", width: "100%", height: "100%" }}
        data-ad-client={ADSENSE_CLIENT}
        data-ad-slot={slotId}
        data-ad-format="auto"
        data-full-width-responsive="true"
      />
    </div>
  );
}
