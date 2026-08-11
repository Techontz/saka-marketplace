"use client";

import Link from "next/link";
import { MapPin } from "lucide-react";

import { useBrowsingLocation } from "@/providers/LocationProvider";

/**
 * The chosen browsing location, in the header.
 *
 * Two jobs, and the second is the important one:
 *
 *   1. It shows what "near me" currently means, so a customer is never
 *      surprised by results sorted around somewhere they did not pick.
 *   2. It is the way BACK to the choice. The welcome dialog is deliberately
 *      shown once and never again — which is only defensible if there is a
 *      permanent, obvious control to change the answer. Without this, someone
 *      who dismissed the dialog has no route to "near me" at all.
 *
 * Renders nothing until a location exists, so the header is unchanged for
 * anyone who declined.
 */
export function LocationChip({ className }: { className?: string }) {
  const { location, clear } = useBrowsingLocation();

  if (!location) return null;

  return (
    <span className={`inline-flex items-center gap-1 ${className ?? ""}`}>
      <Link
        href={`/listings?lat=${location.lat.toFixed(5)}&lng=${location.lng.toFixed(5)}&radius=${location.radius}&loc=${encodeURIComponent(location.label)}&sort=distance`}
        className="inline-flex max-w-[9rem] items-center gap-1.5 rounded-full border border-border bg-white/70 px-3 py-1.5 text-[13px] font-semibold text-navy transition hover:border-teal hover:text-teal"
        title={`Browsing near ${location.label}`}
      >
        <MapPin className="h-3.5 w-3.5 shrink-0 text-teal" />
        <span className="truncate">{location.label}</span>
      </Link>

      {/*
        Clearing resets the decision, which brings the welcome dialog back on
        the next load. That is the intended way to change it — a second copy of
        the picker in the header would be a lot of chrome for a rare action.
      */}
      <button
        type="button"
        onClick={clear}
        aria-label="Change browsing location"
        title="Change location"
        className="text-[12px] font-semibold text-muted-foreground underline underline-offset-2 transition hover:text-teal"
      >
        Change
      </button>
    </span>
  );
}
