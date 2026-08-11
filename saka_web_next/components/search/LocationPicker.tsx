"use client";

import { useQuery } from "@tanstack/react-query";
import { Landmark, MapPin } from "lucide-react";

import { Combobox, type ComboboxOption } from "@/components/ui/Combobox";
import { LocationAutocomplete, type LocationSuggestion } from "@/components/search/LocationAutocomplete";
import { apiGet } from "@/lib/api/browser";
import type { ApiLocation, ApiPlace, Paginated } from "@/lib/types";

/**
 * Where? — Region → District → Ward → Landmark.
 *
 * The location filter used to be one free-text box posted to the API as a
 * `place` LIKE query. Two things were wrong with that, and both cost sales:
 * a customer had to already know whether "Masaki" was a ward or a district
 * before their filter would work, and a typo returned an empty catalogue with
 * no hint that the spelling was the problem.
 *
 * ── WHY BOTH A SEARCH BOX AND A CASCADE ───────────────────────────────────
 * They answer different questions. Someone who knows where they want to live
 * types "Mikocheni" and is done in one action; someone browsing does not know
 * the ward names and needs to walk down from the region. Offering only the
 * cascade punishes the first customer with four taps; offering only the search
 * box punishes the second, who has nothing to type.
 *
 * The search box is therefore a SHORTCUT that fills the cascade, not a rival
 * control — picking "Mikocheni" from it sets district and ward below, so the
 * two are never out of step.
 *
 * ── WHY CLIENT-SIDE FILTERING ─────────────────────────────────────────────
 * Every level is small and cached for a week (31 regions, 155 districts, 70
 * wards). Loading the level once and filtering in the browser makes type-ahead
 * instant and costs nothing per keystroke; a server round trip per letter would
 * be slower and would burn the search throttle.
 *
 * ── THE FOURTH LEVEL ──────────────────────────────────────────────────────
 * There is no `streets` table, so the fourth tier is LANDMARKS — the public
 * places directory, scoped to the chosen district. That is real data, and it
 * is how people actually navigate here: "near Mlimani City" is a more natural
 * search than any street name.
 */

export type LocationValue = {
  region: string;
  district: string;
  ward: string;
  /** A landmark slug. Sets lat/lng/radius rather than an administrative filter. */
  landmark: string;
  /** Human label for whatever is selected, for the filter chip and the URL. */
  label: string;
  lat: string;
  lng: string;
  radius: string;
};

export function LocationPicker({
  value,
  onChange,
}: {
  value: LocationValue;
  /** Same signature as `setFilters` — a partial patch of URL params. */
  onChange: (patch: Record<string, string | null>) => void;
}) {
  const regions = useQuery({
    queryKey: ["locations", "regions"],
    queryFn: () => apiGet<{ data: ApiLocation[] }>("/locations/regions"),
    staleTime: 24 * 60 * 60 * 1000,
  });

  const districts = useQuery({
    queryKey: ["locations", "districts", value.region],
    queryFn: () => apiGet<{ data: ApiLocation[] }>(`/locations/regions/${value.region}/districts`),
    enabled: Boolean(value.region),
    staleTime: 24 * 60 * 60 * 1000,
  });

  const wards = useQuery({
    queryKey: ["locations", "wards", value.district],
    queryFn: () => apiGet<{ data: ApiLocation[] }>(`/locations/districts/${value.district}/wards`),
    enabled: Boolean(value.district),
    staleTime: 24 * 60 * 60 * 1000,
  });

  /*
   * Landmarks are scoped to the DISTRICT rather than the ward: `public_places`
   * records a district but not a ward, and a landmark two streets outside the
   * chosen ward is still the thing a customer is navigating by.
   */
  const landmarks = useQuery({
    queryKey: ["locations", "landmarks", value.district],
    queryFn: () =>
      apiGet<Paginated<ApiPlace>>("/public-places", { district: value.district, per_page: 100 }),
    enabled: Boolean(value.district),
    staleTime: 60 * 60 * 1000,
  });

  const toOptions = (rows: ApiLocation[] | undefined): ComboboxOption[] =>
    (rows ?? []).map((row) => ({
      value: row.slug,
      label: row.name,
      // Only where the number means something. "0" beside an area reads as
      // "nothing here" even when the area simply has no counter.
      badge:
        row.listing_count && row.listing_count > 0
          ? `${row.listing_count.toLocaleString()}`
          : undefined,
    }));

  const landmarkOptions: ComboboxOption[] = (landmarks.data?.data ?? []).map((place) => ({
    value: place.slug,
    label: place.name,
    hint: place.category?.name ?? undefined,
    icon: place.category?.icon ? <span>{place.category.icon}</span> : <Landmark className="h-4 w-4" />,
  }));

  /** Everything below the level being changed has to go — see each handler. */
  const setRegion = (slug: string, option: ComboboxOption | null) =>
    onChange({
      region: slug || null,
      district: null,
      ward: null,
      landmark: null,
      lat: null,
      lng: null,
      radius: null,
      loc: option?.label ?? null,
    });

  const setDistrict = (slug: string, option: ComboboxOption | null) =>
    onChange({
      district: slug || null,
      ward: null,
      landmark: null,
      // A district selection is administrative, so drop any radius the search
      // box or a landmark had set; otherwise the two filters compound and
      // silently narrow the results twice.
      lat: null,
      lng: null,
      radius: null,
      loc: option?.label ?? null,
    });

  const setWard = (slug: string, option: ComboboxOption | null) =>
    onChange({
      ward: slug || null,
      landmark: null,
      lat: null,
      lng: null,
      radius: null,
      loc: option?.label ?? null,
    });

  const setLandmark = (slug: string, option: ComboboxOption | null) => {
    const place = (landmarks.data?.data ?? []).find((row) => row.slug === slug);

    if (!place || place.location.latitude === null || place.location.longitude === null) {
      onChange({ landmark: null, lat: null, lng: null, radius: null });
      return;
    }

    onChange({
      landmark: slug,
      // A landmark is a POINT, not an area: it filters by radius. The ward
      // above stays applied, so "3 km around Mlimani City, in Ubungo" works.
      lat: place.location.latitude.toFixed(5),
      lng: place.location.longitude.toFixed(5),
      radius: "3",
      loc: option?.label ?? null,
    });
  };

  /** The quick-search shortcut fills whichever level of the cascade it matched. */
  const applySuggestion = (suggestion: LocationSuggestion) => {
    const patch: Record<string, string | null> = {
      region: null,
      district: null,
      ward: null,
      landmark: null,
      place: null,
      lat: null,
      lng: null,
      radius: null,
      loc: suggestion.label,
    };

    if (suggestion.filter) {
      patch[suggestion.filter.param] = suggestion.filter.value;
    }

    // A landmark or a business has no administrative filter of its own, so it
    // becomes a radius search — which is what "near there" means.
    if (suggestion.latitude !== null && suggestion.longitude !== null && !suggestion.filter) {
      patch.lat = suggestion.latitude.toFixed(5);
      patch.lng = suggestion.longitude.toFixed(5);
      patch.radius = String(suggestion.radius_km);
    }

    onChange(patch);
  };

  const clearAll = () =>
    onChange({
      region: null,
      district: null,
      ward: null,
      landmark: null,
      place: null,
      lat: null,
      lng: null,
      radius: null,
      loc: null,
    });

  return (
    <div className="space-y-4">
      <div>
        <p className="mb-1.5 text-sm font-medium text-navy">Search any place</p>
        <LocationAutocomplete
          value={value.label}
          onSelect={applySuggestion}
          onClear={clearAll}
          placeholder="Region, area, landmark or business"
        />
      </div>

      <div className="flex items-center gap-3" aria-hidden="true">
        <span className="h-px flex-1 bg-border" />
        <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          or narrow by area
        </span>
        <span className="h-px flex-1 bg-border" />
      </div>

      <Combobox
        label="Region"
        value={value.region}
        options={toOptions(regions.data?.data)}
        onChange={setRegion}
        loading={regions.isPending}
        placeholder="All regions"
        emptyText="No region by that name"
      />

      <Combobox
        label="District"
        value={value.district}
        options={toOptions(districts.data?.data)}
        onChange={setDistrict}
        disabled={!value.region}
        disabledHint="Pick a region first"
        loading={districts.isFetching}
        placeholder="All districts"
        emptyText="No district by that name"
      />

      <Combobox
        label="Ward"
        value={value.ward}
        options={toOptions(wards.data?.data)}
        onChange={setWard}
        disabled={!value.district}
        disabledHint="Pick a district first"
        loading={wards.isFetching}
        placeholder="All wards"
        emptyText="No ward by that name"
      />

      {/*
        Only offered once there is a district AND that district actually has
        landmarks. An empty fourth dropdown is a dead end that makes the whole
        cascade feel broken.
      */}
      {value.district && landmarkOptions.length > 0 && (
        <Combobox
          label="Near a landmark"
          value={value.landmark}
          options={landmarkOptions}
          onChange={setLandmark}
          loading={landmarks.isFetching}
          placeholder="Any landmark"
          emptyText="No landmark by that name"
        />
      )}

      {value.radius && (
        <p className="flex items-start gap-1.5 rounded-lg bg-teal/5 px-3 py-2 text-xs text-muted-foreground">
          <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal" />
          Showing listings within {Math.round(Number(value.radius))} km of{" "}
          <span className="font-semibold text-navy">{value.label || "this point"}</span>.
        </p>
      )}
    </div>
  );
}
