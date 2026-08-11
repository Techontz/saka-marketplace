"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

/**
 * Filter state that lives in the URL.
 *
 * Two things fall out of this that local state cannot give you: a filtered view
 * is a shareable link ("here's the queue I'm looking at"), and the back button
 * works. Both matter a lot in a tool where people hand each other URLs.
 */
export function useUrlFilters<T extends Record<string, string | undefined>>(defaults: T) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const current = { ...defaults } as T;
  for (const key of Object.keys(defaults)) {
    const value = searchParams.get(key);
    if (value !== null) (current as Record<string, string>)[key] = value;
  }

  const setFilters = useCallback(
    (next: Partial<Record<keyof T, string | number | null>>, options?: { resetPage?: boolean }) => {
      const params = new URLSearchParams(searchParams.toString());

      for (const [key, value] of Object.entries(next)) {
        if (value === null || value === "" || value === undefined) params.delete(key);
        else params.set(key, String(value));
      }

      // Changing a filter invalidates the page number — staying on page 7 of a
      // result set that now has two pages shows an empty table.
      if (options?.resetPage !== false) params.delete("page");

      router.replace(`?${params.toString()}`, { scroll: false });
    },
    [router, searchParams],
  );

  return { filters: current, setFilters };
}

/**
 * Delays a fast-changing value.
 *
 * Used for search inputs: without it every keystroke is a request, and the
 * responses race — the answer for "mas" can land after the answer for "masaki"
 * and overwrite it.
 */
export function useDebounced<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
