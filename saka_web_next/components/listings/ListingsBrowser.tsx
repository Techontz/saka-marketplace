"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Fragment, useMemo, useState } from "react";
import { LayoutGrid, Map as MapIcon, Rows2, SlidersHorizontal, Square } from "lucide-react";

import { SponsoredBanner } from "@/components/ads/SponsoredBanner";
import { ListingCard } from "@/components/listings/ListingCard";
import { LazyMapView } from "@/components/map/LazyMapView";
import type { MapPin, MapViewport } from "@/components/map/MapView";
import {
  ListingFilters,
  countActiveFilters,
  splitList,
  type FilterDraft,
} from "@/components/listings/ListingFilters";
import { SearchBox } from "@/components/search/SearchBox";
import { BottomSheet } from "@/components/ui/BottomSheet";
import { CardSkeleton, EmptyState, ErrorState } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiAdCreative, ApiCategory, ApiListing, Paginated } from "@/lib/types";
import { toListingView } from "@/lib/view-models";
import { useGridColumns } from "@/hooks/useGridColumns";
import { useUrlFilters } from "@/hooks/useUrlFilters";

/**
 * Browse, search and filter.
 *
 * The layout is the original: hero banner, search card with its two tabs, a
 * sidebar of filter boxes, a grid and numbered pagination. Filtering happens in
 * the API and lives in the URL, so a search is shareable and survives the back
 * button.
 *
 * WHAT CHANGED FOR RELEASE
 * ------------------------
 *   - The filter controls moved into `<ListingFilters>` so the desktop sidebar
 *     and the mobile sheet render the SAME panel. Two copies would drift, and
 *     the person who finds out is a customer on a phone missing a control.
 *   - On mobile the sidebar is a bottom sheet behind a Filters button with a
 *     live count, instead of a column of boxes stacked above the results.
 *   - The map is loaded on demand. Most sessions never open it, and it was in
 *     the bundle of every page that could show one.
 *
 * The "AI Search" tab is preserved as the design had it, labelled unavailable
 * rather than silently behaving like a normal search — there is no AI search
 * endpoint, and pretending otherwise is a lie the customer can detect.
 */

const PAGE_SIZE = 9;

const DEFAULTS = {
  q: "",
  category: "",
  subcategory: "",
  region: "",
  district: "",
  ward: "",
  /** A public place used as a search anchor; sets lat/lng/radius, not a scope. */
  landmark: "",
  place: "",
  /** Display label for the chosen location. Never sent to the API. */
  loc: "",
  min_price: "",
  max_price: "",
  purpose: "",
  amenities: "",
  facilities: "",
  sort: "",
  view: "grid",
  lat: "",
  lng: "",
  radius: "",
  page: "1",
};

/**
 * How many cards separate one inline advertisement from the next.
 *
 * Six, because it divides cleanly by every column count this grid uses — one,
 * two and three — so the strip always lands on a row boundary rather than
 * halfway through one. At three-across that is an ad every two rows, which is
 * the sparsest spacing that still gives the placement inventory on a page of
 * twenty-four results.
 */
const CARDS_BETWEEN_ADS = 6;

/**
 * The creative to render after the card at `position`, if any.
 *
 * Returns null once the supplied creatives run out rather than cycling them.
 * Repeating the same banner four times down one page is the single thing that
 * makes a marketplace feel like a content farm, and the placement already caps
 * how many distinct campaigns it will serve.
 */
function inlineAdAfter(position: number, ads: ApiAdCreative[]): ApiAdCreative | null {
  // `position` is zero-based, so the boundary after the sixth card is index 5.
  if ((position + 1) % CARDS_BETWEEN_ADS !== 0) return null;

  return ads[Math.floor((position + 1) / CARDS_BETWEEN_ADS) - 1] ?? null;
}

export function ListingsBrowser({
  categories,
  /*
   * Fetched on the SERVER by the page and handed down, rather than queried
   * here.
   *
   * This component is client-side and its listings arrive asynchronously, so a
   * second client query for ads would resolve at its own pace and insert
   * strips into a grid the visitor is already reading — content moving under a
   * thumb, which is the exact failure the reserved-box work exists to prevent.
   * Server-fetched, they are in the first paint alongside the cards.
   */
  inlineAds = [],
}: {
  categories: ApiCategory[];
  inlineAds?: ApiAdCreative[];
}) {
  const { filters, setFilters, attributeParams, clearAttributes } = useUrlFilters(DEFAULTS);
  const [tab, setTab] = useState<"default" | "ai">("default");
  const [sheetOpen, setSheetOpen] = useState(false);
  const { columns, setColumns } = useGridColumns(2);

  // Price is drafted and applied on a button press; everything else commits
  // immediately. See the note in ListingFilters.
  const [draft, setDraft] = useState<FilterDraft>({
    min_price: filters.min_price,
    max_price: filters.max_price,
  });

  const selectedCategory = categories.find((category) => category.slug === filters.category);

  const query = useMemo(() => {
    return {
      q: filters.q || undefined,
      // The API takes a leaf category; the vertical is the fallback.
      category: filters.subcategory || filters.category || undefined,
      region: filters.region || undefined,
      district: filters.district || undefined,
      ward: filters.ward || undefined,
      place: filters.place || undefined,
      min_price: filters.min_price || undefined,
      max_price: filters.max_price || undefined,
      purpose: filters.purpose || undefined,
      amenities: splitList(filters.amenities),
      facilities: splitList(filters.facilities),
      lat: filters.lat || undefined,
      lng: filters.lng || undefined,
      radius: filters.radius || undefined,
      /*
       * Attribute filters come straight from the URL. The codes are whatever
       * the selected category defines, so nothing here names one.
       */
      ...attributeParams,
      // An explicit choice wins; otherwise a geo search is most useful nearest
      // first, and everything else takes the API's own relevance order.
      sort: filters.sort || (filters.lat ? "distance" : undefined),
      page: filters.page,
      per_page: PAGE_SIZE,
    };
  }, [filters, attributeParams]);

  const listings = useQuery({
    queryKey: ["listings", query],
    queryFn: () => apiGet<Paginated<ApiListing>>("/listings", query),
    staleTime: 30 * 1000,
    // Keeps the previous page on screen while the next one loads, instead of
    // collapsing to skeletons and losing the scroll position.
    placeholderData: (previous) => previous,
  });

  const views = (listings.data?.data ?? []).map(toListingView);
  const meta = listings.data?.meta;
  const isMap = filters.view === "map";
  const activeFilters = countActiveFilters(filters, attributeParams);

  const pins: MapPin[] = useMemo(
    () =>
      views
        .filter((listing) => listing.latitude !== null && listing.longitude !== null)
        .map((listing) => ({
          id: listing.slug,
          lat: listing.latitude as number,
          lng: listing.longitude as number,
          label: listing.title,
          sublabel:
            listing.price === null
              ? undefined
              : listing.price >= 1_000_000
                ? `${Math.round(listing.price / 1_000_000)}M`
                : `${Math.round(listing.price / 1000)}K`,
          meta: listing.location,
          image: listing.image,
          href: `/listings/${listing.slug}`,
          tone: "listing" as const,
        })),
    [views],
  );

  /*
   * Panning or zooming re-runs the search over what is on screen.
   *
   * `replace` rather than `push`: the map is a continuous control and every
   * settle would otherwise add a history entry. Resetting the page is
   * deliberate — moving to a new area and staying on page 4 shows an empty grid.
   */
  const searchViewport = ({ center, radiusKm }: MapViewport) =>
    setFilters(
      {
        lat: center.lat.toFixed(5),
        lng: center.lng.toFixed(5),
        radius: radiusKm.toFixed(1),
      },
      { replace: true },
    );

  const applyDraft = () => {
    setFilters({
      min_price: draft.min_price || null,
      max_price: draft.max_price || null,
    });
    setSheetOpen(false);
  };

  const resetAll = () => {
    setDraft({ min_price: "", max_price: "" });
    setFilters({
      ...clearAttributes(),
      ...Object.fromEntries(
        Object.keys(DEFAULTS)
          // `q` and `view` are not filters: clearing the search term or
          // throwing someone back to the grid is not what "Reset" means here.
          .filter((key) => key !== "q" && key !== "view" && key !== "page")
          .map((key) => [key, null]),
      ),
    });
  };

  const filterPanel = (
    <ListingFilters
      filters={filters}
      setFilters={setFilters}
      draft={draft}
      setDraft={setDraft}
      attributeParams={attributeParams}
      clearAttributes={clearAttributes}
      categories={categories}
      onApply={applyDraft}
    />
  );

  /*
   * One column or two on a phone; the desktop grid is untouched. Written as
   * whole class strings because Tailwind cannot see a class it has to
   * concatenate at runtime — `grid-cols-${n}` compiles to nothing.
   */
  const gridClass =
    columns === 1
      ? "grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3"
      : "grid grid-cols-2 gap-3 sm:gap-6 md:grid-cols-2 xl:grid-cols-3";

  // The h1 lives in the server-rendered <SearchHero>, not here.

  return (
    <section className="bg-page py-10">
      <div className="mx-auto max-w-7xl px-4">
        <div className="mb-8 rounded-[5px] border border-border bg-white p-4 sm:p-6">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div className="flex gap-2">
              <button
                onClick={() => setTab("default")}
                className={`rounded-[5px] px-5 py-2 text-sm font-semibold ${tab === "default" ? "bg-teal text-white" : "bg-transparent text-navy"}`}
              >
                Default Search
              </button>
              <button
                onClick={() => setTab("ai")}
                className={`rounded-[5px] px-5 py-2 text-sm font-semibold ${tab === "ai" ? "bg-teal text-white" : "bg-transparent text-navy"}`}
              >
                AI Search
              </button>
            </div>

            <div className="flex rounded-[5px] border border-border p-1">
              <button
                onClick={() => setFilters({ view: "grid" }, { resetPage: false })}
                aria-pressed={!isMap}
                className={`inline-flex items-center gap-1.5 rounded-[3px] px-3 py-1.5 text-sm font-semibold transition ${!isMap ? "bg-teal text-white" : "text-navy"}`}
              >
                <LayoutGrid className="h-4 w-4" />
                Grid
              </button>
              <button
                onClick={() => setFilters({ view: "map" }, { resetPage: false })}
                aria-pressed={isMap}
                className={`inline-flex items-center gap-1.5 rounded-[3px] px-3 py-1.5 text-sm font-semibold transition ${isMap ? "bg-teal text-white" : "text-navy"}`}
              >
                <MapIcon className="h-4 w-4" />
                Map
              </button>
            </div>
          </div>

          {tab === "ai" && (
            <p className="mb-4 rounded-lg bg-[#FFF4DB] px-4 py-3 text-sm text-[#8A6A17]">
              Describing what you want in your own words is coming. For now, the search below
              matches titles, descriptions and locations.
            </p>
          )}

          <div className="grid grid-cols-1 items-end gap-3 lg:grid-cols-12">
            <div className="lg:col-span-6">
              <label className="mb-2 block text-sm font-semibold text-navy">Search Listings</label>
              <SearchBox
                defaultValue={filters.q}
                placeholder={tab === "ai" ? "Describe your ideal listing..." : "Search listings..."}
                onSubmit={(value) => setFilters({ q: value })}
              />
            </div>

            <div className="lg:col-span-2">
              <label className="mb-2 block text-sm font-semibold text-navy" htmlFor="category">
                Category
              </label>
              <select
                id="category"
                value={filters.category}
                onChange={(event) =>
                  setFilters({
                    ...clearAttributes(),
                    category: event.target.value || null,
                    subcategory: null,
                  })
                }
                className="h-[52px] w-full rounded-lg border border-border bg-white px-4 text-[15px] font-medium outline-none focus:border-teal"
              >
                <option value="">All Categories</option>
                {categories.map((category) => (
                  <option key={category.slug} value={category.slug}>
                    {category.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="lg:col-span-2">
              <label className="mb-2 block text-sm font-semibold text-navy" htmlFor="subcategory">
                Subcategory
              </label>
              <select
                id="subcategory"
                value={filters.subcategory}
                onChange={(event) =>
                  setFilters({ ...clearAttributes(), subcategory: event.target.value || null })
                }
                disabled={!selectedCategory}
                className="h-[52px] w-full rounded-lg border border-border bg-white px-4 text-[15px] font-medium outline-none focus:border-teal disabled:bg-page disabled:text-muted-foreground"
              >
                <option value="">
                  {selectedCategory ? "All Subcategories" : "Select Category"}
                </option>
                {(selectedCategory?.children ?? []).map((child) => (
                  <option key={child.slug} value={child.slug}>
                    {child.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="lg:col-span-2">
              <button
                onClick={() => setFilters({ q: filters.q })}
                className="h-[52px] w-full rounded-lg bg-teal font-semibold text-white transition-all hover:opacity-90"
              >
                Search
              </button>
            </div>
          </div>
        </div>

        {/* -------------------------------------------- mobile toolbar --- */}
        <div className="mb-4 flex items-center justify-between gap-3 lg:hidden">
          <button
            type="button"
            onClick={() => setSheetOpen(true)}
            className="inline-flex items-center gap-2 rounded-full border-2 border-border bg-white px-4 py-2.5 text-sm font-semibold text-navy tap-scale"
          >
            <SlidersHorizontal className="h-4 w-4" />
            Filters
            {activeFilters > 0 && (
              <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-teal px-1.5 text-[11px] font-bold text-white">
                {activeFilters}
              </span>
            )}
          </button>

          {!isMap && (
            <div className="flex rounded-full border border-border bg-white p-1">
              <button
                type="button"
                onClick={() => setColumns(1)}
                aria-pressed={columns === 1}
                aria-label="One column"
                className={`flex h-8 w-9 items-center justify-center rounded-full transition ${
                  columns === 1 ? "bg-teal text-white" : "text-navy"
                }`}
              >
                <Square className="h-4 w-4" />
              </button>
              <button
                type="button"
                onClick={() => setColumns(2)}
                aria-pressed={columns === 2}
                aria-label="Two columns"
                className={`flex h-8 w-9 items-center justify-center rounded-full transition ${
                  columns === 2 ? "bg-teal text-white" : "text-navy"
                }`}
              >
                <Rows2 className="h-4 w-4 rotate-90" />
              </button>
            </div>
          )}
        </div>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
          {/* Desktop sidebar. The sheet renders the same panel on mobile. */}
          <aside className="hidden space-y-6 lg:col-span-3 lg:block">
            {filterPanel}

            {activeFilters > 0 && (
              <button
                onClick={resetAll}
                className="w-full rounded-[5px] border border-border bg-white px-6 py-3 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
              >
                Reset all filters
              </button>
            )}
          </aside>

          <div className="lg:col-span-9">
            {isMap ? (
              <div className="space-y-4">
                <LazyMapView
                  pins={pins}
                  loading={listings.isFetching}
                  height={520}
                  center={
                    filters.lat && filters.lng
                      ? { lat: Number(filters.lat), lng: Number(filters.lng) }
                      : null
                  }
                  /*
                   * Frame the results only until the customer takes over. Once
                   * they have panned there is a lat/lng in the URL, and
                   * re-fitting on every new result set would fight them.
                   */
                  fitToPins={!filters.lat}
                  autoSearch
                  onAreaSearch={searchViewport}
                />

                <p className="text-sm text-muted-foreground">
                  {pins.length} of {views.length} results on this page have a location.
                  {views.length > 0 && pins.length === 0 && " Try a different area."}
                </p>

                <div className={gridClass}>
                  {views.map((listing) => (
                    <ListingCard key={listing.slug} p={listing} />
                  ))}
                </div>
              </div>
            ) : listings.isPending ? (
              <div className={gridClass}>
                {/*
                  * The skeleton count MUST match the page size.
                  *
                  * Six placeholders standing in for nine cards means the grid
                  * grows by a full row when the data lands, pushing the footer
                  * down — measured at CLS 0.51 on this page, five times the
                  * "needs improvement" threshold. `PAGE_SIZE` is the one number
                  * both the query and the placeholder read.
                  */}
                  <CardSkeleton count={PAGE_SIZE} />
              </div>
            ) : listings.error ? (
              <ErrorState error={listings.error} onRetry={() => void listings.refetch()} />
            ) : views.length === 0 ? (
              <EmptyState
                title="No listings match your filters"
                description="Try widening the price range, clearing a filter, or searching a different area."
                action={
                  <Link
                    href="/listings"
                    className="inline-flex items-center justify-center rounded-full bg-teal px-5 py-2 font-semibold text-white"
                  >
                    Clear all filters
                  </Link>
                }
              />
            ) : (
              <div className={gridClass}>
                {views.map((listing, position) => (
                  <Fragment key={listing.slug}>
                    <ListingCard p={listing} />
                    {inlineAdAfter(position, inlineAds) && (
                      /*
                       * A full-width row inside the grid, not a break in it.
                       *
                       * `col-span-full` makes the strip span whatever the
                       * column count happens to be, so one rule covers the
                       * one-, two- and three-across layouts. Placing it as a
                       * grid ITEM rather than splitting the grid in two is what
                       * keeps the cards' row alignment intact either side.
                       */
                      <div className="col-span-full">
                        <SponsoredBanner
                          creative={inlineAdAfter(position, inlineAds)!}
                          placement="listings_inline"
                          variant="strip"
                        />
                      </div>
                    )}
                  </Fragment>
                ))}
              </div>
            )}

            {meta && meta.last_page > 1 && (
              <div className="mt-10 flex items-center justify-center gap-2">
                <button
                  onClick={() =>
                    setFilters({ page: String(meta.current_page - 1) }, { resetPage: false })
                  }
                  disabled={meta.current_page === 1}
                  className="px-4 py-2 text-sm text-navy disabled:opacity-40"
                >
                  Previous
                </button>

                {buildPageList(meta.current_page, meta.last_page).map((item, index) =>
                  item === "…" ? (
                    <span key={`gap-${index}`} className="px-2 text-muted-foreground">
                      …
                    </span>
                  ) : (
                    <button
                      key={item}
                      onClick={() => setFilters({ page: String(item) }, { resetPage: false })}
                      aria-current={meta.current_page === item ? "page" : undefined}
                      className={`h-10 w-10 rounded-[5px] text-sm font-semibold ${
                        meta.current_page === item
                          ? "bg-teal text-white"
                          : "border border-border bg-white text-navy"
                      }`}
                    >
                      {item}
                    </button>
                  ),
                )}

                <button
                  onClick={() =>
                    setFilters({ page: String(meta.current_page + 1) }, { resetPage: false })
                  }
                  disabled={meta.current_page === meta.last_page}
                  className="px-4 py-2 text-sm text-navy disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      <BottomSheet
        open={sheetOpen}
        onClose={() => setSheetOpen(false)}
        title="Filters"
        description={
          activeFilters > 0
            ? `${activeFilters} applied`
            : "Narrow the results by place, price and features"
        }
        footer={
          <div className="flex gap-3">
            <button
              type="button"
              onClick={resetAll}
              disabled={activeFilters === 0}
              className="flex-1 rounded-full border-2 border-border px-5 py-3 text-sm font-semibold text-navy transition disabled:opacity-40"
            >
              Reset
            </button>
            <button
              type="button"
              onClick={applyDraft}
              className="flex-[2] rounded-full bg-teal px-5 py-3 text-sm font-semibold text-white tap-scale"
            >
              {/* The live count is the point of filtering on a phone: it tells
                  you whether the last tap helped before you close the sheet. */}
              Show {meta?.total ?? views.length} result{(meta?.total ?? views.length) === 1 ? "" : "s"}
            </button>
          </div>
        }
      >
        {filterPanel}
      </BottomSheet>
    </section>
  );
}

function buildPageList(page: number, total: number): Array<number | "…"> {
  if (total <= 5) return Array.from({ length: total }, (_, index) => index + 1);

  const pages: Array<number | "…"> = [1, 2];
  if (page > 3) pages.push("…");
  if (page !== 1 && page !== 2 && page !== total) pages.push(page);
  if (page < total - 1) pages.push("…");
  pages.push(total);

  return pages;
}
