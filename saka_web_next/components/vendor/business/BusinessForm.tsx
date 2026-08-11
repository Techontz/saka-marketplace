"use client";

import { useQuery } from "@tanstack/react-query";
import { Check } from "lucide-react";
import { useState } from "react";

import { Button, Checkbox, Field, Input, Select, Textarea } from "@/components/vendor/ui";
import { apiGet } from "@/lib/vendor/api/browser";
import { cn } from "@/lib/vendor/cn";
import type { BusinessType, Envelope, Location, OpeningHours, VendorProfile } from "@/lib/vendor/api/types";

/**
 * The building blocks of the business profile, shared by onboarding and
 * settings.
 *
 * ONE set of components for both, deliberately. A wizard with its own copy of
 * every field drifts from the settings screen — different validation, different
 * labels, and a field that can be set in one place and not the other. The
 * wizard is a sequence of these; settings is all of them on one page.
 */

export type ProfileDraft = Partial<{
  display_name: string;
  business_name: string;
  business_type: string;
  bio: string;
  business_reg_no: string;
  tin: string;
  region_slug: string;
  district_slug: string;
  ward_slug: string;
  street: string;
  latitude: string;
  longitude: string;
  public_email: string;
  public_phone: string;
  whatsapp: string;
  website: string;
  opening_hours: OpeningHours;
  social_links: Record<string, string>;
}>;

export function draftFromProfile(profile: VendorProfile | null): ProfileDraft {
  if (!profile) return {};

  return {
    display_name: profile.display_name ?? "",
    business_name: profile.business_name ?? "",
    business_type: profile.business_type ?? "",
    bio: profile.bio ?? "",
    business_reg_no: profile.business_reg_no ?? "",
    tin: profile.tin ?? "",
    region_slug: profile.location.region_slug ?? "",
    district_slug: profile.location.district_slug ?? "",
    ward_slug: profile.location.ward_slug ?? "",
    street: profile.location.street ?? "",
    latitude: profile.location.latitude?.toString() ?? "",
    longitude: profile.location.longitude?.toString() ?? "",
    public_email: profile.contact.public_email ?? "",
    public_phone: profile.contact.public_phone ?? "",
    whatsapp: profile.contact.whatsapp ?? "",
    website: profile.contact.website ?? "",
    opening_hours: profile.opening_hours ?? {},
    social_links: profile.social_links ?? {},
  };
}

/**
 * Turns the draft into an API payload.
 *
 * Empty strings become null rather than being dropped, so clearing a field
 * actually clears it — a PATCH that omits the key would leave the old value in
 * place, which reads as "the save didn't work".
 */
export function payloadFromDraft(draft: ProfileDraft, keys: (keyof ProfileDraft)[]): Record<string, unknown> {
  const payload: Record<string, unknown> = {};

  for (const key of keys) {
    const value = draft[key];

    if (value === undefined) continue;

    if (key === "opening_hours" || key === "social_links") {
      payload[key] = value;
      continue;
    }

    if (key === "latitude" || key === "longitude") {
      payload[key] = value === "" ? null : Number(value);
      continue;
    }

    payload[key] = value === "" ? null : value;
  }

  /*
   * Coordinates travel together or not at all — the API rejects half a pin,
   * and a partial payload here would surface as a confusing 422 on a field the
   * vendor never touched.
   */
  const hasLat = "latitude" in payload;
  const hasLng = "longitude" in payload;

  if (hasLat !== hasLng) {
    delete payload.latitude;
    delete payload.longitude;
  }

  return payload;
}

// ------------------------------------------------------------------ step 1

export function BusinessStep({
  draft,
  onChange,
  types,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
  types: BusinessType[];
}) {
  const selected = types.find((type) => type.value === draft.business_type);

  return (
    <div className="space-y-5">
      <Field label="Business name" required>
        <Input
          value={draft.business_name ?? ""}
          onChange={(event) =>
            onChange({
              business_name: event.target.value,
              // The public display name follows the business name unless the
              // vendor has deliberately set it to something else.
              display_name:
                !draft.display_name || draft.display_name === draft.business_name
                  ? event.target.value
                  : draft.display_name,
            })
          }
          placeholder="e.g. Kilimani Pharmacy"
          autoFocus
        />
      </Field>

      <div>
        <p className="mb-1.5 text-[13px] font-medium text-ink">
          What kind of business is it? <span className="text-danger">*</span>
        </p>
        <p className="mb-3 text-xs text-ink-soft">
          This decides which categories you can post in, and what we ask you for next.
        </p>

        <div className="grid gap-2 sm:grid-cols-2">
          {types.map((type) => {
            const active = draft.business_type === type.value;

            return (
              <button
                key={type.value}
                type="button"
                onClick={() => onChange({ business_type: type.value })}
                aria-pressed={active}
                className={cn(
                  "flex items-start gap-2.5 rounded-[var(--radius-control)] border p-3 text-left transition-colors",
                  active
                    ? "border-brand bg-brand-soft"
                    : "border-line bg-surface hover:border-line-strong",
                )}
              >
                <span
                  className={cn(
                    "mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border",
                    active ? "border-brand bg-brand text-white" : "border-line-strong",
                  )}
                >
                  {active && <Check aria-hidden className="h-3 w-3" />}
                </span>
                <span className="min-w-0">
                  <span className="block text-sm font-medium text-ink">{type.label}</span>
                  <span className="block text-xs text-ink-soft">{type.description}</span>
                </span>
              </button>
            );
          })}
        </div>
      </div>

      <Field
        label="Description"
        hint="Shown on your public profile. What do you do, and where?"
      >
        <Textarea
          rows={4}
          value={draft.bio ?? ""}
          onChange={(event) => onChange({ bio: event.target.value })}
        />
      </Field>

      {/*
        Registration and tax numbers are only asked of trades expected to have
        them. Demanding a TIN from someone letting a spare room is how you lose
        the listing.
      */}
      {selected?.expects_registration_number && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Business registration number">
            <Input
              value={draft.business_reg_no ?? ""}
              onChange={(event) => onChange({ business_reg_no: event.target.value })}
            />
          </Field>
          <Field label="TIN">
            <Input value={draft.tin ?? ""} onChange={(event) => onChange({ tin: event.target.value })} />
          </Field>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ step 2

export function LocationStep({
  draft,
  onChange,
  businessType,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
  businessType: BusinessType | null;
}) {
  const regions = useQuery({
    queryKey: ["locations", "regions"],
    queryFn: () => apiGet<Envelope<Location[]>>("/locations/regions").then((r) => r.data),
  });

  // Slugs all the way down: the location endpoints publish slugs and are
  // addressed by them, and the profile is written with them too.
  const regionSlug = draft.region_slug ?? "";
  const districtSlug = draft.district_slug ?? "";

  const districts = useQuery({
    queryKey: ["locations", "districts", regionSlug],
    queryFn: () =>
      apiGet<Envelope<Location[]>>(`/locations/regions/${regionSlug}/districts`).then((r) => r.data),
    enabled: Boolean(regionSlug),
  });

  const wards = useQuery({
    queryKey: ["locations", "wards", districtSlug],
    queryFn: () =>
      apiGet<Envelope<Location[]>>(`/locations/districts/${districtSlug}/wards`).then((r) => r.data),
    enabled: Boolean(districtSlug),
  });

  const needsStreet = businessType?.has_walk_in_address ?? true;

  return (
    <div className="space-y-5">
      <Field label="Country">
        {/* One market today. A select with one option is honest about that
            without pretending the field does not exist. */}
        <Select value="TZ" disabled>
          <option value="TZ">Tanzania</option>
        </Select>
      </Field>

      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Region" required>
          <Select
            value={regionSlug}
            onChange={(event) =>
              // Changing a level clears the ones below it, or the API rejects
              // the mismatch — a district must belong to its region.
              onChange({ region_slug: event.target.value, district_slug: "", ward_slug: "" })
            }
          >
            <option value="">Choose…</option>
            {(regions.data ?? []).map((region) => (
              <option key={region.slug} value={region.slug}>
                {region.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="District">
          <Select
            value={districtSlug}
            disabled={!regionSlug || districts.isPending}
            onChange={(event) => onChange({ district_slug: event.target.value, ward_slug: "" })}
          >
            <option value="">{regionSlug ? "Choose…" : "Pick a region first"}</option>
            {(districts.data ?? []).map((district) => (
              <option key={district.slug} value={district.slug}>
                {district.name}
              </option>
            ))}
          </Select>
        </Field>

        <Field label="Ward">
          <Select
            value={draft.ward_slug ?? ""}
            disabled={!districtSlug || wards.isPending}
            onChange={(event) => onChange({ ward_slug: event.target.value })}
          >
            <option value="">{districtSlug ? "Choose…" : "Pick a district first"}</option>
            {(wards.data ?? []).map((ward) => (
              <option key={ward.slug} value={ward.slug}>
                {ward.name}
              </option>
            ))}
          </Select>
        </Field>
      </div>

      <Field
        label="Street address"
        required={needsStreet}
        hint={
          needsStreet
            ? "Where customers find you."
            : "Optional — you work at your customers' locations, so this is only shown if you fill it in."
        }
      >
        <Input
          value={draft.street ?? ""}
          onChange={(event) => onChange({ street: event.target.value })}
        />
      </Field>

      <MapPin draft={draft} onChange={onChange} />
    </div>
  );
}

/**
 * The map pin.
 *
 * An embedded interactive map is deliberately NOT here: it needs a Google Maps
 * key, which is a per-deployment setting an administrator has to provide, and a
 * map component that silently renders grey because the key is missing is worse
 * than no map. This captures the same data — a precise pin — using the
 * browser's own geolocation, plus manual entry, and links out to a real map for
 * anyone who wants to drag a marker.
 */
function MapPin({
  draft,
  onChange,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
}) {
  const [locating, setLocating] = useState(false);
  const [geoError, setGeoError] = useState<string | null>(null);

  const hasPin = Boolean(draft.latitude && draft.longitude);

  const useMyLocation = () => {
    if (!navigator.geolocation) {
      setGeoError("This browser cannot share a location.");
      return;
    }

    setLocating(true);
    setGeoError(null);

    navigator.geolocation.getCurrentPosition(
      (position) => {
        onChange({
          latitude: position.coords.latitude.toFixed(7),
          longitude: position.coords.longitude.toFixed(7),
        });
        setLocating(false);
      },
      () => {
        setGeoError("We couldn't get your location. Enter the coordinates below instead.");
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };

  return (
    <div className="rounded-[var(--radius-card)] border border-line p-4">
      <p className="text-[13px] font-medium text-ink">Map pin</p>
      <p className="mt-0.5 mb-3 text-xs text-ink-soft">
        Puts your business on the map and powers &quot;near me&quot; search.
      </p>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="secondary" size="sm" loading={locating} onClick={useMyLocation}>
          Use my current location
        </Button>

        {hasPin && (
          <a
            href={`https://www.google.com/maps/search/?api=1&query=${draft.latitude},${draft.longitude}`}
            target="_blank"
            rel="noopener noreferrer"
            className="text-xs font-medium text-brand hover:underline"
          >
            Check this pin on a map
          </a>
        )}
      </div>

      {geoError && <p className="mt-2 text-xs text-danger">{geoError}</p>}

      <div className="mt-3 grid gap-3 sm:grid-cols-2">
        <Field label="Latitude">
          <Input
            value={draft.latitude ?? ""}
            onChange={(event) => onChange({ latitude: event.target.value })}
            placeholder="-6.7924"
            inputMode="decimal"
          />
        </Field>
        <Field label="Longitude">
          <Input
            value={draft.longitude ?? ""}
            onChange={(event) => onChange({ longitude: event.target.value })}
            placeholder="39.2083"
            inputMode="decimal"
          />
        </Field>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ step 3

export function ContactStep({
  draft,
  onChange,
  accountPhone,
  accountEmail,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
  accountPhone: string | null;
  accountEmail: string | null;
}) {
  return (
    <div className="space-y-5">
      <Field
        label="Public phone"
        hint={
          accountPhone
            ? `Leave empty to use your account number, ${accountPhone}.`
            : "The number customers should call."
        }
      >
        <Input
          type="tel"
          value={draft.public_phone ?? ""}
          onChange={(event) => onChange({ public_phone: event.target.value })}
          placeholder={accountPhone ?? "+255…"}
        />
      </Field>

      <Field
        label="Public email"
        hint={accountEmail ? `Leave empty to use ${accountEmail}.` : undefined}
      >
        <Input
          type="email"
          value={draft.public_email ?? ""}
          onChange={(event) => onChange({ public_email: event.target.value })}
          placeholder={accountEmail ?? undefined}
        />
      </Field>

      <Field label="WhatsApp" hint="Customers message this number directly from your listings.">
        <Input
          type="tel"
          value={draft.whatsapp ?? ""}
          onChange={(event) => onChange({ whatsapp: event.target.value })}
          placeholder="+255…"
        />
      </Field>

      <Field label="Website" hint="Must start with http:// or https://.">
        <Input
          type="url"
          value={draft.website ?? ""}
          onChange={(event) => onChange({ website: event.target.value })}
          placeholder="https://"
        />
      </Field>
    </div>
  );
}

// ------------------------------------------------------------------ step 5

const DAYS: { key: string; label: string }[] = [
  { key: "mon", label: "Monday" },
  { key: "tue", label: "Tuesday" },
  { key: "wed", label: "Wednesday" },
  { key: "thu", label: "Thursday" },
  { key: "fri", label: "Friday" },
  { key: "sat", label: "Saturday" },
  { key: "sun", label: "Sunday" },
];

/**
 * Opening hours.
 *
 * Supports split shifts because real businesses close for lunch, and a closed
 * day is an EMPTY list rather than a missing key — the API distinguishes "shut
 * on Sunday" from "never told us about Sunday", and so does this.
 */
export function HoursStep({
  draft,
  onChange,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
}) {
  const hours = draft.opening_hours ?? {};

  const setDay = (day: string, ranges: { open: string; close: string }[]) => {
    onChange({ opening_hours: { ...hours, [day]: ranges } });
  };

  const applyToAll = () => {
    const monday = hours.mon ?? [];
    const next: OpeningHours = {};
    for (const day of DAYS) next[day.key] = day.key === "sun" ? [] : [...monday];
    onChange({ opening_hours: next });
  };

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <Button type="button" variant="ghost" size="sm" onClick={applyToAll}>
          Copy Monday to Mon–Sat
        </Button>
      </div>

      {DAYS.map((day) => {
        const ranges = hours[day.key] ?? [];
        const configured = day.key in hours;
        const closed = configured && ranges.length === 0;

        return (
          <div key={day.key} className="rounded-[var(--radius-control)] border border-line p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm font-medium text-ink">{day.label}</span>

              <div className="flex items-center gap-3">
                <Checkbox
                  label="Closed"
                  checked={closed}
                  onChange={(event) => setDay(day.key, event.target.checked ? [] : [{ open: "09:00", close: "17:00" }])}
                />
                {!closed && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setDay(day.key, [...ranges, { open: "14:00", close: "18:00" }])}
                  >
                    Add hours
                  </Button>
                )}
              </div>
            </div>

            {!closed && ranges.length === 0 && (
              <p className="mt-2 text-xs text-ink-faint">
                Not set. Tick &quot;Closed&quot;, or add opening hours.
              </p>
            )}

            {ranges.map((range, index) => (
              <div key={index} className="mt-2 flex flex-wrap items-center gap-2">
                <Input
                  type="time"
                  aria-label={`${day.label} opening time ${index + 1}`}
                  value={range.open}
                  className="w-32"
                  onChange={(event) =>
                    setDay(
                      day.key,
                      ranges.map((r, i) => (i === index ? { ...r, open: event.target.value } : r)),
                    )
                  }
                />
                <span className="text-sm text-ink-faint">to</span>
                <Input
                  type="time"
                  aria-label={`${day.label} closing time ${index + 1}`}
                  value={range.close}
                  className="w-32"
                  onChange={(event) =>
                    setDay(
                      day.key,
                      ranges.map((r, i) => (i === index ? { ...r, close: event.target.value } : r)),
                    )
                  }
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setDay(day.key, ranges.filter((_, i) => i !== index))}
                >
                  Remove
                </Button>
              </div>
            ))}
          </div>
        );
      })}
    </div>
  );
}

/**
 * Social links.
 *
 * A fixed set of networks, because each one is a rendered icon on the public
 * profile — an open-ended list would grow blank squares as vendors invented
 * networks. Mirrors `SocialNetwork` in the API.
 */
const NETWORKS: { key: string; label: string; placeholder: string }[] = [
  { key: "facebook", label: "Facebook", placeholder: "facebook.com/yourbusiness" },
  { key: "instagram", label: "Instagram", placeholder: "@yourbusiness" },
  { key: "x", label: "X", placeholder: "@yourbusiness" },
  { key: "linkedin", label: "LinkedIn", placeholder: "linkedin.com/in/yourbusiness" },
  { key: "tiktok", label: "TikTok", placeholder: "@yourbusiness" },
  { key: "youtube", label: "YouTube", placeholder: "youtube.com/@yourbusiness" },
];

/**
 * Whether what has been typed will survive the server's normalisation.
 *
 * A DELIBERATELY loose mirror of `SocialNetwork::normalise` — it exists to warn,
 * not to decide. The server is the authority and re-checks the host; duplicating
 * the full host table here would give two sources of truth that drift the first
 * time a network adds a domain. What this catches is the one mistake worth
 * catching before save: a link pasted into the wrong network's box.
 */
function looksWrong(network: string, value: string): boolean {
  const trimmed = value.trim();

  // Empty is fine — it means "remove this one".
  if (trimmed === "") return false;

  // A handle, with or without the @. Always plausible.
  if (!trimmed.includes("/") && !trimmed.includes(".")) return false;
  if (trimmed.startsWith("@")) return false;

  const host = trimmed
    .replace(/^https?:\/\//i, "")
    .replace(/^www\./i, "")
    .split("/")[0]
    .toLowerCase();

  // The primary domain per network. Regional and legacy variants are accepted
  // by the server and simply do not trigger the warning here.
  const expected: Record<string, string[]> = {
    facebook: ["facebook.com", "fb.com"],
    instagram: ["instagram.com", "instagr.am"],
    x: ["x.com", "twitter.com"],
    linkedin: ["linkedin.com"],
    tiktok: ["tiktok.com"],
    youtube: ["youtube.com", "youtu.be"],
  };

  const allowed = expected[network] ?? [];

  return allowed.length > 0 && !allowed.some((domain) => host === domain || host.endsWith("." + domain));
}

export function SocialStep({
  draft,
  onChange,
}: {
  draft: ProfileDraft;
  onChange: (patch: ProfileDraft) => void;
}) {
  const links = draft.social_links ?? {};

  return (
    <div className="space-y-4">
      <p className="text-sm text-ink-soft">
        Paste a link or just your username. Only the ones you fill in are shown on your public
        profile.
      </p>

      <div className="grid gap-4 sm:grid-cols-2">
        {NETWORKS.map((network) => {
          const value = links[network.key] ?? "";
          const wrong = looksWrong(network.key, value);

          return (
            <Field
              key={network.key}
              label={network.label}
              error={wrong ? `That does not look like a ${network.label} link.` : undefined}
            >
              <Input
                /*
                 * `type="text"`, not `type="url"`.
                 *
                 * The server accepts a bare handle and a scheme-less host, both
                 * of which a url input rejects with a browser-native message
                 * the form cannot style or explain — so the control was refusing
                 * exactly the two things a vendor is most likely to type.
                 */
                type="text"
                inputMode="url"
                autoComplete="off"
                value={value}
                placeholder={network.placeholder}
                onChange={(event) =>
                  onChange({ social_links: { ...links, [network.key]: event.target.value } })
                }
              />
            </Field>
          );
        })}
      </div>
    </div>
  );
}

/** Loads the business types once, for any screen that needs them. */
export function useBusinessTypes() {
  return useQuery({
    queryKey: ["business-types"],
    queryFn: () => apiGet<Envelope<BusinessType[]>>("/business-types").then((r) => r.data),
    // Static reference data.
    staleTime: 60 * 60 * 1000,
  });
}

/** Keeps a local draft in step with a profile that loads asynchronously. */
export function useProfileDraft(profile: VendorProfile | null) {
  const [draft, setDraft] = useState<ProfileDraft>(() => draftFromProfile(profile));
  const [hydrated, setHydrated] = useState(profile !== null);

  // Seeded during render, not in an effect: an effect would paint an empty form
  // first and replace it a frame later. Only ONCE — re-seeding on every refetch
  // would discard whatever the vendor is currently typing.
  if (profile && !hydrated) {
    setHydrated(true);
    setDraft(draftFromProfile(profile));
  }

  const update = (patch: ProfileDraft) => setDraft((current) => ({ ...current, ...patch }));

  return { draft, update, setDraft, hydrated };
}
