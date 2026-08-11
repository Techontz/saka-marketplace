"use client";

import { useSyncExternalStore } from "react";
import { ExternalLink, Navigation } from "lucide-react";

import { appleMapsUrl, googleDirectionsUrl, googleMapsUrl, isAppleDevice } from "@/lib/config";

/**
 * "Get directions", and the two map apps worth offering.
 *
 * Apple Maps is rendered only on Apple hardware — `maps.apple.com` on Android
 * or Windows opens a page telling the visitor to buy an Apple device, which is
 * a dead link with extra steps. The check runs after mount because the server
 * has no user agent to branch on, and branching during render would produce a
 * hydration mismatch.
 */
export function DirectionsLinks({
  lat,
  lng,
  label,
  className = "",
}: {
  lat: number;
  lng: number;
  label?: string;
  className?: string;
}) {
  /*
   * Read AFTER hydration, never during SSR.
   *
   * useSyncExternalStore returns the server snapshot (false) on the server and
   * during the first client render, then the real value — which is exactly the
   * behaviour needed to branch on the user agent without a hydration mismatch.
   */
  const apple = useSyncExternalStore(
    () => () => {},
    () => isAppleDevice(),
    () => false,
  );

  return (
    <div className={`flex flex-wrap gap-2 ${className}`}>
      <a
        href={googleDirectionsUrl(lat, lng)}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center gap-2 rounded-full bg-teal px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
      >
        <Navigation className="h-4 w-4" />
        Directions
      </a>

      <a
        href={googleMapsUrl(lat, lng, label)}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
      >
        <ExternalLink className="h-4 w-4" />
        Google Maps
      </a>

      {apple && (
        <a
          href={appleMapsUrl(lat, lng, label)}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
        >
          <ExternalLink className="h-4 w-4" />
          Apple Maps
        </a>
      )}
    </div>
  );
}
