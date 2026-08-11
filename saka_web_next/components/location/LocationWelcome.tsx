"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { useRouter } from "next/navigation";
import { Loader2, MapPin, Navigation, Search } from "lucide-react";

import { LocationAutocomplete, type LocationSuggestion } from "@/components/search/LocationAutocomplete";
import { Logo } from "@/components/ui/Logo";
import { useBrowsingLocation } from "@/providers/LocationProvider";

/**
 * "Where are you looking?" — asked once, properly.
 *
 * ── WHY NOT JUST CALL navigator.geolocation ───────────────────────────────
 * Because the browser's own prompt arrives with no context. A permission
 * dialog that appears the instant a page loads, before the visitor knows what
 * the site is, gets denied — and a denial is REMEMBERED by the browser, so
 * "near me" is then broken for that person permanently. Asking in our own UI
 * first means the browser prompt only ever appears after someone has pressed a
 * button that says they want it.
 *
 * ── WHY THERE IS A SECOND OPTION ──────────────────────────────────────────
 * A large share of visitors will not share a location, on a phone, over mobile
 * data, to a site they have just met. "Choose an area" has to be a first-class
 * path rather than a consolation prize, so it is offered at the same weight
 * and it uses the same place search as the filters.
 *
 * ── ASKED ONCE ────────────────────────────────────────────────────────────
 * The decision is stored, not the answer — see LocationProvider. Someone who
 * dismissed this does not see it again.
 */
export function LocationWelcome() {
  const { shouldPrompt, requestDeviceLocation, setManual, decline, requesting, error } = useBrowsingLocation();
  const router = useRouter();

  /*
   * Mounted-gate.
   *
   * The provider reads localStorage in its initialiser, which is SSR-safe only
   * because it returns empty on the server — so on the very first client render
   * `shouldPrompt` is true even for someone who answered months ago. Without a
   * gate this dialog flashes on their screen every single page load.
   *
   * `useSyncExternalStore` with a no-op subscription is the idiom for "am I on
   * the client yet": the server snapshot is false, the client snapshot is true,
   * and React handles the transition without a setState-in-effect cascade.
   */
  const hydrated = useSyncExternalStore(
    () => () => undefined,
    () => true,
    () => false,
  );

  const [choosing, setChoosing] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);

  const open = hydrated && shouldPrompt;

  useEffect(() => {
    if (!open) return;

    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    panelRef.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      // Escape is a dismissal, and a dismissal is a decision — otherwise this
      // reappears on the next page load and becomes the thing people hate.
      if (event.key === "Escape") decline();
    };

    document.addEventListener("keydown", onKey);

    return () => {
      document.body.style.overflow = previous;
      document.removeEventListener("keydown", onKey);
    };
  }, [open, decline]);

  if (!open) return null;

  const applyPlace = (suggestion: LocationSuggestion) => {
    if (suggestion.latitude === null || suggestion.longitude === null) {
      // An administrative area with no centroid can still filter, so send them
      // to the results rather than refusing the choice.
      decline();
      router.push(
        `/listings?${suggestion.filter ? `${suggestion.filter.param}=${suggestion.filter.value}` : `place=${encodeURIComponent(suggestion.label)}`}&loc=${encodeURIComponent(suggestion.label)}`,
      );
      return;
    }

    setManual({
      label: suggestion.label,
      lat: suggestion.latitude,
      lng: suggestion.longitude,
      radius: suggestion.radius_km,
      source: "manual",
    });
  };

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end justify-center bg-navy/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
      role="dialog"
      aria-modal="true"
      aria-labelledby="location-welcome-title"
    >
      <div
        ref={panelRef}
        tabIndex={-1}
        className="w-full max-w-lg overflow-hidden rounded-t-3xl bg-white shadow-2xl outline-none animate-slide-up sm:rounded-3xl sm:animate-scale-in-soft"
      >
        {/* A photographic header, matching the hero treatment used across the
            rest of the site rather than inventing a fifth visual language. */}
        <div className="relative isolate overflow-hidden bg-navy px-6 pb-6 pt-7 text-center sm:px-8">
          <div
            aria-hidden="true"
            className="absolute inset-0 -z-10 bg-gradient-to-br from-teal/30 via-navy to-navy"
          />

          <Logo size="lg" variant="light" className="mb-4" />

          <h2 id="location-welcome-title" className="text-2xl font-extrabold text-white">
            Find listings near you
          </h2>
          <p className="mx-auto mt-2 max-w-sm text-sm text-white/75">
            Share your location and we&apos;ll put the closest properties, vehicles and businesses
            first — or pick an area yourself.
          </p>
        </div>

        <div className="px-6 py-6 sm:px-8">
          {choosing ? (
            <div>
              <p className="mb-2 text-sm font-medium text-navy">Where are you looking?</p>

              <LocationAutocomplete
                value=""
                onSelect={applyPlace}
                onClear={() => undefined}
                placeholder="Region, area or landmark"
                autoFocus
              />

              <button
                type="button"
                onClick={() => setChoosing(false)}
                className="mt-4 text-sm font-semibold text-muted-foreground transition hover:text-navy"
              >
                ← Back
              </button>
            </div>
          ) : (
            <div className="space-y-3">
              <button
                type="button"
                onClick={() => void requestDeviceLocation()}
                disabled={requesting}
                className="flex w-full items-center justify-center gap-2 rounded-full bg-teal px-6 py-3.5 text-[15px] font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
              >
                {requesting ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Navigation className="h-4 w-4" />
                )}
                Use my location
              </button>

              <button
                type="button"
                onClick={() => setChoosing(true)}
                className="flex w-full items-center justify-center gap-2 rounded-full border-2 border-border px-6 py-3.5 text-[15px] font-semibold text-navy transition hover:border-teal hover:text-teal"
              >
                <Search className="h-4 w-4" />
                Choose an area
              </button>

              {error && (
                <p className="rounded-lg bg-orange/10 px-3 py-2 text-sm text-orange" role="alert">
                  {error}
                </p>
              )}

              <button
                type="button"
                onClick={decline}
                className="w-full py-2 text-sm font-medium text-muted-foreground transition hover:text-navy"
              >
                Browse everything instead
              </button>
            </div>
          )}

          <p className="mt-4 flex items-start gap-1.5 border-t border-border pt-4 text-xs text-muted-foreground">
            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal" />
            Your location stays on this device. We only use it to sort what you see, and you can
            change it any time from the header.
          </p>
        </div>
      </div>
    </div>
  );
}
