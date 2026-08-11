"use client";

import { useQuery } from "@tanstack/react-query";

import { CategoryFilters } from "@/components/listings/CategoryFilters";
import { PriceRangeChips } from "@/components/listings/PriceRangeChips";
import { LocationPicker } from "@/components/search/LocationPicker";
import { apiGet } from "@/lib/api/browser";
import type { ApiCategory } from "@/lib/types";

/**
 * Every filter, in one place, rendered identically on desktop and mobile.
 *
 * Extracted from ListingsBrowser so the sidebar and the mobile sheet cannot
 * drift apart. They did the moment there were two of them: a filter added to
 * one is invisible on the other, and the person who notices is a customer on a
 * phone who cannot find the control their laptop had.
 *
 * DRAFT vs APPLIED
 * ----------------
 * Price and location are held as a DRAFT and committed by "Apply", because
 * every keystroke in a price box would otherwise be a request. Purpose,
 * attributes, amenities and facilities apply immediately — they are single
 * decisive taps, and making someone press Apply after ticking one box is the
 * kind of friction that makes a filter panel feel slow.
 */

export type FilterDraft = {
  min_price: string;
  max_price: string;
};

const PURPOSES = [
  { value: "rent", label: "For rent" },
  { value: "sale", label: "For sale" },
  { value: "lease", label: "To lease" },
  // Jobs and Services. Added to the API enum once those verticals were
  // populated; without it here a third of the catalogue is unreachable.
  { value: "hire", label: "For hire" },
];

const SORTS = [
  { value: "", label: "Most relevant" },
  { value: "newest", label: "Newest first" },
  { value: "price_asc", label: "Price: low to high" },
  { value: "price_desc", label: "Price: high to low" },
  { value: "popularity", label: "Most viewed" },
];

type Amenity = { slug: string; name: string; icon: string | null };

export function ListingFilters({
  filters,
  setFilters,
  draft,
  setDraft,
  attributeParams,
  clearAttributes,
  categories,
  onApply,
}: {
  filters: Record<string, string>;
  setFilters: (patch: Record<string, string | null>) => void;
  draft: FilterDraft;
  setDraft: (draft: FilterDraft) => void;
  attributeParams: Record<string, string>;
  clearAttributes: () => Record<string, null>;
  categories: ApiCategory[];
  /** Commits the draft. Also used by the sheet's footer button. */
  onApply: () => void;
}) {
  const selectedCategory = categories.find((category) => category.slug === filters.category);

  /*
   * Amenities and facilities are taxonomy: seeded, slow-changing, and read on
   * every browse. Cached for an hour so opening the filter panel does not
   * refetch a list that has not changed since the app booted.
   */
  const amenities = useQuery({
    queryKey: ["amenities"],
    queryFn: () => apiGet<{ data: Amenity[] }>("/amenities"),
    staleTime: 60 * 60 * 1000,
  });

  const facilities = useQuery({
    queryKey: ["facilities"],
    queryFn: () => apiGet<{ data: Amenity[] }>("/facilities"),
    staleTime: 60 * 60 * 1000,
  });

  const selectedAmenities = splitList(filters.amenities);
  const selectedFacilities = splitList(filters.facilities);

  const toggleIn = (key: "amenities" | "facilities", slug: string) => {
    const current = splitList(filters[key]);
    const next = current.includes(slug)
      ? current.filter((item) => item !== slug)
      : [...current, slug];

    setFilters({ [key]: next.length > 0 ? next.join(",") : null });
  };

  return (
    <div className="space-y-6">
      <FilterBox title="Location">
        <LocationPicker
          value={{
            region: filters.region,
            district: filters.district,
            ward: filters.ward,
            landmark: filters.landmark,
            label: filters.loc,
            lat: filters.lat,
            lng: filters.lng,
            radius: filters.radius,
          }}
          onChange={(patch) => setFilters(patch)}
        />
      </FilterBox>

      <FilterBox title="Category">
        <label className="mb-2 block text-sm text-muted-foreground" htmlFor="filter-category">
          Category
        </label>
        <select
          id="filter-category"
          value={filters.category}
          onChange={(event) =>
            /*
              Attribute filters are scoped to the category that defined them.
              Carrying `attributes[beds]` into Vehicles filters out every car
              and reads as "no results".
            */
            setFilters({
              ...clearAttributes(),
              category: event.target.value || null,
              subcategory: null,
            })
          }
          className="mb-4 w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
        >
          <option value="">All categories</option>
          {categories.map((category) => (
            <option key={category.slug} value={category.slug}>
              {category.name}
              {category.listing_count > 0 ? ` (${category.listing_count})` : ""}
            </option>
          ))}
        </select>

        <label className="mb-2 block text-sm text-muted-foreground" htmlFor="filter-subcategory">
          Subcategory
        </label>
        <select
          id="filter-subcategory"
          value={filters.subcategory}
          onChange={(event) =>
            setFilters({ ...clearAttributes(), subcategory: event.target.value || null })
          }
          disabled={!selectedCategory}
          className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal disabled:bg-page disabled:text-muted-foreground"
        >
          <option value="">{selectedCategory ? "All subcategories" : "Pick a category first"}</option>
          {(selectedCategory?.children ?? []).map((child) => (
            <option key={child.slug} value={child.slug}>
              {child.name}
              {child.listing_count > 0 ? ` (${child.listing_count})` : ""}
            </option>
          ))}
        </select>
      </FilterBox>

      <FilterBox title="Price">
        {/*
          The chips only FILL the inputs below; they are a shortcut, not a
          replacement. Which ladder appears depends on the vertical AND the
          purpose — a house to rent must not be offered the 1-billion sale
          brackets.
        */}
        <PriceRangeChips
          vertical={filters.category || undefined}
          purpose={filters.purpose || undefined}
          min={draft.min_price}
          max={draft.max_price}
          onSelect={(min, max) => {
            setDraft({ min_price: min, max_price: max });
            // Applied immediately: a chip is a decisive tap, and making
            // someone press Apply afterwards makes the filter feel broken.
            setFilters({ min_price: min || null, max_price: max || null });
          }}
        />

        <div className="my-4 h-px bg-border" />

        <label className="mb-2 block text-sm text-muted-foreground" htmlFor="filter-min-price">
          Minimum price
        </label>
        <input
          id="filter-min-price"
          type="number"
          inputMode="numeric"
          min="0"
          value={draft.min_price}
          onChange={(event) => setDraft({ ...draft, min_price: event.target.value })}
          placeholder="No minimum"
          className="mb-4 w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
        />

        <label className="mb-2 block text-sm text-muted-foreground" htmlFor="filter-max-price">
          Maximum price
        </label>
        <input
          id="filter-max-price"
          type="number"
          inputMode="numeric"
          min="0"
          value={draft.max_price}
          onChange={(event) => setDraft({ ...draft, max_price: event.target.value })}
          placeholder="No maximum"
          className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
        />

        <button
          type="button"
          onClick={onApply}
          className="mt-4 w-full rounded-[5px] bg-teal px-4 py-2.5 text-sm font-semibold text-white tap-scale"
        >
          Apply price
        </button>
      </FilterBox>

      <FilterBox title="Purpose">
        <div className="space-y-3">
          {PURPOSES.map((purpose) => (
            <label
              key={purpose.value}
              className="flex cursor-pointer items-center gap-3 text-sm text-navy"
            >
              <input
                type="radio"
                name="purpose"
                checked={filters.purpose === purpose.value}
                onChange={() =>
                  setFilters({ purpose: filters.purpose === purpose.value ? null : purpose.value })
                }
                className="h-4 w-4 accent-teal"
              />
              {purpose.label}
            </label>
          ))}
        </div>
      </FilterBox>

      <FilterBox title="Sort by">
        <select
          value={filters.sort}
          onChange={(event) => setFilters({ sort: event.target.value || null })}
          aria-label="Sort results"
          className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
        >
          {SORTS.map((sort) => (
            <option key={sort.value} value={sort.value}>
              {sort.label}
            </option>
          ))}
          {/* Only offered once there is a point to sort from. */}
          {filters.lat && <option value="distance">Nearest first</option>}
        </select>
      </FilterBox>

      {/*
        Category-defined attributes: bedrooms and area for property, mileage
        for vehicles, storage and RAM for phones. Nothing here is hardcoded, so
        an attribute added in the admin portal becomes a filter with no deploy.
      */}
      <CategoryFilters
        categorySlug={filters.subcategory || filters.category || undefined}
        values={attributeParams}
        onChange={(patch) => setFilters(patch)}
        FilterBox={FilterBox}
      />

      {(amenities.data?.data.length ?? 0) > 0 && (
        <FilterBox title="Amenities">
          <ChipList
            options={amenities.data?.data ?? []}
            selected={selectedAmenities}
            onToggle={(slug) => toggleIn("amenities", slug)}
          />
        </FilterBox>
      )}

      {(facilities.data?.data.length ?? 0) > 0 && (
        <FilterBox title="Nearby facilities">
          <ChipList
            options={facilities.data?.data ?? []}
            selected={selectedFacilities}
            onToggle={(slug) => toggleIn("facilities", slug)}
          />
        </FilterBox>
      )}
    </div>
  );
}

function ChipList({
  options,
  selected,
  onToggle,
}: {
  options: Amenity[];
  selected: string[];
  onToggle: (slug: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2">
      {options.map((option) => {
        const active = selected.includes(option.slug);

        return (
          <button
            key={option.slug}
            type="button"
            onClick={() => onToggle(option.slug)}
            aria-pressed={active}
            className={`rounded-full border px-3 py-1.5 text-sm transition ${
              active
                ? "border-teal bg-teal text-white"
                : "border-border bg-white text-navy hover:border-teal hover:text-teal"
            }`}
          >
            {option.icon} {option.name}
          </button>
        );
      })}
    </div>
  );
}

export function FilterBox({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-[5px] border border-border bg-white p-5">
      <h3 className="mb-4 text-base font-bold text-navy">{title}</h3>
      {children}
    </div>
  );
}

/** The API takes repeated params; the URL keeps them as one comma list. */
export function splitList(value: string | undefined): string[] {
  return (value ?? "").split(",").filter(Boolean);
}

/**
 * How many filters are actually narrowing the results.
 *
 * Drives the badge on the mobile Filters button. Without a number there, the
 * only way to tell whether a filter is applied on a phone is to open the sheet
 * and read it — which is the problem the sheet was meant to solve.
 */
export function countActiveFilters(
  filters: Record<string, string>,
  attributeParams: Record<string, string>,
): number {
  let count = 0;

  // A location is one decision however many params it wrote.
  if (filters.loc || filters.region || filters.district || filters.ward || filters.place) count++;
  if (filters.category) count++;
  if (filters.subcategory) count++;
  if (filters.min_price) count++;
  if (filters.max_price) count++;
  if (filters.purpose) count++;
  if (filters.sort) count++;

  count += splitList(filters.amenities).length;
  count += splitList(filters.facilities).length;
  count += Object.keys(attributeParams).length;

  return count;
}
