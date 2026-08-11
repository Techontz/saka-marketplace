"use client";

import { useQuery } from "@tanstack/react-query";
import { LayoutGrid, Map as MapIcon } from "lucide-react";

import { BusinessCard } from "@/components/businesses/BusinessCard";
import { LazyMapView } from "@/components/map/LazyMapView";
import type { MapPin } from "@/components/map/MapView";
import { CardSkeleton, EmptyState, ErrorState } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiBusiness, Paginated } from "@/lib/types";
import { useUrlFilters } from "@/hooks/useUrlFilters";
import { useGeolocation } from "@/hooks/useGeolocation";

/** The business directory: search, filter by trade, and find what is nearby. */

const TYPES = [
  ["", "All businesses"],
  ["shop", "Shops"],
  ["landlord", "Landlords"],
  ["restaurant", "Restaurants"],
  ["hotel", "Hotels"],
  ["car_dealer", "Car dealers"],
  ["service_provider", "Services"],
  ["pharmacy", "Pharmacies"],
  ["clinic", "Clinics"],
  ["school", "Schools"],
  ["event_organizer", "Events"],
] as const;

const DEFAULTS = { q: "", business_type: "", verified: "", view: "grid", lat: "", lng: "", radius: "", page: "1" };

export function BusinessBrowser() {
  const { filters, setFilters } = useUrlFilters(DEFAULTS);
  const geo = useGeolocation();

  const businesses = useQuery({
    queryKey: ["businesses", filters],
    queryFn: () =>
      apiGet<Paginated<ApiBusiness>>("/businesses", {
        q: filters.q || undefined,
        business_type: filters.business_type || undefined,
        verified: filters.verified || undefined,
        lat: filters.lat || undefined,
        lng: filters.lng || undefined,
        radius: filters.radius || undefined,
        page: filters.page,
        per_page: 24,
      }),
  });

  const rows = businesses.data?.data ?? [];
  const meta = businesses.data?.meta;
  const isMap = filters.view === "map";

  const pins: MapPin[] = rows
    .filter((business) => business.location.latitude !== null && business.location.longitude !== null)
    .map((business) => ({
      id: business.slug,
      lat: business.location.latitude as number,
      lng: business.location.longitude as number,
      label: business.display_name,
      meta: [business.location.district, business.location.region].filter(Boolean).join(", "),
      image: business.logo_url ?? null,
      href: `/businesses/${business.slug}`,
      tone: "business" as const,
    }));

  const findNearMe = async () => {
    const position = await geo.request();
    if (position) {
      setFilters({ lat: position.lat.toFixed(5), lng: position.lng.toFixed(5), radius: "10" });
    }
  };

  return (
    <>
      <section className="bg-page py-10">
        <div className="mx-auto max-w-7xl px-4">
          <div className="mb-8 rounded-[5px] border border-border bg-white p-6">
            <div className="grid grid-cols-1 gap-3 lg:grid-cols-12 lg:items-end">
              <div className="lg:col-span-5">
                <label htmlFor="business-q" className="mb-2 block text-sm font-semibold text-navy">
                  Search businesses
                </label>
                <input
                  id="business-q"
                  defaultValue={filters.q}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") setFilters({ q: event.currentTarget.value });
                  }}
                  placeholder="Name or what they do…"
                  className="h-[52px] w-full rounded-lg border border-border px-4 text-[15px] outline-none focus:border-teal"
                />
              </div>

              <div className="lg:col-span-3">
                <label htmlFor="business-type" className="mb-2 block text-sm font-semibold text-navy">
                  Type
                </label>
                <select
                  id="business-type"
                  value={filters.business_type}
                  onChange={(event) => setFilters({ business_type: event.target.value || null })}
                  className="h-[52px] w-full rounded-lg border border-border bg-white px-4 text-[15px] font-medium outline-none focus:border-teal"
                >
                  {TYPES.map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="lg:col-span-2">
                <button
                  onClick={findNearMe}
                  className="h-[52px] w-full rounded-lg border border-border bg-white font-semibold text-navy transition hover:border-teal hover:text-teal"
                >
                  {geo.loading ? "Locating…" : "Near me"}
                </button>
              </div>

              <div className="lg:col-span-2">
                <div className="flex h-[52px] rounded-lg border border-border p-1">
                  <button
                    onClick={() => setFilters({ view: "grid" }, { resetPage: false })}
                    className={`flex flex-1 items-center justify-center gap-1.5 rounded-[3px] text-sm font-semibold ${!isMap ? "bg-teal text-white" : "text-navy"}`}
                  >
                    <LayoutGrid className="h-4 w-4" /> Grid
                  </button>
                  <button
                    onClick={() => setFilters({ view: "map" }, { resetPage: false })}
                    className={`flex flex-1 items-center justify-center gap-1.5 rounded-[3px] text-sm font-semibold ${isMap ? "bg-teal text-white" : "text-navy"}`}
                  >
                    <MapIcon className="h-4 w-4" /> Map
                  </button>
                </div>
              </div>
            </div>

            <label className="mt-4 flex items-center gap-2 text-sm text-navy">
              <input
                type="checkbox"
                checked={filters.verified === "1"}
                onChange={(event) => setFilters({ verified: event.target.checked ? "1" : null })}
                className="h-4 w-4 accent-teal"
              />
              Verified businesses only
            </label>

            {geo.error && <p className="mt-2 text-sm text-muted-foreground">{geo.error}</p>}
          </div>

          {isMap && (
            <div className="mb-6">
              <LazyMapView
                pins={pins}
                loading={businesses.isFetching}
                height={480}
                center={
                  filters.lat && filters.lng
                    ? { lat: Number(filters.lat), lng: Number(filters.lng) }
                    : null
                }
                fitToPins={!filters.lat}
                autoSearch
                onAreaSearch={({ center, radiusKm }) =>
                  setFilters(
                    {
                      lat: center.lat.toFixed(5),
                      lng: center.lng.toFixed(5),
                      radius: radiusKm.toFixed(1),
                    },
                    { replace: true },
                  )
                }
              />
            </div>
          )}

          {businesses.isPending ? (
            <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
              {/* Approximates a first screenful. Matching `per_page` exactly would
                  reserve four screens of placeholders for a directory that
                  usually returns a handful, and the grid would collapse when
                  the data landed. */}
              <CardSkeleton count={6} height={220} />
            </div>
          ) : businesses.error ? (
            <ErrorState error={businesses.error} onRetry={() => void businesses.refetch()} />
          ) : rows.length === 0 ? (
            <EmptyState
              title="No businesses match"
              description={
                filters.radius
                  ? "Nothing in this area yet. Try widening the map or clearing the location filter."
                  : "Try a different search or category."
              }
            />
          ) : (
            <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
              {rows.map((business) => (
                <BusinessCard key={business.slug} business={business} />
              ))}
            </div>
          )}

          {meta && meta.last_page > 1 && (
            <div className="mt-10 flex items-center justify-center gap-2">
              <button
                onClick={() => setFilters({ page: String(meta.current_page - 1) }, { resetPage: false })}
                disabled={meta.current_page === 1}
                className="px-4 py-2 text-sm text-navy disabled:opacity-40"
              >
                Previous
              </button>
              <span className="text-sm text-muted-foreground">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <button
                onClick={() => setFilters({ page: String(meta.current_page + 1) }, { resetPage: false })}
                disabled={meta.current_page === meta.last_page}
                className="px-4 py-2 text-sm text-navy disabled:opacity-40"
              >
                Next
              </button>
            </div>
          )}
        </div>
      </section>
    </>
  );
}
