"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Clock, Loader2, Search, TrendingUp, X } from "lucide-react";

import { apiGet } from "@/lib/api/browser";
import { useAuth } from "@/providers/AuthProvider";

/**
 * The search field, with suggestions, history and popular searches.
 *
 * Three sources, shown in the order they are useful:
 *
 *   - as you type: live suggestions across listings, businesses, categories
 *     and places, because "masaki" is a place and "toyota" is stock;
 *   - on focus with an empty box: this customer's recent searches;
 *   - and, for a visitor with no history, what everyone else is searching for.
 *
 * Debounced at 250ms — the API is rate-limited per IP and a request per
 * keystroke would trip it during normal typing.
 */

type Suggestion = { type: string; label: string; slug: string };
type Suggestions = Record<string, Suggestion[]>;

const GROUP_LABELS: Record<string, string> = {
  listings: "Listings",
  businesses: "Businesses",
  categories: "Categories",
  places: "Places",
};

export function SearchBox({
  defaultValue = "",
  placeholder = "Search listings...",
  className = "",
  onSubmit,
}: {
  defaultValue?: string;
  placeholder?: string;
  className?: string;
  onSubmit?: (query: string) => void;
}) {
  const router = useRouter();
  const { isAuthenticated } = useAuth();

  const [value, setValue] = useState(defaultValue);
  const [debounced, setDebounced] = useState(defaultValue);
  const [open, setOpen] = useState(false);
  const [syncedDefault, setSyncedDefault] = useState(defaultValue);
  const containerRef = useRef<HTMLDivElement>(null);

  // Adjusted during render rather than in an effect: navigating to a new
  // search URL must show the new term immediately, not one frame later.
  if (defaultValue !== syncedDefault) {
    setSyncedDefault(defaultValue);
    setValue(defaultValue);
  }

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value.trim()), 250);
    return () => clearTimeout(timer);
  }, [value]);

  useEffect(() => {
    const handler = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  const suggestions = useQuery({
    queryKey: ["search-suggestions", debounced],
    queryFn: () => apiGet<{ data: Suggestions }>("/search/suggestions", { q: debounced, limit: 4 }),
    enabled: open && debounced.length >= 2,
    staleTime: 5 * 60 * 1000,
  });

  const history = useQuery({
    queryKey: ["search-history"],
    queryFn: () => apiGet<{ data: { query: string }[] }>("/account/search-history", { limit: 6 }),
    enabled: open && isAuthenticated && debounced.length < 2,
    staleTime: 60 * 1000,
  });

  const popular = useQuery({
    queryKey: ["popular-searches"],
    queryFn: () => apiGet<{ data: { query: string }[] }>("/search/popular", { limit: 6 }),
    enabled: open && debounced.length < 2,
    staleTime: 15 * 60 * 1000,
  });

  const go = (query: string) => {
    setOpen(false);
    setValue(query);

    if (onSubmit) {
      onSubmit(query);
      return;
    }

    router.push(`/listings?q=${encodeURIComponent(query)}`);
  };

  const groups = Object.entries(suggestions.data?.data ?? {}).filter(
    ([, items]) => items.length > 0,
  );

  const recent = history.data?.data ?? [];
  const trending = popular.data?.data ?? [];
  const showIdlePanel = debounced.length < 2 && (recent.length > 0 || trending.length > 0);

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (value.trim()) go(value.trim());
        }}
      >
        <div className="relative">
          <input
            value={value}
            onChange={(event) => setValue(event.target.value)}
            onFocus={() => setOpen(true)}
            placeholder={placeholder}
            aria-label="Search listings"
            className="w-full h-[52px] rounded-lg border border-border bg-white pl-4 pr-20 text-[15px] outline-none focus:border-teal"
          />

          {value && (
            <button
              type="button"
              onClick={() => {
                setValue("");
                setDebounced("");
              }}
              aria-label="Clear search"
              /* 44x44 hit area centred on the same point as before, so the
                 icon does not move. */
              className="absolute right-10 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-muted-foreground hover:text-navy"
            >
              <X className="h-4 w-4" />
            </button>
          )}

          <button
            type="submit"
            aria-label="Search"
            className="absolute right-1 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-teal"
          >
            {suggestions.isFetching ? (
              <Loader2 className="h-5 w-5 animate-spin" />
            ) : (
              <Search className="h-5 w-5" />
            )}
          </button>
        </div>
      </form>

      {open && (groups.length > 0 || showIdlePanel) && (
        <div className="absolute left-0 right-0 top-full z-50 mt-2 max-h-[420px] overflow-y-auto rounded-xl border border-border bg-white shadow-2xl animate-slide-down">
          {groups.map(([group, items]) => (
            <div key={group} className="border-b border-border last:border-b-0">
              <p className="px-4 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                {GROUP_LABELS[group] ?? group}
              </p>
              {items.map((item) => (
                <button
                  key={`${group}-${item.slug}`}
                  type="button"
                  onClick={() => {
                    setOpen(false);

                    // A suggestion is a destination, not a search term: going
                    // to the thing beats searching for its name.
                    if (item.type === "listing") router.push(`/listings/${item.slug}`);
                    else if (item.type === "business") router.push(`/businesses/${item.slug}`);
                    else if (item.type === "category") router.push(`/listings?category=${item.slug}`);
                    else router.push(`/listings?region=${item.slug}`);
                  }}
                  className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-navy transition hover:bg-teal/5 hover:text-teal"
                >
                  <Search className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="truncate">{item.label}</span>
                </button>
              ))}
            </div>
          ))}

          {showIdlePanel && groups.length === 0 && (
            <>
              {recent.length > 0 && (
                <div className="border-b border-border">
                  <p className="px-4 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    Your recent searches
                  </p>
                  {recent.map((item) => (
                    <button
                      key={`recent-${item.query}`}
                      type="button"
                      onClick={() => go(item.query)}
                      className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-navy transition hover:bg-teal/5 hover:text-teal"
                    >
                      <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="truncate">{item.query}</span>
                    </button>
                  ))}
                </div>
              )}

              {trending.length > 0 && (
                <div>
                  <p className="px-4 pt-3 pb-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    Popular on SAKA
                  </p>
                  {trending.map((item) => (
                    <button
                      key={`popular-${item.query}`}
                      type="button"
                      onClick={() => go(item.query)}
                      className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-navy transition hover:bg-teal/5 hover:text-teal"
                    >
                      <TrendingUp className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="truncate">{item.query}</span>
                    </button>
                  ))}
                </div>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
