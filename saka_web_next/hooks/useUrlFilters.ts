"use client";

import { useCallback, useMemo } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * Filters that live in the URL.
 *
 * The URL is the source of truth so a filtered search is shareable, bookmarkable
 * and survives the back button — which is what a marketplace needs and what
 * component state alone cannot give.
 */
export function useUrlFilters<T extends Record<string, string>>(defaults: T) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const filters = useMemo(() => {
    const next = { ...defaults };

    for (const key of Object.keys(defaults) as (keyof T)[]) {
      const value = searchParams.get(String(key));
      if (value !== null) next[key] = value as T[keyof T];
    }

    return next;
  }, [searchParams, defaults]);

  /**
   * Every `attributes[...]` parameter currently in the URL.
   *
   * These cannot live in `defaults`: the set is decided by whichever category
   * is selected, and the frontend does not know the codes ahead of time.
   */
  const attributeParams = useMemo(() => {
    const out: Record<string, string> = {};

    for (const [key, value] of searchParams.entries()) {
      if (key.startsWith("attributes[") && value !== "") out[key] = value;
    }

    return out;
  }, [searchParams]);

  const setFilters = useCallback(
    (
      // Deliberately widened beyond `keyof T`: attribute filter keys are
      // discovered at runtime from the category's own definitions.
      patch: Partial<Record<keyof T, string | null>> & Record<string, string | null>,
      options: { resetPage?: boolean; replace?: boolean } = {},
    ) => {
      const params = new URLSearchParams(searchParams.toString());

      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === "" || value === undefined) {
          params.delete(key);
        } else {
          params.set(key, String(value));
        }
      }

      // Changing a filter while on page 6 of the old result set lands on an
      // empty page; that reads as "no results" rather than "you moved".
      if (options.resetPage !== false && !("page" in patch)) params.delete("page");

      const url = `${pathname}?${params.toString()}`;

      /*
       * `replace` for continuous changes — dragging the map re-searches every
       * time the gesture settles, and pushing each one would bury the page the
       * customer arrived from under a hundred history entries.
       */
      if (options.replace) {
        router.replace(url, { scroll: false });
        return;
      }

      router.push(url, { scroll: false });
    },
    [router, pathname, searchParams],
  );

  /** Clears every attribute filter — used when the category changes. */
  const clearAttributes = useCallback((): Record<string, null> => {
    return Object.fromEntries(Object.keys(attributeParams).map((key) => [key, null]));
  }, [attributeParams]);

  return { filters, setFilters, attributeParams, clearAttributes };
}
