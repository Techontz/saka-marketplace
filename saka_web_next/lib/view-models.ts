import type { ApiListing, ApiListingAttribute, ApiMedia, ApiPlace } from "./types";

/**
 * The bridge between the API's shape and the design's.
 *
 * The marketplace UI was built against a flat listing object — `beds`,
 * `sqft`, `location` as one string, `purpose` capitalised for a badge colour.
 * The API returns something richer and differently shaped: a price object, a
 * category with a parent, a location split into region/district/ward, and
 * attributes that are a map on a summary and an array of descriptors on a
 * detail.
 *
 * Mapping in ONE place is what keeps the rebuilt design identical to the
 * original while the components stay unaware of the API. Nothing here invents
 * data: a listing with no bedrooms attribute gets `beds: null`, and the card
 * omits the row rather than printing "0 Beds".
 */

export type ListingView = {
  slug: string;
  uuid: string;
  title: string;
  location: string;
  image: string | null;
  /**
   * The media row behind `image`, when the listing has one.
   *
   * `image` stays a plain URL because a placeholder has no media row and every
   * consumer needs SOMETHING to render. This carries the renditions alongside
   * it so a card can hand `lib/media.ts` a srcset instead of downloading the
   * 1600px original into a 435px box. Null for the placeholder.
   */
  imageMedia: ApiMedia | null;
  imageAlt: string;
  category: string;
  subcategory: string;
  /**
   * Null when the listing has none.
   *
   * It used to default to "Sale", which was harmless while property was the
   * only vertical and is not now: every job vacancy and every service booking
   * came out of the mapper labelled "Sale", so a card for a plumber's call-out
   * rate carried a green "Sale" badge. A missing purpose renders no badge.
   */
  purpose: "Rent" | "Sale" | "Lease" | "Hire" | null;
  price: number | null;
  priceUnit: string | null;
  currency: string;
  isNegotiable: boolean;
  beds: number | null;
  bathrooms: number | null;
  sqft: number | null;
  /*
   * Optional in the catalog and absent from most listings, which is why each is
   * nullable and every consumer omits its row rather than printing a zero. The
   * original design rendered all three, so dropping them would lose a row the
   * API can genuinely fill.
   */
  balconies: number | null;
  doors: number | null;
  parkings: number | null;
  verified: boolean;
  featured: boolean;
  latitude: number | null;
  longitude: number | null;
  distanceKm: number | null;
  views: number;
};

const PLACEHOLDER_IMAGE = null;

/** "Masaki, Kinondoni, Dar es Salaam" — the most specific parts, in order. */
export function formatLocation(location: ApiListing["location"]): string {
  const parts = [
    location.address_line || location.ward,
    location.district,
    location.region,
  ].filter((part): part is string => Boolean(part));

  // De-duplicate: an address line of "Masaki" beside a ward of "Masaki" reads
  // as a mistake.
  return [...new Set(parts)].join(", ");
}

/**
 * A place's address, most specific part first.
 *
 * Places carry far less geography than listings — no ward, and often only a
 * region — so this returns null rather than an empty string when there is
 * nothing to show, letting callers pick their own fallback copy.
 */
export function formatPlaceAddress(place: ApiPlace): string | null {
  const parts = [place.location.address_line, place.location.district, place.location.region].filter(
    (part): part is string => Boolean(part),
  );

  return parts.length > 0 ? [...new Set(parts)].join(", ") : null;
}

/** Attributes arrive as a map on a summary and as descriptors on a detail. */
export function attributeMap(
  attributes: ApiListing["attributes"],
): Record<string, string | number | boolean | null> {
  if (Array.isArray(attributes)) {
    return Object.fromEntries(
      (attributes as ApiListingAttribute[]).map((attribute) => [attribute.code, attribute.value]),
    );
  }

  return attributes ?? {};
}

function numeric(value: string | number | boolean | null | undefined): number | null {
  if (value === null || value === undefined || value === "" || typeof value === "boolean") return null;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

const PURPOSES: Record<string, NonNullable<ListingView["purpose"]>> = {
  rent: "Rent",
  sale: "Sale",
  lease: "Lease",
  hire: "Hire",
};

/** "For sale", "to lease" — the phrase, not the badge. */
export function purposePhrase(purpose: ListingView["purpose"]): string | null {
  if (purpose === null) return null;

  return purpose === "Lease" ? "To lease" : `For ${purpose.toLowerCase()}`;
}

export function toListingView(listing: ApiListing): ListingView {
  const attributes = attributeMap(listing.attributes);

  return {
    slug: listing.slug,
    uuid: listing.uuid,
    title: listing.title,
    location: formatLocation(listing.location),
    image: listing.primary_image?.url ?? listing.images?.[0]?.url ?? PLACEHOLDER_IMAGE,
    imageMedia: listing.primary_image ?? listing.images?.[0] ?? null,
    imageAlt: listing.primary_image?.alt_text ?? listing.title,
    // The design shows the vertical above the leaf: "Property" / "Apartments".
    category: listing.category?.parent?.name ?? listing.category?.name ?? "",
    subcategory: listing.category?.name ?? "",
    purpose: listing.purpose ? (PURPOSES[listing.purpose] ?? null) : null,
    price: listing.price?.amount ?? null,
    priceUnit: listing.price?.unit && listing.price.unit !== "total" ? listing.price.unit : null,
    currency: listing.price?.currency ?? "TZS",
    isNegotiable: listing.price?.is_negotiable ?? false,
    beds: numeric(attributes.beds ?? attributes.bedrooms),
    bathrooms: numeric(attributes.bathrooms),
    sqft: numeric(attributes.sqft ?? attributes.area),
    balconies: numeric(attributes.balconies),
    doors: numeric(attributes.doors),
    parkings: numeric(attributes.parkings ?? attributes.parking),
    verified: Boolean(listing.is_verified),
    featured: Boolean(listing.is_featured),
    latitude: listing.location.latitude,
    longitude: listing.location.longitude,
    distanceKm: listing.distance_km ?? null,
    views: listing.stats?.views ?? 0,
  };
}

export function formatPrice(view: Pick<ListingView, "price" | "currency" | "priceUnit">): string {
  if (view.price === null) return "Price on request";

  const amount = `${view.currency} ${view.price.toLocaleString()}`;
  return view.priceUnit ? `${amount} / ${view.priceUnit}` : amount;
}

/** Distances below a kilometre read better in metres. */
export function formatDistance(km: number | null): string | null {
  if (km === null) return null;
  return km < 1 ? `${Math.round(km * 1000)} m away` : `${km.toFixed(1)} km away`;
}
