/** Wire types for the vendor API. Naming follows the wire exactly (snake_case). */

export type Envelope<T> = { data: T };
export type WithMeta<T, M> = { data: T; meta: M };

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
};

export type Paginated<T> = { data: T[]; meta: PaginationMeta };

export type DailyPoint = { date: string; value: number };

// ------------------------------------------------------------------ identity

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
  /** Publishing is gated on a verified phone. */
  can_publish_listings: boolean;
  permissions?: string[];
};

// -------------------------------------------------------------- business type

/**
 * The per-vertical rule set, straight from the API.
 *
 * Every conditional field, every noun and every category pre-filter in this
 * portal reads from here rather than from a local table — the rules live in the
 * enum on the server, and a second copy would drift.
 */
export type BusinessType = {
  value: string;
  label: string;
  description: string;
  category_slugs: string[];
  has_opening_hours: boolean;
  has_walk_in_address: boolean;
  expects_registration_number: boolean;
  listing_noun: { singular: string; plural: string };
};

// ------------------------------------------------------------------- profile

export type OpeningRange = { open: string; close: string };
export type OpeningHours = Record<string, OpeningRange[]>;

export type VendorProfile = {
  slug: string;
  display_name: string;
  business_name: string | null;
  business_type: string | null;
  bio: string | null;
  business_reg_no: string | null;
  tin: string | null;
  location: {
    country_code: string;
    region_id: number | null;
    region_slug: string | null;
    region: string | null;
    district_id: number | null;
    district_slug: string | null;
    district: string | null;
    ward_id: number | null;
    ward_slug: string | null;
    ward: string | null;
    street: string | null;
    latitude: number | null;
    longitude: number | null;
  };
  contact: {
    public_email: string | null;
    public_phone: string | null;
    whatsapp: string | null;
    website: string | null;
  };
  branding: { logo_url?: string | null; cover_url?: string | null };
  opening_hours: OpeningHours | null;
  social_links: Record<string, string> | null;
  verification: { is_verified: boolean; level: string; verified_at: string | null };
  stats: {
    active_listings: number;
    total_listings: number;
    rating_avg: number | null;
    rating_count: number;
    response_rate_pct: number | null;
  };
  onboarding_completed_at: string | null;
};

export type OnboardingStep = {
  complete: boolean;
  required: boolean;
  applicable?: boolean;
  missing: string[];
};

export type OnboardingProgress = {
  steps: Record<string, OnboardingStep>;
  completed_steps: number;
  total_steps: number;
  percentage: number;
  next_step: string | null;
  is_complete: boolean;
  onboarding_completed_at: string | null;
};

export type ProfileMeta = { progress: OnboardingProgress; business_type: BusinessType | null };

// ------------------------------------------------------------------ listings

export type ListingSummary = {
  uuid: string;
  slug: string;
  title: string;
  price: { amount: number; currency: string; unit: string | null } | null;
  purpose: string | null;
  status: string;
  is_verified: boolean;
  is_featured: boolean;
  category?: { slug: string; name: string; parent: { slug: string; name: string } | null };
  location: {
    region?: string | null;
    region_slug?: string | null;
    district?: string | null;
    district_slug?: string | null;
    ward_slug?: string | null;
    address_line: string | null;
  };
  primary_image?: { url: string } | null;
  stats: { views: number; favorites: number; inquiries: number };
  /**
   * On a SUMMARY this is the flat `code => value` projection.
   *
   * The DETAIL response spells the same data differently — see
   * `ListingDetail.attributes`. Two shapes under one key is the API's contract,
   * not a choice made here.
   */
  attributes: Record<string, string | number | boolean | null>;
  created_at: string | null;
};

export type ListingMedia = {
  uuid: string;
  url: string;
  variants?: Record<string, { url: string }>;
  alt_text: string | null;
  position: number;
  is_primary: boolean;
  processing_status: string;
};

/** One attribute as the DETAIL endpoint returns it: described, not just valued. */
export type ListingAttribute = {
  code: string;
  name: string;
  unit: string | null;
  value: string | number | boolean | null;
  /** Set for select-type attributes: the human label of the chosen option. */
  label: string | null;
};

export type ListingDetail = Omit<ListingSummary, "attributes"> & {
  attributes: ListingAttribute[];
  description: string | null;
  available_from: string | null;
  expires_at: string | null;
  images: ListingMedia[];
  amenities: { slug: string; name: string }[];
  facilities: { slug: string; name: string }[];
  rejection_reason?: string | null;
  /**
   * Whether this listing's category can carry a surveyed land boundary.
   *
   * Decided by the API from config, not by the portal from a slug — see
   * `saka.listings.boundary_categories`. Optional because an older API build
   * does not send it, and the Boundary tab simply does not appear then.
   */
  supports_boundary?: boolean;
};

// ------------------------------------------------------------------ catalog

export type Category = {
  slug: string;
  name: string;
  icon: string | null;
  is_leaf: boolean;
  listing_count: number;
  children?: Category[];
};

export type Attribute = {
  code: string;
  name: string;
  input_type: string;
  data_type: string;
  unit: string | null;
  is_required?: boolean;
  options?: { value: string; label: string }[];
};

export type Location = { slug: string; name: string; id?: number };

export type TaxonomyTerm = { slug: string; name: string; icon: string | null };

// --------------------------------------------------------------- dashboard

/**
 * `GET /seller/dashboard`.
 *
 * These names are the API's, verified against a live response. An earlier
 * version of this type invented `listings.published` and `engagement.views`
 * — the API calls them `listings.active` and `engagement.total_views` — and
 * because the type asserted they existed, TypeScript could not catch it and
 * the dashboard crashed on `undefined.toLocaleString()`.
 *
 * Every counter is optional here on purpose: the UI formats defensively, so a
 * field the API stops sending degrades to a dash instead of a white screen.
 */
export type VendorDashboard = {
  listings: {
    total?: number;
    /** Published and live. The API's name for what the UI labels "Live". */
    active?: number;
    draft?: number;
    pending?: number;
    rejected?: number;
    paused?: number;
    expired?: number;
    sold?: number;
    archived?: number;
    by_status?: Record<string, number>;
  };
  engagement: {
    total_views?: number;
    views_last_30_days?: number;
    total_favorites?: number;
    total_inquiries?: number;
    unread_inquiries?: number;
  };
  verification: {
    phone_verified: boolean;
    email_verified: boolean;
    can_publish: boolean;
    seller_verified: boolean;
    verification_level: string;
  };
  /**
   * The dashboard spells this `percent`; the vendor-profile progress meta
   * spells the same idea `percentage`. Both are accepted rather than picking
   * one and quietly reading `undefined`.
   */
  profile_completion: number | { percent?: number; percentage?: number };
};

export type VendorAnalytics = {
  range: { from: string; to: string; days: number };
  views: DailyPoint[];
  favorites: DailyPoint[];
  inquiries: DailyPoint[];
  reviews: DailyPoint[];
};

// -------------------------------------------------------------- engagement

export type Inquiry = {
  uuid: string;
  /** The sender's own name, split as the API stores it. */
  first_name: string | null;
  last_name: string | null;
  email: string;
  phone: string | null;
  message: string;
  source: string;
  status: string;
  listing?: { slug: string; title: string; uuid?: string } | null;
  /** Null until the vendor answers. */
  reply: { body: string; replied_at: string | null } | null;
  read_at: string | null;
  created_at: string | null;
};

export type Review = {
  uuid: string;
  rating: number;
  title: string | null;
  body: string | null;
  status: string;
  reviewer: { uuid: string; name: string } | null;
  reply: { body: string; replied_at: string } | null;
  listing?: { slug: string; title: string } | null;
  created_at: string | null;
};

export type VendorVerification = {
  uuid: string;
  type: string;
  status: string;
  /**
   * Masked, even for the person it belongs to — "•••• •••• •••• 0123".
   *
   * The vendor typed this number themselves; redisplaying all twenty digits
   * would put a national identity number into the page source, the browser
   * cache and any screenshot of the dashboard to tell them nothing new. The
   * full value exists only behind `verification.review`.
   */
  document_number_masked: string | null;
  reviewed_at: string | null;
  reviewer_note: string | null;
  /**
   * Pending, but a reviewer has already asked for something to be fixed.
   *
   * Derived server-side rather than being a status of its own: requesting
   * information deliberately leaves the request pending and actionable, and
   * pending WITH a note is exactly what "needs correction" means.
   */
  needs_correction: boolean;
  created_at: string;
};

export type VerificationsMeta = {
  types: { value: string; label: string }[];
  /**
   * Whether any automated identity check is connected.
   *
   * Always false today — NIDA publishes no integration a marketplace can call,
   * so every document is read by a person. Surfaced so the portal can say that
   * plainly instead of leaving a human queue looking like a stalled robot.
   */
  automated_verification: { available: boolean; provider: string };
  nida_digits: number;
};

// ----------------------------------------------------------------- promotions

/**
 * A placement a vendor may request.
 *
 * Served from the API's `AdPlacement` enum, filtered by `isVendorRequestable()`
 * — there is no second placement list on this side. A placement the public
 * serving system does not support cannot appear here, because it would not
 * exist in the enum the serving system reads.
 */
export type PromotionPlacement = {
  value: string;
  label: string;
  description: string;
  aspect_ratio: { desktop: number; mobile: number };
  max_concurrent: number;
  vendor_requestable: boolean;
};

export type PromotionStatusOption = {
  value: string;
  label: string;
  is_reviewable: boolean;
  is_cancellable: boolean;
  is_editable: boolean;
};

export type PromotionOptions = {
  placements: PromotionPlacement[];
  promotable_types: string[];
  statuses: PromotionStatusOption[];
};

/** Something the vendor owns and could promote. */
export type PromotableItem = {
  type: string;
  /** Null for the business profile — a vendor has exactly one, resolved server-side. */
  uuid: string | null;
  label: string;
  image_url: string | null;
};

export type PromotionRequest = {
  uuid: string;
  promoted: {
    type: string | null;
    label: string | null;
    /** False once the promoted listing has been deleted. A real state. */
    still_exists: boolean;
  };
  placement: string;
  placement_label: string;
  requested_start: string;
  requested_end: string;
  status: string;
  status_label: string;
  is_cancellable: boolean;
  creative: {
    headline: string;
    body: string | null;
    cta_label: string | null;
    /** Derived server-side. Read-only — never an input on this surface. */
    destination_url: string | null;
    image?: { url: string } | null;
    mobile_image?: { url: string } | null;
  };
  review: {
    reviewed_at: string | null;
    rejection_reason: string | null;
  };
  /**
   * The campaign an administrator minted on approval, when there is one.
   *
   * `is_serving` is the honest answer to "is my promotion live?". Approval
   * creates a DRAFT campaign and an administrator activates it separately, so
   * an approved request is not yet running — and this surface never calls it
   * Active until this says so.
   */
  campaign?: {
    uuid: string;
    status: string;
    status_label: string;
    is_serving: boolean;
    impressions: number;
    clicks: number;
    ctr: number | null;
  } | null;
  created_at: string | null;
};

// ----------------------------------------------------------------- specialist

export type ServiceMode = "online" | "in_person" | "both";

export type SpecialistService = {
  uuid: string;
  name: string;
  description: string | null;
  duration_minutes: number;
  /** The specialist's own padding between appointments. Owner view only. */
  buffer_minutes?: number;
  mode: ServiceMode;
  mode_label: string;
  is_active: boolean;
  /** Null is "price on enquiry", never free. */
  price: { amount: number; currency: string } | null;
  position?: number;
  bookings_count?: number;
};

export type AvailabilityWindow = {
  id: number;
  weekday: number;
  start_time: string;
  end_time: string;
};

export type AvailabilityBlock = {
  id: number;
  starts_at: string;
  ends_at: string;
  reason: string | null;
};

export type SpecialistAvailability = {
  timezone: string;
  hours: AvailabilityWindow[];
  blocks: AvailabilityBlock[];
};

export type VendorBooking = {
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
  /** Present only on the specialist's own view — a booking is useless without it. */
  customer?: { name: string; email: string | null; phone: string; is_registered: boolean };
  cancelled_by?: string | null;
  created_at: string | null;
};

export type SpecialistOptions = {
  modes: { value: string; label: string }[];
  statuses: {
    value: string;
    label: string;
    occupies_slot: boolean;
    is_cancellable: boolean;
    allowed_transitions: string[];
  }[];
  default_timezone: string;
  weekdays: { value: number; label: string }[];
};
