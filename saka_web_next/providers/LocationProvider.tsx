"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from "react";

/**
 * Where the customer is browsing from.
 *
 * Held here rather than in a URL param because it outlives a single search: it
 * is the answer to "near me" on every page, and asking again on each one would
 * be the browser permission prompt over and over.
 *
 * ── THE DECISION IS STORED, NOT JUST THE ANSWER ───────────────────────────
 * `status` records that a choice was MADE, separately from what it was. That
 * distinction is what stops the welcome dialog reappearing: someone who
 * declined has decided, and re-asking them on the next visit is the behaviour
 * that trains people to block a site's location permission permanently.
 *
 * Only re-prompted if they clear their storage or explicitly change it from
 * the header.
 */

export type BrowsingLocation = {
  label: string;
  lat: number;
  lng: number;
  /** Radius to search around it, km. */
  radius: number;
  /** How it was obtained — shown back to the customer so it is not a mystery. */
  source: "device" | "manual";
};

type LocationState = {
  location: BrowsingLocation | null;
  /**
   * `unset` — never asked, so the welcome dialog should appear.
   * `granted` / `chosen` — we have a location.
   * `declined` — asked and refused. Do not ask again.
   */
  status: "unset" | "granted" | "chosen" | "declined";
};

const STORAGE_KEY = "saka:browsing-location";

const EMPTY: LocationState = { location: null, status: "unset" };

function read(): LocationState {
  if (typeof window === "undefined") return EMPTY;

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return EMPTY;

    const parsed = JSON.parse(raw) as LocationState;

    // Guard against a partially-written or hand-edited value: a malformed
    // location would otherwise put NaN into every radius query.
    if (parsed.status === "granted" || parsed.status === "chosen") {
      const l = parsed.location;

      if (!l || !Number.isFinite(l.lat) || !Number.isFinite(l.lng)) return EMPTY;
    }

    return parsed;
  } catch {
    // Private mode, a quota error, or corrupt JSON. Behaving as "never asked"
    // is the safe fallback — the worst case is one extra dialog.
    return EMPTY;
  }
}

function write(state: LocationState) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    // Storage unavailable. The choice still applies for this session, which is
    // better than blocking the customer over a preference.
  }
}

/*
 * ── THE STORE ─────────────────────────────────────────────────────────────
 *
 * localStorage is state React does not own and the server cannot see, so it is
 * exposed through `useSyncExternalStore` rather than seeded into `useState`.
 *
 * That distinction caused a real bug. A `useState` initialiser runs on the
 * server AND on the first client render, and `read()` branches on
 * `typeof window` — so the server produced EMPTY while the client immediately
 * produced the stored location. <LocationChip> renders null for the former and
 * a <span> for the latter, so the header gained a child during hydration and
 * React threw:
 *
 *     Hydration failed because the server rendered HTML didn't match the client
 *
 * It reported it as "server <a>, client <span>", because the comparison is
 * POSITIONAL: the inserted <span> displaced the Saved-listings <a> at index 0.
 *
 * `getServerSnapshot` fixes it at the source. React uses it for the server
 * render and for the hydrating client render, then swaps to `getSnapshot`
 * afterwards — so both sides agree on EMPTY, and the location appears on the
 * next commit. Every consumer becomes hydration-safe, not just the one that
 * happened to break.
 */

/** Cached so `getSnapshot` returns a STABLE reference; a fresh object each call
 *  makes useSyncExternalStore re-render forever. */
let snapshot: LocationState | null = null;

const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  // Another tab choosing a location should not leave this one stale.
  const onStorage = (event: StorageEvent) => {
    if (event.key !== STORAGE_KEY) return;
    snapshot = null;
    listener();
  };

  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

function getSnapshot(): LocationState {
  snapshot ??= read();
  return snapshot;
}

/** Server and hydrating client both see "never asked". */
function getServerSnapshot(): LocationState {
  return EMPTY;
}

function emit(next: LocationState) {
  snapshot = next;
  write(next);

  for (const listener of listeners) listener();
}

type LocationContext = LocationState & {
  /** Ask the browser. Resolves false if refused or unavailable. */
  requestDeviceLocation: () => Promise<boolean>;
  setManual: (location: BrowsingLocation) => void;
  decline: () => void;
  clear: () => void;
  /** True when the welcome dialog should be shown. */
  shouldPrompt: boolean;
  requesting: boolean;
  error: string | null;
};

const Context = createContext<LocationContext | null>(null);

export function LocationProvider({ children }: { children: ReactNode }) {
  // See the store above for why this is not `useState(() => read())`.
  const state = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  // Ephemeral UI state, not persisted, and identical on both sides of a
  // hydration — so ordinary state is correct here.
  const [requesting, setRequesting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const persist = useCallback((next: LocationState) => emit(next), []);

  const requestDeviceLocation = useCallback((): Promise<boolean> => {
    if (typeof navigator === "undefined" || !navigator.geolocation) {
      setError("This browser cannot share a location. Choose an area instead.");
      return Promise.resolve(false);
    }

    setRequesting(true);
    setError(null);

    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          persist({
            status: "granted",
            location: {
              label: "Near me",
              lat: position.coords.latitude,
              lng: position.coords.longitude,
              // 10 km covers a city's worth of listings without pulling in the
              // next region; the customer can widen it from the map.
              radius: 10,
              source: "device",
            },
          });

          setRequesting(false);
          resolve(true);
        },
        (cause) => {
          setError(
            cause.code === cause.PERMISSION_DENIED
              ? "Location permission was declined. Pick an area instead."
              : "We couldn't work out where you are. Pick an area instead.",
          );
          setRequesting(false);
          // NOT recorded as `declined`: the dialog stays open so they can pick
          // manually. Declining is a deliberate act, not a failed lookup.
          resolve(false);
        },
        { enableHighAccuracy: false, timeout: 10_000, maximumAge: 5 * 60_000 },
      );
    });
  }, [persist]);

  const setManual = useCallback(
    (location: BrowsingLocation) => persist({ status: "chosen", location }),
    [persist],
  );

  const decline = useCallback(() => persist({ status: "declined", location: null }), [persist]);

  const clear = useCallback(() => persist(EMPTY), [persist]);

  const value = useMemo<LocationContext>(
    () => ({
      ...state,
      requestDeviceLocation,
      setManual,
      decline,
      clear,
      shouldPrompt: state.status === "unset",
      requesting,
      error,
    }),
    [state, requestDeviceLocation, setManual, decline, clear, requesting, error],
  );

  return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useBrowsingLocation(): LocationContext {
  const context = useContext(Context);

  if (context === null) {
    throw new Error("useBrowsingLocation must be used inside <LocationProvider>.");
  }

  return context;
}
