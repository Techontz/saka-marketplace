"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { Globe, MapPin, Phone } from "lucide-react";

import { BusinessCard } from "@/components/businesses/BusinessCard";
import { ListingCard } from "@/components/listings/ListingCard";
import { DirectionsLinks } from "@/components/map/DirectionsLinks";
import { LazyMapView } from "@/components/map/LazyMapView";
import type { MapPin as Pin } from "@/components/map/MapView";
import { SafeImage } from "@/components/ui/SafeImage";
import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiBusiness, ApiListing, ApiPlace, Paginated } from "@/lib/types";
import { formatDistance, formatPlaceAddress, toListingView } from "@/lib/view-models";
import { useGeolocation } from "@/hooks/useGeolocation";
import { useUrlFilters } from "@/hooks/useUrlFilters";

/**
 * Places in a category, and what is near the selected one.
 *
 * "Nearby" is the reason this page exists on a marketplace rather than in a
 * directory: someone looking at a hospital is usually looking for somewhere to
 * live or shop near it, so selecting a place runs a radius search for listings
 * and businesses around it.
 */
const DEFAULTS = { lat: "", lng: "", radius: "" };

export function PlaceCategoryBrowser({ slug, name }: { slug: string; name: string }) {
  const [selected, setSelected] = useState<ApiPlace | null>(null);
  const geo = useGeolocation();

  /*
   * The area lives in the URL, exactly as it does for listings and businesses,
   * so panning the map here is shareable and survives the back button too.
   */
  const { filters, setFilters } = useUrlFilters(DEFAULTS);

  const places = useQuery({
    queryKey: ["places", slug, filters.lat, filters.lng, filters.radius],
    queryFn: () =>
      apiGet<Paginated<ApiPlace>>("/public-places", {
        category: slug,
        per_page: 48,
        lat: filters.lat || undefined,
        lng: filters.lng || undefined,
        radius: filters.lat ? filters.radius || "50" : undefined,
      }),
  });

  const rows = places.data?.data ?? [];
  const active = selected ?? rows[0] ?? null;
  const hasCoords =
    active != null && active.location.latitude !== null && active.location.longitude !== null;

  const nearbyListings = useQuery({
    queryKey: ["place-nearby-listings", active?.slug],
    queryFn: () =>
      apiGet<Paginated<ApiListing>>("/listings", {
        lat: active!.location.latitude,
        lng: active!.location.longitude,
        radius: 5,
        sort: "distance",
        per_page: 4,
      }),
    enabled: Boolean(active && hasCoords),
  });

  const nearbyBusinesses = useQuery({
    queryKey: ["place-nearby-businesses", active?.slug],
    queryFn: () =>
      apiGet<Paginated<ApiBusiness>>("/businesses", {
        lat: active!.location.latitude,
        lng: active!.location.longitude,
        radius: 5,
        per_page: 6,
      }),
    enabled: Boolean(active && hasCoords),
  });

  const pins: Pin[] = rows
    .filter((place) => place.location.latitude !== null && place.location.longitude !== null)
    .map((place) => ({
      id: place.slug,
      lat: place.location.latitude as number,
      lng: place.location.longitude as number,
      label: place.name,
      meta: formatPlaceAddress(place) ?? undefined,
      image: place.image_url,
      tone: "place" as const,
    }));

  return (
    <>
      {/* The hero is server-rendered by the page so the h1 is in the HTML. */}
      <section className="bg-page py-10">
        <div className="mx-auto max-w-7xl px-4">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
              {places.data ? `${places.data.meta.total} place${places.data.meta.total !== 1 ? "s" : ""}` : "Loading…"}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              {filters.lat && (
                <button
                  onClick={() => setFilters({ lat: null, lng: null, radius: null })}
                  className="rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
                >
                  Clear area
                </button>
              )}
              <button
                onClick={async () => {
                  const position = await geo.request();
                  if (!position) return;
                  setFilters({
                    lat: position.lat.toFixed(5),
                    lng: position.lng.toFixed(5),
                    radius: "50",
                  });
                }}
                className="rounded-full border border-border bg-white px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
              >
                {geo.loading ? "Locating…" : "Sort by distance from me"}
              </button>
            </div>
          </div>

          {pins.length > 0 && (
            <div className="mb-8">
              <LazyMapView
                pins={pins}
                height={420}
                loading={places.isFetching}
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
                activePinId={active?.slug}
                onPinClick={(pin) => {
                  const place = rows.find((item) => item.slug === pin.id);
                  if (place) setSelected(place);
                }}
              />
            </div>
          )}

          <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <div className="lg:col-span-1">
              <h2 className="mb-4 text-lg font-bold text-navy">All {name.toLowerCase()}</h2>

              {places.isPending ? (
                <div className="space-y-3">
                  <RowSkeleton count={4} />
                </div>
              ) : places.error ? (
                <ErrorState error={places.error} onRetry={() => void places.refetch()} />
              ) : rows.length === 0 ? (
                <EmptyState title="Nothing listed here yet" />
              ) : (
                <ul className="max-h-[560px] space-y-2 overflow-y-auto pr-1">
                  {rows.map((place) => (
                    <li key={place.slug}>
                      <button
                        onClick={() => setSelected(place)}
                        className={`w-full rounded-lg border p-4 text-left transition ${
                          active?.slug === place.slug
                            ? "border-teal bg-teal/5"
                            : "border-border bg-white hover:border-teal"
                        }`}
                      >
                        <div className="flex items-start gap-3">
                          <SafeImage
                            src={place.image_url}
                            alt=""
                            className="h-12 w-12 shrink-0 rounded object-cover"
                            fallbackClassName="h-12 w-12 shrink-0 rounded bg-page text-lg"
                            fallback={place.category?.icon ?? "📍"}
                          />

                          <div className="min-w-0 flex-1">
                            <p className="font-semibold text-navy">{place.name}</p>
                            <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                              <MapPin className="h-3 w-3 shrink-0 text-teal" />
                              <span className="truncate">
                                {formatPlaceAddress(place) ?? "Address not published"}
                              </span>
                            </p>
                            {place.location.distance_km !== undefined && (
                              <p className="mt-1 text-xs font-semibold text-teal">
                                {formatDistance(place.location.distance_km)}
                              </p>
                            )}
                          </div>
                        </div>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="space-y-8 lg:col-span-2">
              {active && (
                <div className="rounded-xl border border-border bg-white p-6">
                  <h2 className="text-2xl font-extrabold text-navy">{active.name}</h2>
                  {active.description && (
                    <p className="mt-2 text-muted-foreground">{active.description}</p>
                  )}

                  <ul className="mt-4 space-y-2 text-sm">
                    <li className="flex items-start gap-2 text-muted-foreground">
                      <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal" />
                      {formatPlaceAddress(active) ?? "Address not published"}
                    </li>
                    {active.phone && (
                      <li>
                        <a href={`tel:${active.phone}`} className="flex items-center gap-2 text-navy hover:text-teal">
                          <Phone className="h-4 w-4 text-teal" /> {active.phone}
                        </a>
                      </li>
                    )}
                    {active.website && (
                      <li>
                        <a
                          href={active.website}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="flex items-center gap-2 truncate text-navy hover:text-teal"
                        >
                          <Globe className="h-4 w-4 shrink-0 text-teal" />
                          <span className="truncate">{active.website}</span>
                        </a>
                      </li>
                    )}
                  </ul>

                  {hasCoords && (
                    <DirectionsLinks
                      className="mt-5"
                      lat={active.location.latitude as number}
                      lng={active.location.longitude as number}
                      label={active.name}
                    />
                  )}
                </div>
              )}

              {active && hasCoords && (
                <>
                  <section>
                    <h2 className="mb-4 text-xl font-extrabold text-navy">
                      Listings near {active.name}
                    </h2>
                    {nearbyListings.isPending ? (
                      <RowSkeleton count={2} />
                    ) : (nearbyListings.data?.data.length ?? 0) === 0 ? (
                      <p className="rounded-xl border border-dashed border-border bg-white p-8 text-center text-sm text-muted-foreground">
                        Nothing listed within 5&nbsp;km yet.
                      </p>
                    ) : (
                      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {(nearbyListings.data?.data ?? []).map((listing) => (
                          <ListingCard key={listing.slug} p={toListingView(listing)} />
                        ))}
                      </div>
                    )}
                  </section>

                  <section>
                    <h2 className="mb-4 text-xl font-extrabold text-navy">
                      Businesses near {active.name}
                    </h2>
                    {nearbyBusinesses.isPending ? (
                      <RowSkeleton count={2} />
                    ) : (nearbyBusinesses.data?.data.length ?? 0) === 0 ? (
                      <p className="rounded-xl border border-dashed border-border bg-white p-8 text-center text-sm text-muted-foreground">
                        No businesses within 5&nbsp;km yet.
                      </p>
                    ) : (
                      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {(nearbyBusinesses.data?.data ?? []).map((business) => (
                          <BusinessCard key={business.slug} business={business} />
                        ))}
                      </div>
                    )}
                  </section>
                </>
              )}
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
