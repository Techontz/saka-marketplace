"use client";

import { useQuery } from "@tanstack/react-query";
import { MapPin, Navigation } from "lucide-react";

import { SafeImage } from "@/components/ui/SafeImage";
import { apiGet } from "@/lib/api/browser";
import { googleDirectionsUrl } from "@/lib/config";
import type { ApiPlace, Paginated } from "@/lib/types";
import { formatDistance, formatPlaceAddress } from "@/lib/view-models";

/**
 * What is around this business — schools, hospitals, banks, transport.
 *
 * The reason it belongs on a property page: "what is nearby" is most of what
 * decides whether somewhere is worth viewing, and the directory already holds
 * it. A 2 km radius keeps the list to genuine walking-distance context rather
 * than everything in the city.
 *
 * Rendered only when it finds something, so a business outside the seeded area
 * gets no empty section.
 */
export function NearbyPlaces({ lat, lng }: { lat: number; lng: number }) {
  const places = useQuery({
    queryKey: ["business-nearby-places", lat, lng],
    queryFn: () =>
      apiGet<Paginated<ApiPlace>>("/public-places", {
        lat,
        lng,
        radius: 2,
        per_page: 8,
      }),
    staleTime: 10 * 60 * 1000,
  });

  const rows = places.data?.data ?? [];

  if (places.isPending || rows.length === 0) return null;

  return (
    <div className="rounded-xl border border-border bg-white p-6">
      <h3 className="mb-4 flex items-center gap-2 text-lg font-bold text-navy">
        <MapPin className="h-4 w-4 text-teal" />
        What&apos;s nearby
      </h3>

      <ul className="space-y-3">
        {rows.map((place) => (
          <li key={place.slug} className="flex items-center gap-3">
            <SafeImage
              src={place.image_url}
              alt=""
              className="h-10 w-10 shrink-0 rounded object-cover"
              fallbackClassName="h-10 w-10 shrink-0 rounded bg-page text-base"
              fallback={place.category?.icon ?? "📍"}
            />

            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-navy">{place.name}</p>
              <p className="truncate text-xs text-muted-foreground">
                {place.category?.name}
                {place.location.distance_km !== undefined &&
                  ` · ${formatDistance(place.location.distance_km)}`}
              </p>
            </div>

            {place.location.latitude !== null && place.location.longitude !== null && (
              <a
                href={googleDirectionsUrl(place.location.latitude, place.location.longitude)}
                target="_blank"
                rel="noopener noreferrer"
                aria-label={`Directions to ${place.name}`}
                title={formatPlaceAddress(place) ?? place.name}
                className="shrink-0 text-muted-foreground transition hover:text-teal"
              >
                <Navigation className="h-4 w-4" />
              </a>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
