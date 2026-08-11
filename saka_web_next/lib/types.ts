/** The shapes the SAKA API actually returns, as consumed by this app. */

export type Envelope<T> = { data: T; meta?: Record<string, unknown> };

export type Paginated<T> = {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
};

export type ApiMedia = {
  uuid: string;
  url: string;
  /**
   * Resized renditions, keyed thumb/card/detail/full.
   *
   * The array arm is not a mistake: these are produced by a queued job, and
   * until it runs `MediaResource` serialises an empty PHP array — which is
   * `[]`, not `{}`. See `lib/media.ts`, which is the only thing that should be
   * reading this shape.
   */
  variants?: Record<string, { url: string; width?: number | null; height?: number | null }> | unknown[];
  alt_text: string | null;
  width?: number | null;
  height?: number | null;
  is_primary?: boolean;
  processing_status?: string;
};

export type ApiCategoryRef = {
  slug: string;
  name: string;
  icon: string | null;
  parent?: { slug: string; name: string; icon: string | null } | null;
};

export type ApiListing = {
  uuid: string;
  slug: string;
  title: string;
  description?: string | null;
  price: { amount: number; currency: string; unit: string; is_negotiable: boolean } | null;
  purpose: string | null;
  condition: string | null;
  status: string;
  is_verified: boolean;
  is_featured: boolean;
  category?: ApiCategoryRef | null;
  location: {
    region?: string | null;
    region_slug?: string | null;
    district?: string | null;
    district_slug?: string | null;
    ward?: string | null;
    ward_slug?: string | null;
    address_line: string | null;
    latitude: number | null;
    longitude: number | null;
  };
  primary_image?: ApiMedia | null;
  images?: ApiMedia[];
  stats: { views: number; favorites: number; inquiries: number };
  attributes: Record<string, string | number | boolean | null> | ApiListingAttribute[];
  amenities?: { slug: string; name: string; icon: string | null }[];
  facilities?: { slug: string; name: string; icon: string | null }[];
  seller?: ApiSellerSummary | null;
  distance_km?: number;
  available_from?: string | null;
  published_at?: string | null;
  created_at?: string | null;
  /** Land parcels only — null on every other vertical. Detail responses only. */
  boundary?: ApiBoundary | null;
  /** Whether this category COULD carry a boundary, for the right empty state. */
  supports_boundary?: boolean;
};

/**
 * A surveyed land parcel, as `ListingBoundaryResource` returns it.
 *
 * `rings` is GeoJSON order — [longitude, latitude] — with ring 0 the outer edge
 * and any further rings holes. The measurements are computed SERVER-SIDE from
 * those coordinates and are not editable by the seller, which is what makes the
 * advertised acreage worth reading.
 */
export type ApiBoundary = {
  rings: [number, number][][];
  area: { sqm: number; acres: number; hectares: number; display: string };
  perimeter_m: number;
  perimeter_display: string;
  vertex_count: number;
  centroid: { latitude: number; longitude: number } | null;
  bounds: {
    min_latitude: number;
    max_latitude: number;
    min_longitude: number;
    max_longitude: number;
  } | null;
  survey_reference: string | null;
  notes: string | null;
  geojson: unknown;
  updated_at: string | null;
};

export type ApiListingAttribute = {
  code: string;
  name: string;
  unit: string | null;
  value: string | number | boolean | null;
  label: string | null;
};

export type ApiSellerSummary = {
  uuid?: string;
  slug?: string | null;
  display_name?: string | null;
  is_verified?: boolean;
  rating_avg?: number | null;
  rating_count?: number;
  phone?: string | null;
  whatsapp?: string | null;
  logo_url?: string | null;
  member_since?: string | null;
};

export type ApiBusiness = {
  slug: string;
  display_name: string;
  business_type: string | null;
  business_type_label: string | null;
  logo_url?: string | null;
  cover_url?: string | null;
  location: {
    region?: string | null;
    region_slug?: string | null;
    district?: string | null;
    district_slug?: string | null;
    ward?: string | null;
    street?: string | null;
    latitude: number | null;
    longitude: number | null;
  };
  rating: { average: number | null; count: number };
  listing_count: number;
  is_verified: boolean;
  distance_km?: number;
  bio?: string | null;
  contact?: { phone: string | null; email: string | null; whatsapp: string | null; website: string | null };
  opening_hours?: Record<string, { open: string; close: string }[]> | null;
  social_links?: Record<string, string> | null;
  verification?: { is_verified: boolean; level: string; verified_at: string | null };
  stats?: {
    active_listings: number;
    total_listings: number;
    response_rate_pct: number | null;
    response_time_minutes: number | null;
  };
  member_since?: string | null;
};

export type ApiCategory = {
  slug: string;
  name: string;
  icon: string | null;
  description: string | null;
  depth: number;
  is_leaf: boolean;
  listing_count: number;
  image_url?: string | null;
  children?: ApiCategory[];
};

/**
 * A public place.
 *
 * The geography is NESTED under `location`, matching `PublicPlaceResource`.
 * An earlier version of this type declared `latitude`, `district` and
 * `distance_km` at the top level; because every one of them was optional it
 * still typechecked, and every read silently returned `undefined` — which is
 * why the places map rendered no pins and every address came out blank.
 * Nothing here is optional that the API always sends.
 */
/**
 * One attribute a category defines, exactly as `/categories/{slug}/attributes`
 * returns it.
 *
 * This is what makes the marketplace multi-vertical: the FRONTEND holds no
 * knowledge of bedrooms or mileage. It reads the definitions for whichever
 * category is selected and renders a control per `input_type`, so adding
 * "Year Built" to Property in the admin portal makes a filter appear here with
 * no deploy.
 */
export type ApiAttribute = {
  code: string;
  name: string;
  input_type: "text" | "number" | "select" | "boolean" | string;
  data_type: "string" | "integer" | "decimal" | "boolean" | string;
  unit: string | null;
  is_filterable: boolean;
  is_required: boolean;
  min_value: number | null;
  max_value: number | null;
  /** Present for `select`; each entry is a raw value or {value,label}. */
  options: (string | { value: string; label?: string })[];
};

export type ApiPlace = {
  slug: string;
  name: string;
  description: string | null;
  image_url: string | null;
  category: { slug: string; name: string; icon: string | null } | null;
  location: {
    region: string | null;
    district: string | null;
    address_line: string | null;
    latitude: number | null;
    longitude: number | null;
    /** Only present when the request carried lat/lng/radius. */
    distance_km?: number;
  };
  phone: string | null;
  website: string | null;
  opening_hours: Record<string, { open: string; close: string }[]> | null;
};

export type ApiReview = {
  uuid: string;
  rating: number;
  title: string | null;
  body: string | null;
  status: string;
  helpful_count: number;
  reply: { body: string; replied_at: string | null } | null;
  reviewer: { uuid: string; name: string } | null;
  listing?: { slug: string; title: string } | null;
  created_at: string | null;
};

export type ApiInquiry = {
  uuid: string;
  first_name: string | null;
  last_name: string | null;
  email: string;
  phone: string | null;
  message: string;
  source: string;
  status: string;
  reply: { body: string; replied_at: string | null } | null;
  listing?: { uuid?: string; slug: string; title: string } | null;
  read_at: string | null;
  created_at: string | null;
};

export type ApiNotification = {
  id: string;
  type: string;
  data: {
    title?: string;
    body?: string;
    url?: string;
    listing_slug?: string;
    listing_title?: string;
    inquiry_uuid?: string;
    review_uuid?: string;
  };
  read: boolean;
  read_at: string | null;
  created_at: string;
};

export type SessionUser = {
  uuid: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  email: string;
  phone: string | null;
  status: string;
  roles: string[];
  email_verified: boolean;
  phone_verified: boolean;
  can_publish_listings: boolean;
  avatar_url?: string | null;
};

export type ApiLocation = { slug: string; name: string; latitude?: number | null; longitude?: number | null; listing_count?: number };

/**
 * Where an advertisement can appear.
 *
 * Mirrors `App\Domain\Advertising\Enums\AdPlacement`. A union rather than a
 * free string so a typo is a compile error at the call site instead of a slot
 * that silently renders nothing on a page nobody re-checks.
 */
export type AdPlacement =
  | "homepage_hero"
  | "homepage_strip"
  | "listings_top"
  | "listings_inline"
  | "businesses"
  | "specialists"
  | "category_page"
  | "footer";

/**
 * One advertisement, as the public API returns it.
 *
 * Deliberately thin: the campaign, its priority, its cap and its delivery
 * numbers are not here and must not be added. See `AdCreativeResource` — a
 * competitor could otherwise read a rival's spend straight off the marketplace.
 */
export type ApiAdCreative = {
  uuid: string;
  headline: string;
  body: string | null;
  cta_label: string | null;
  click_url: string;
  alt_text: string;
  image?: ApiMedia | null;
  mobile_image?: ApiMedia | null;
  advertiser?: { name: string } | null;
};

/** The placement descriptor travelling alongside a serve response. */
export type ApiAdPlacementMeta = {
  value: AdPlacement;
  label: string;
  description: string;
  aspect_ratio: { desktop: number; mobile: number };
  max_concurrent: number;
  expects_category_targeting: boolean;
};

// ---------------------------------------------------------------- specialists

/** How a service is delivered. Mirrors `ServiceMode` in the API. */
export type ServiceMode = "online" | "in_person" | "both";

export type ApiSpecialistService = {
  uuid: string;
  name: string;
  description: string | null;
  duration_minutes: number;
  mode: ServiceMode;
  mode_label: string;
  is_active: boolean;
  /**
   * Null means "price on enquiry", NOT free.
   *
   * A common way for professionals to sell, so the shape distinguishes it —
   * rendering `0` here would tell customers a barrister works for nothing.
   */
  price: { amount: number; currency: string } | null;
};

/** One day of the booking calendar, as the slots endpoint returns it. */
export type ApiSlotDay = {
  date: string;
  slots: { start: string; end: string }[];
};

export type ApiBooking = {
  uuid: string;
  scheduled_date: string;
  start_time: string;
  end_time: string;
  timezone: string;
  starts_at_utc: string;
  status: string;
  status_label: string;
  is_cancellable: boolean;
  awaits_specialist: boolean;
  service?: { uuid: string; name: string; duration_minutes: number; mode: ServiceMode } | null;
  specialist?: { slug: string; title: string } | null;
  customer_note: string | null;
  specialist_note: string | null;
  created_at: string | null;
};
