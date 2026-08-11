/** Wire types for the admin API. Naming follows the wire exactly (snake_case). */

export type Envelope<T> = { data: T };

export type PaginationMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
};

export type Paginated<T> = { data: T[]; meta: PaginationMeta };

export type AdminUser = {
  uuid: string;
  first_name: string;
  last_name: string | null;
  full_name?: string;
  email: string;
  phone: string | null;
  status: string;
  email_verified: boolean;
  phone_verified: boolean;
  roles: string[];
  listings_count?: number;
  seller_profile?: {
    slug: string;
    display_name: string;
    is_verified: boolean;
    verification_level: string;
    rating_avg: number | null;
  } | null;
  last_login_at?: string | null;
  created_at: string | null;
};

export type SessionUser = {
  uuid: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  email: string;
  status: string;
  roles: string[];
  /**
   * The caller's own permission slugs, from `GET /auth/me`.
   *
   * Read from the API rather than derived from `roles` on the client: the
   * role/permission matrix lives in PHP, and a second copy here would drift
   * the first time it changed — silently, and in the direction of showing
   * operators controls they cannot use.
   */
  permissions: string[];
};

export type Overview = {
  users: Record<string, number>;
  listings: Record<string, number>;
  engagement: {
    inquiries: number;
    unread_inquiries: number;
    reviews: number;
    pending_reviews: number;
    average_rating: number | null;
    favorites: number;
    views: number;
  };
  catalog: Record<string, number>;
  verifications: Record<string, number>;
  revenue: { available: boolean; reason: string };
};

export type DailyPoint = { date: string; value: number };

export type Growth = {
  range: { from: string; to: string; days: number };
  listings: DailyPoint[];
  published_listings: DailyPoint[];
  users: DailyPoint[];
  vendors: DailyPoint[];
  inquiries: DailyPoint[];
  reviews: DailyPoint[];
  favorites: DailyPoint[];
  views: DailyPoint[];
};

export type CategoryPopularity = { name: string; slug: string; icon: string | null; listings: number };

export type TopVendor = {
  uuid: string;
  name: string;
  is_verified: boolean;
  listings: number;
  views: number;
  inquiries: number;
  favorites: number;
};

export type AuditEntry = {
  id: number;
  action: string;
  actor: { uuid: string; name: string; email: string } | null;
  actor_label: string | null;
  subject: { type: string; id: number } | null;
  changes: Record<string, unknown> | null;
  previous: Record<string, unknown> | null;
  ip_address: string | null;
  request_id: string | null;
  created_at: string;
  direction?: "performed" | "received";
};

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
  location: { region?: string | null; address_line: string | null };
  primary_image?: { url: string } | null;
  stats: { views: number; favorites: number; inquiries: number };
  attributes: Record<string, string | number | boolean | null>;
  created_at: string | null;
};

export type ListingDetail = ListingSummary & {
  description: string | null;
  images: { uuid: string; url: string }[];
  amenities: { slug: string; name: string }[];
  facilities: { slug: string; name: string }[];
  seller?: { uuid: string; display_name: string; is_verified: boolean };
  deleted_at: string | null;
  rejection_reason?: string | null;
  status_history: { from: string | null; to: string; reason: string | null; at: string }[];
};

export type BulkResult = {
  action: string;
  succeeded: string[];
  failed: { uuid: string; reason: string }[];
  summary: { requested: number; succeeded: number; failed: number };
};

export type Category = {
  slug: string;
  name: string;
  icon: string | null;
  description: string | null;
  depth: number;
  is_leaf: boolean;
  listing_count: number;
  image_url?: string | null;
  children?: Category[];
};

export type Attribute = {
  code: string;
  name: string;
  input_type: string;
  data_type: string;
  unit: string | null;
  is_filterable: boolean;
  position?: number;
  options?: { value: string; label: string }[];
};

export type TaxonomyTerm = { slug: string; name: string; icon: string | null };

export type VerificationRequest = {
  uuid: string;
  type: string;
  status: string;
  /**
   * The FULL identity number — reviewers only.
   *
   * A reviewer's job is to compare this against the document in the photograph,
   * so it cannot be masked here. This type has no counterpart on the public API:
   * `BusinessResource` carries no identity field at all, and the vendor's own
   * view gets `document_number_masked`.
   */
  document_number: string | null;
  document_number_masked: string | null;
  /**
   * Present only when no automated provider is connected — which is always,
   * today. Rendered as "not available", never as a failed check: a vendor whose
   * document could not be machine-checked has done nothing wrong.
   */
  automated_check: { outcome: string; provider: string } | null;
  /** Pending, but the reviewer has already asked the vendor to fix something. */
  needs_correction: boolean;
  document_url: string | null;
  user: { uuid: string; name: string; email: string; phone_verified: boolean };
  reviewed_by: string | null;
  reviewed_at: string | null;
  rejection_reason: string | null;
  created_at: string;
};

export type Role = {
  name: string;
  label: string;
  description: string | null;
  level: number;
  is_assignable: boolean;
  permissions: string[];
  users_count: number;
};

export type Banner = {
  uuid: string;
  title: string;
  subtitle: string | null;
  link_url: string | null;
  link_label: string | null;
  placement: string;
  position: number;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  image_url: string | null;
  is_live: boolean;
};

export type HomepageSection = {
  key: string;
  title: string;
  subtitle: string | null;
  position: number;
  is_active: boolean;
  item_limit: number | null;
};

export type Faq = { id: number; question: string; answer: string; group: string | null; position: number; is_active: boolean };

export type Page = {
  slug: string;
  title: string;
  body: string | null;
  meta_title: string | null;
  meta_description: string | null;
  is_published: boolean;
  published_at: string | null;
};

export type Setting = {
  key: string;
  value: unknown;
  group: string;
  description: string | null;
  is_public: boolean;
};

export type PlaceCategory = {
  id: number;
  slug: string;
  name: string;
  icon: string | null;
  position: number;
  is_active: boolean;
  place_count: number;
};

export type AdminPlace = {
  uuid: string;
  slug: string;
  name: string;
  description: string | null;
  category: { id: number; slug: string; name: string; icon: string | null } | null;
  region: string | null;
  district: string | null;
  address_line: string | null;
  phone: string | null;
  website: string | null;
  opening_hours: string | null;
  is_active: boolean;
};

export type SystemInfo = {
  application: { name: string; environment: string; debug: boolean; url: string; timezone: string; maintenance: boolean };
  versions: { php: string; laravel: string; database: string | null };
  drivers: Record<string, string>;
  queue: { connection: string; failed_jobs?: number; pending_jobs?: number; failed_last_24h?: number; available?: boolean };
  storage: Record<string, unknown>;
};

export type Review = {
  uuid: string;
  rating: number;
  title: string | null;
  body: string | null;
  status: string;
  reviewer: { uuid: string; first_name: string } | null;
  created_at: string | null;
};

// ---------------------------------------------------------------- advertising

/**
 * Placement descriptors, served from `App\Domain\Advertising\Enums\AdPlacement`.
 *
 * Fetched rather than hardcoded. A placement added in PHP appears in the form's
 * dropdown with no deploy here, and the aspect ratios the upload guidance shows
 * stay in step with the boxes the marketplace actually reserves — two copies of
 * those numbers is how an advert ends up letterboxed into a 45px sliver.
 */
export type AdPlacementOption = {
  value: string;
  label: string;
  description: string;
  aspect_ratio: { desktop: number; mobile: number };
  max_concurrent: number;
  expects_category_targeting: boolean;
};

export type AdStatusOption = {
  value: string;
  label: string;
  is_servable: boolean;
  follows_schedule: boolean;
};

export type AdvertisingOptions = {
  placements: AdPlacementOption[];
  statuses: AdStatusOption[];
};

export type Advertiser = {
  uuid: string;
  name: string;
  slug: string;
  is_active: boolean;
  contact: { name: string | null; email: string | null; phone: string | null };
  notes: string | null;
  vendor?: { slug: string; display_name: string } | null;
  campaigns_count?: number;
  created_at: string | null;
};

/** Delivery figures. `ctr` is null when nothing has been shown — never 0. */
export type AdPerformance = {
  impressions: number;
  clicks: number;
  ctr: number | null;
};

export type AdCreative = {
  uuid: string;
  headline: string;
  body: string | null;
  cta_label: string | null;
  click_url: string;
  alt_text: string | null;
  is_active: boolean;
  position: number;
  image?: { url: string } | null;
  mobile_image?: { url: string } | null;
  performance: AdPerformance;
  created_at: string | null;
};

export type AdCampaign = {
  uuid: string;
  name: string;
  advertiser: { uuid?: string; name?: string };
  placement: string;
  placement_label: string;
  /**
   * The stored column — a cache the scheduler refreshes every five minutes.
   */
  status: string;
  status_label: string;
  /**
   * What the DATES say right now.
   *
   * Sent alongside `status` because that column can be up to five minutes
   * stale: a campaign booked to start in two minutes reads "Scheduled" in the
   * list while it is already serving. Showing this one means the operator sees
   * what the campaign is DOING without the portal re-deriving the date rule.
   */
  effective_status: string;
  starts_at: string | null;
  ends_at: string | null;
  priority: number;
  impression_cap: number | null;
  targeting: {
    categories?: { slug: string; name: string }[];
    regions?: { slug: string; name: string }[];
  };
  performance: AdPerformance;
  creatives_count?: number;
  creatives?: AdCreative[];
  created_at: string | null;
};

export type AdvertisingPerformance = {
  /**
   * Whether anything was delivered in the range.
   *
   * The UI branches on this rather than on `totals.impressions > 0`, so "we
   * have sold no advertising yet" renders as an empty state instead of an
   * authoritative-looking chart asserting delivery was flat at zero.
   */
  has_data: boolean;
  range: { from: string; to: string };
  totals: AdPerformance;
  series: { date: string; impressions: number; clicks: number }[];
  by_placement: {
    placement: string;
    placement_label: string;
    impressions: number;
    clicks: number;
    ctr: number | null;
  }[];
  top_campaigns: {
    uuid: string;
    name: string;
    advertiser: string | null;
    placement_label: string;
    impressions: number;
    clicks: number;
    ctr: number | null;
  }[];
};

/**
 * A vendor's promotion request, as the reviewer sees it.
 *
 * `blockers` is the field this screen is built around: everything that would
 * make approval fail, computed server-side from the same facts the approval
 * path checks. Without it an operator presses Approve, gets a 422, and has to
 * infer which of five conditions failed.
 */
export type AdminPromotionRequest = {
  uuid: string;
  vendor?: { uuid: string; name: string; email: string } | null;
  promoted: {
    type: string | null;
    label: string | null;
    /** False once the promoted listing has been deleted. */
    still_exists: boolean;
    destination_url: string | null;
  };
  placement: string;
  placement_label: string;
  requested_start: string;
  requested_end: string;
  status: string;
  status_label: string;
  is_reviewable: boolean;
  creative: {
    headline: string;
    body: string | null;
    cta_label: string | null;
    image?: { url: string } | null;
    mobile_image?: { url: string } | null;
  };
  blockers: string[];
  review: {
    reviewed_at: string | null;
    reviewed_by?: string | null;
    rejection_reason: string | null;
  };
  campaign?: {
    uuid: string;
    status: string;
    status_label: string;
    is_serving: boolean;
  } | null;
  created_at: string | null;
};

// ------------------------------------------------------------------- bookings

export type AdminBooking = {
  uuid: string;
  scheduled_date: string;
  start_time: string;
  end_time: string;
  timezone: string;
  starts_at_utc: string;
  status: string;
  status_label: string;
  service?: { uuid: string; name: string; duration_minutes: number } | null;
  specialist?: { slug: string; title: string } | null;
  /** Support needs the customer's number to be able to help at all. */
  customer?: { name: string; email: string | null; phone: string; is_registered: boolean };
  customer_note: string | null;
  specialist_note: string | null;
  cancelled_by?: string | null;
  created_at: string | null;
};

export type BookingStats = {
  by_status: { status: string; label: string; total: number }[];
  total: number;
  upcoming: number;
};
