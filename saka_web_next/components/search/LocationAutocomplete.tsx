"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect, useId, useRef, useState } from "react";
import { Building2, Landmark, Loader2, MapPin, Search, X } from "lucide-react";

import { apiGet } from "@/lib/api/browser";
import { useDebounced } from "@/hooks/useDebounced";

/**
 * "Where?" — a real place picker, not a text box.
 *
 * The location filter used to be free text posted straight to the API as a
 * `place` LIKE query. Two things were wrong with that: a customer had to know
 * whether "Masaki" is a ward or a district before their filter would work, and
 * a matched string carries no coordinate, so choosing somewhere could never
 * move the map.
 *
 * Each suggestion arrives from the API with the exact filter parameter to
 * apply AND a coordinate and radius. This component does not know that Masaki
 * is a ward and Mlimani City is a landmark — the API says so, and that keeps
 * the five-types-to-four-filters mapping in one place instead of three clients.
 *
 * ACCESSIBILITY
 * -------------
 * A real combobox: `role="combobox"` with `aria-expanded`, `aria-controls` and
 * `aria-activedescendant`, arrow keys, Enter, Escape. A filter that only works
 * with a mouse is a filter a third of people cannot use, and on a phone the
 * listbox is the primary interaction rather than a nicety.
 */

export type LocationSuggestion = {
  id: string;
  type: "region" | "district" | "ward" | "place" | "business";
  label: string;
  context: string;
  slug: string;
  icon: string | null;
  latitude: number | null;
  longitude: number | null;
  radius_km: number;
  listing_count: number | null;
  filter: { param: "region" | "district" | "ward"; value: string } | null;
};

const TYPE_LABEL: Record<LocationSuggestion["type"], string> = {
  region: "Region",
  district: "District",
  ward: "Area",
  place: "Landmark",
  business: "Business",
};

export function LocationAutocomplete({
  value,
  onSelect,
  onClear,
  placeholder = "Region, area or landmark",
  types,
  className,
  inputClassName,
  autoFocus = false,
}: {
  /** The label of the currently applied location, or an empty string. */
  value: string;
  onSelect: (suggestion: LocationSuggestion) => void;
  onClear: () => void;
  placeholder?: string;
  /** e.g. "ward,district,region" to exclude landmarks and businesses. */
  types?: string;
  className?: string;
  inputClassName?: string;
  autoFocus?: boolean;
}) {
  const [term, setTerm] = useState(value);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);

  const rootRef = useRef<HTMLDivElement>(null);
  const listboxId = useId();

  // Follow the applied value when it changes from outside — clearing the
  // filter elsewhere, or arriving with one already in the URL.
  const [syncedValue, setSyncedValue] = useState(value);

  if (value !== syncedValue) {
    setSyncedValue(value);
    setTerm(value);
  }

  /*
   * 250 ms. Short enough that the list feels attached to the keyboard, long
   * enough that a normal typist sends one request per word rather than one per
   * letter — the endpoint is throttled per IP and an undebounced input burns
   * that budget in a sentence.
   */
  const debounced = useDebounced(term, 250);
  const query = debounced.trim();

  const suggestions = useQuery({
    queryKey: ["location-search", query, types],
    queryFn: () =>
      apiGet<{ data: LocationSuggestion[] }>("/locations/search", {
        q: query,
        limit: 8,
        types,
      }),
    enabled: open && query.length >= 2,
    // The same prefixes recur constantly; the API caches too, but this saves
    // the round trip when someone backspaces.
    staleTime: 5 * 60 * 1000,
  });

  const rows = suggestions.data?.data ?? [];

  // Clicking outside closes the list and restores the applied label, so a
  // half-typed term never lingers as if it were filtering something.
  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: PointerEvent) => {
      if (rootRef.current?.contains(event.target as Node)) return;

      setOpen(false);
      setActive(-1);
      setTerm(value);
    };

    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open, value]);

  const choose = (suggestion: LocationSuggestion) => {
    setTerm(suggestion.label);
    setSyncedValue(suggestion.label);
    setOpen(false);
    setActive(-1);
    onSelect(suggestion);
  };

  const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();

      if (!open) {
        setOpen(true);
        return;
      }

      if (rows.length === 0) return;

      // Wraps, so holding Down does not dead-end at the last row.
      setActive((current) => {
        const next = event.key === "ArrowDown" ? current + 1 : current - 1;
        return (next + rows.length) % rows.length;
      });

      return;
    }

    if (event.key === "Enter") {
      if (open && active >= 0 && rows[active]) {
        event.preventDefault();
        choose(rows[active]);
      }

      return;
    }

    if (event.key === "Escape") {
      if (open) {
        // Escape closes the list first; a second press is left to the browser
        // so it can still clear the field or close a parent drawer.
        event.preventDefault();
        setOpen(false);
        setActive(-1);
        setTerm(value);
      }

      return;
    }

    if (event.key === "Tab") {
      setOpen(false);
      setActive(-1);
    }
  };

  const clear = () => {
    setTerm("");
    setSyncedValue("");
    setActive(-1);
    setOpen(false);
    onClear();
  };

  const showList = open && query.length >= 2;

  return (
    <div ref={rootRef} className={`relative ${className ?? ""}`}>
      <div className="relative">
        <Search
          aria-hidden="true"
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        />

        <input
          type="text"
          role="combobox"
          aria-expanded={showList}
          aria-controls={listboxId}
          aria-autocomplete="list"
          aria-activedescendant={active >= 0 && rows[active] ? `${listboxId}-${active}` : undefined}
          autoComplete="off"
          // A phone keyboard that opens on "search" and does not autocorrect
          // "Masaki" to "Masks".
          enterKeyHint="search"
          autoCorrect="off"
          autoCapitalize="words"
          spellCheck={false}
          autoFocus={autoFocus}
          value={term}
          placeholder={placeholder}
          onChange={(event) => {
            setTerm(event.target.value);
            setOpen(true);
            setActive(-1);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          className={
            inputClassName ??
            "w-full rounded-[5px] border border-border py-2 pl-9 pr-9 text-sm outline-none focus:border-teal"
          }
        />

        {(term || value) && (
          <button
            type="button"
            onClick={clear}
            aria-label="Clear location"
            className="absolute right-2 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition hover:bg-page hover:text-navy"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>

      {showList && (
        <ul
          id={listboxId}
          role="listbox"
          aria-label="Location suggestions"
          className="absolute left-0 right-0 top-full z-40 mt-1 max-h-72 overflow-y-auto overscroll-contain rounded-xl border border-border bg-white py-1 shadow-xl"
        >
          {suggestions.isPending && (
            <li className="flex items-center gap-2 px-4 py-3 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin text-teal" />
              Searching…
            </li>
          )}

          {!suggestions.isPending && rows.length === 0 && (
            <li className="px-4 py-3 text-sm text-muted-foreground">
              Nothing called &ldquo;{query}&rdquo;. Try a district, an area or a landmark.
            </li>
          )}

          {rows.map((row, index) => (
            <li key={row.id} id={`${listboxId}-${index}`} role="option" aria-selected={index === active}>
              <button
                type="button"
                // pointerdown, not click: the outside-click handler runs on
                // pointerdown and would close the list before a click landed.
                onPointerDown={(event) => {
                  event.preventDefault();
                  choose(row);
                }}
                onMouseEnter={() => setActive(index)}
                className={`flex w-full items-center gap-3 px-4 py-2.5 text-left transition ${
                  index === active ? "bg-teal/10" : "hover:bg-page"
                }`}
              >
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-page text-teal">
                  <TypeIcon type={row.type} icon={row.icon} />
                </span>

                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold text-navy">{row.label}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {TYPE_LABEL[row.type]}
                    {row.context ? ` · ${row.context}` : ""}
                  </span>
                </span>

                {/* Only where the number means something. A ward carries no
                    count, and "0" beside one would read as "nothing here". */}
                {row.listing_count !== null && row.listing_count > 0 && (
                  <span className="shrink-0 text-xs font-semibold text-muted-foreground">
                    {row.listing_count.toLocaleString()}
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function TypeIcon({ type, icon }: { type: LocationSuggestion["type"]; icon: string | null }) {
  // A public place carries its category's emoji; it is more recognisable than
  // any generic pin we could substitute.
  if (type === "place" && icon) return <span className="text-base leading-none">{icon}</span>;
  if (type === "place") return <Landmark className="h-4 w-4" />;
  if (type === "business") return <Building2 className="h-4 w-4" />;

  return <MapPin className="h-4 w-4" />;
}
