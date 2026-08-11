"use client";

import { useCallback, useSyncExternalStore } from "react";

/**
 * One column or two, on a phone. Remembered between visits.
 *
 * Two is the default because a marketplace grid is for comparing, but a single
 * column gives each card a photo twice the size, which is what people switch to
 * when they are reading rather than scanning.
 *
 * Persisted in localStorage rather than the URL: it is a preference about how
 * someone likes to browse, not part of what they searched for, and it should
 * not travel in a shared link.
 *
 * WHY useSyncExternalStore
 * ------------------------
 * localStorage is external state that the server cannot see. Reading it during
 * render makes the server and the first client render disagree and React
 * replaces the tree with a hydration error; reading it in an effect and calling
 * setState causes a second render pass on every mount. This hook is exactly
 * what the API is for — `getServerSnapshot` returns the default, the client
 * snapshot reads storage, and a `storage` event keeps two open tabs in step.
 */

const STORAGE_KEY = "saka:listing-columns";

export type GridColumns = 1 | 2;

/** Subscribers, so a change in one component updates every other on the page. */
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);

  // Another tab changing the preference should not leave this one stale.
  const onStorage = (event: StorageEvent) => {
    if (event.key !== STORAGE_KEY) return;

    // Invalidate the cached value so read() picks up the other tab's choice.
    current = event.newValue === "1" ? 1 : 2;
    listener();
  };

  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(listener);
    window.removeEventListener("storage", onStorage);
  };
}

/**
 * The in-memory answer, which is also the fallback when storage is unavailable.
 *
 * Private mode and several in-app browsers throw on localStorage access. The
 * preference is a nicety and losing it across visits is acceptable; the toggle
 * silently doing nothing for the rest of the session is not, so the value lives
 * here and storage is only where it is persisted.
 */
let current: GridColumns | null = null;

function read(): GridColumns {
  if (current !== null) return current;

  try {
    current = window.localStorage.getItem(STORAGE_KEY) === "1" ? 1 : 2;
  } catch {
    current = 2;
  }

  return current;
}

export function useGridColumns(fallback: GridColumns = 2) {
  const columns = useSyncExternalStore(subscribe, read, () => fallback);

  const setColumns = useCallback((next: GridColumns) => {
    current = next;

    try {
      window.localStorage.setItem(STORAGE_KEY, String(next));
    } catch {
      // Persistence failed; the session still honours the choice.
    }

    for (const listener of listeners) listener();
  }, []);

  return { columns, setColumns };
}
