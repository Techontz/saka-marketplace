"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import {
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  Eye,
  Hash,
  Heart,
  MapPin,
  MessageSquare,
  ShieldCheck,
  Star,
  Tag,
} from "lucide-react";

import { ReportListingButton } from "@/components/listings/ReportListingButton";
import { ShareButton } from "@/components/businesses/ShareButton";
import { SafeImage } from "@/components/ui/SafeImage";
import { apiGet } from "@/lib/api/browser";
import type { ApiBusiness, ApiListing } from "@/lib/types";
import { formatPrice, purposePhrase, toListingView } from "@/lib/view-models";
import { useAuth } from "@/providers/AuthProvider";
import { useAuthDialog } from "@/providers/AuthDialogProvider";
import { useFavorites } from "@/providers/FavoritesProvider";

/**
 * Everything a buyer needs before they scroll.
 *
 * This block exists because the gallery is shorter than the sidebar beside it,
 * and the column under it was empty white space on every listing — the page
 * read as unfinished exactly where it had to read as trustworthy.
 *
 * Filling it with the facts that were previously buried in a tab is not just
 * cosmetic: price, address, purpose, reference number and the seller's
 * standing are what someone checks before they decide to call, and none of them
 * should be behind a click.
 *
 * Everything here is REAL. Nothing renders a zero or an em dash to fill a slot:
 * a listing with no views shows no views row, and a seller with no ratings gets
 * "No ratings yet" rather than a hollow five stars.
 */
export function ListingSummary({ listing }: { listing: ApiListing }) {
  const view = toListingView(listing);
  const descriptors = Array.isArray(listing.attributes) ? listing.attributes : [];

  const { isAuthenticated } = useAuth();
  const authDialog = useAuthDialog();
  const favorites = useFavorites();
  const saved = favorites.isListingSaved(listing.slug);

  /*
   * The seller's standing lives on the business profile, not on the listing.
   * Fetched once and cached for ten minutes — it is a public read, and every
   * listing by the same seller reuses the same cache entry.
   */
  const profile = useQuery({
    queryKey: ["business", listing.seller?.slug],
    queryFn: () => apiGet<{ data: ApiBusiness }>(`/businesses/${listing.seller!.slug}`),
    enabled: Boolean(listing.seller?.slug),
    staleTime: 10 * 60 * 1000,
  });

  const business = profile.data?.data;
  const purpose = purposePhrase(view.purpose);

  /*
   * The reference a customer quotes on the phone.
   *
   * The last block of the UUID rather than the whole thing: it is short enough
   * to read aloud, and it is already unique across this catalogue. The database
   * id would have been shorter still and would have leaked how many listings
   * the platform has.
   */
  const reference = listing.uuid.split("-").pop()?.toUpperCase() ?? listing.uuid;

  const published = listing.published_at ?? listing.created_at ?? null;

  return (
    <div className="space-y-6">
      {/* ---------------------------------------------------- summary ---- */}
      <section className="rounded-xl border border-border bg-white p-6 sm:p-8">
        <div className="flex flex-wrap items-center gap-2">
          {purpose && (
            <span className="rounded-full bg-teal/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-teal">
              {purpose}
            </span>
          )}
          {listing.is_verified && (
            <span className="inline-flex items-center gap-1 rounded-full bg-navy/5 px-3 py-1 text-xs font-bold uppercase tracking-wide text-navy">
              <ShieldCheck className="h-3.5 w-3.5 text-teal" />
              Verified
            </span>
          )}
          {listing.is_featured && (
            <span className="rounded-full bg-orange/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-orange">
              Featured
            </span>
          )}
          {listing.condition && (
            <span className="rounded-full border border-border px-3 py-1 text-xs font-semibold capitalize text-muted-foreground">
              {listing.condition}
            </span>
          )}
        </div>

        <h2 className="mt-4 text-2xl font-extrabold leading-snug text-navy sm:text-3xl">
          {listing.title}
        </h2>

        <p className="mt-2 flex items-start gap-1.5 text-muted-foreground">
          <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal" />
          {view.location || "Location not published"}
        </p>

        <div className="mt-4 flex flex-wrap items-baseline gap-3">
          <span className="text-3xl font-extrabold text-teal">{formatPrice(view)}</span>
          {view.isNegotiable && (
            <span className="text-sm font-semibold text-muted-foreground">Negotiable</span>
          )}
        </div>

        {/* Facts. Each row is omitted when the API has nothing for it. */}
        <dl className="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-border pt-6 sm:grid-cols-3">
          {listing.category && (
            <Fact icon={<Tag className="h-4 w-4" />} label="Category">
              <Link
                href={`/listings?category=${listing.category.parent?.slug ?? listing.category.slug}`}
                className="inline-flex min-h-11 items-center transition hover:text-teal"
              >
                {listing.category.parent?.name ?? listing.category.name}
              </Link>
            </Fact>
          )}

          {listing.category?.parent && (
            <Fact icon={<Building2 className="h-4 w-4" />} label="Subcategory">
              <Link
                href={`/listings?category=${listing.category.slug}`}
                className="inline-flex min-h-11 items-center transition hover:text-teal"
              >
                {listing.category.name}
              </Link>
            </Fact>
          )}

          {listing.available_from && (
            <Fact icon={<Calendar className="h-4 w-4" />} label="Available from">
              {new Date(listing.available_from).toLocaleDateString(undefined, {
                day: "numeric",
                month: "long",
                year: "numeric",
              })}
            </Fact>
          )}

          {published && (
            <Fact icon={<Clock className="h-4 w-4" />} label="Published">
              {new Date(published).toLocaleDateString(undefined, {
                day: "numeric",
                month: "long",
                year: "numeric",
              })}
            </Fact>
          )}

          {listing.stats.views > 0 && (
            <Fact icon={<Eye className="h-4 w-4" />} label="Views">
              {listing.stats.views.toLocaleString()}
            </Fact>
          )}

          {listing.stats.inquiries > 0 && (
            <Fact icon={<MessageSquare className="h-4 w-4" />} label="Enquiries">
              {listing.stats.inquiries.toLocaleString()}
            </Fact>
          )}

          <Fact icon={<Hash className="h-4 w-4" />} label="Reference">
            <span className="font-mono text-sm tracking-wide">{reference}</span>
          </Fact>
        </dl>

        {/* Actions. Save and Share are also in the sidebar; repeating them here
            is deliberate — this is where someone finishes reading the summary,
            and Report has nowhere else sensible to live. */}
        <div className="mt-6 flex flex-wrap items-center gap-3 border-t border-border pt-5">
          <button
            type="button"
            onClick={() => {
              if (!isAuthenticated) {
                authDialog.open("login", "Sign in to save this listing.");
                return;
              }
              void favorites.toggleListing(listing.slug);
            }}
            className="inline-flex items-center gap-2 rounded-full border-2 border-border px-5 py-2.5 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
          >
            <Heart className={`h-4 w-4 ${saved ? "fill-teal text-teal" : ""}`} />
            {saved ? "Saved" : "Save"}
          </button>

          <ShareButton
            title={listing.title}
            text={`${listing.title} on SAKA`}
            className="inline-flex items-center gap-2 rounded-full border-2 border-border px-5 py-2.5 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
          />

          <ReportListingButton slug={listing.slug} />
        </div>
      </section>

      {/* ------------------------------------------------- quick facts ---- */}
      {descriptors.length > 0 && (
        <section className="rounded-xl border border-border bg-white p-6 sm:p-8">
          <h3 className="mb-5 text-xl font-extrabold text-navy">Key features</h3>

          {/*
            Rendered straight from whatever the category defines. There is no
            list of property fields here: a plot shows its area, a car shows
            mileage and transmission, a generator shows kVA. Adding an attribute
            in the admin portal makes it appear here with no deploy.
          */}
          <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {descriptors.map((attribute) => (
              <div key={attribute.code} className="rounded-lg bg-page px-4 py-3">
                <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {attribute.name}
                </dt>
                <dd className="mt-0.5 font-bold text-navy">
                  {formatAttributeValue(attribute.label ?? attribute.value)}
                  {attribute.unit && (
                    <span className="ml-1 text-sm font-semibold text-muted-foreground">
                      {attribute.unit}
                    </span>
                  )}
                </dd>
              </div>
            ))}
          </dl>
        </section>
      )}

      {/* ------------------------------------------------- description ---- */}
      {listing.description && (
        <section className="rounded-xl border border-border bg-white p-6 sm:p-8">
          <h3 className="mb-4 text-xl font-extrabold text-navy">Description</h3>
          <p className="whitespace-pre-wrap leading-relaxed text-muted-foreground">
            {listing.description}
          </p>
        </section>
      )}

      {/* -------------------------------------------- seller highlights --- */}
      {listing.seller?.slug && (
        <section className="rounded-xl border border-border bg-white p-6 sm:p-8">
          <h3 className="mb-5 text-xl font-extrabold text-navy">About the seller</h3>

          <div className="flex flex-wrap items-center gap-4">
            <SafeImage
              src={business?.logo_url ?? listing.seller.logo_url}
              alt={`${listing.seller.display_name} logo`}
              className="h-14 w-14 rounded-full object-cover"
              fallbackClassName="h-14 w-14 rounded-full bg-teal/10 text-teal"
              fallback={<Building2 className="h-6 w-6" />}
            />

            <div className="min-w-0 flex-1">
              <Link
                href={`/businesses/${listing.seller.slug}`}
                className="flex items-center gap-1.5 text-lg font-bold text-navy transition hover:text-teal"
              >
                {listing.seller.display_name}
                {listing.seller.is_verified && <ShieldCheck className="h-4 w-4 shrink-0 text-teal" />}
              </Link>
              {business?.business_type_label && (
                <p className="text-sm text-muted-foreground">{business.business_type_label}</p>
              )}
            </div>

            <Link
              href={`/businesses/${listing.seller.slug}`}
              className="rounded-full border-2 border-teal px-5 py-2 text-sm font-semibold text-teal transition hover:bg-teal hover:text-white"
            >
              View profile
            </Link>
          </div>

          <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-border pt-5 sm:grid-cols-4">
            <Highlight
              label="Rating"
              value={
                business?.rating.count
                  ? `${business.rating.average?.toFixed(1) ?? "—"} / 5`
                  : "No ratings yet"
              }
              hint={business?.rating.count ? `${business.rating.count} reviews` : undefined}
              icon={<Star className="h-4 w-4 fill-orange text-orange" />}
            />

            <Highlight
              label="Listings"
              value={
                business?.stats?.active_listings !== undefined
                  ? business.stats.active_listings.toLocaleString()
                  : (business?.listing_count?.toLocaleString() ?? "—")
              }
            />

            {/* "Years on SAKA" is derived, not stored: a member_since of this
                year should read "New this year", not "0 years". */}
            {(business?.member_since ?? listing.seller.member_since) && (
              <Highlight
                label="On SAKA"
                value={membershipLength(business?.member_since ?? listing.seller.member_since)}
              />
            )}

            {business?.stats?.response_time_minutes != null && (
              <Highlight
                label="Replies in"
                value={formatResponseTime(business.stats.response_time_minutes)}
                icon={<Clock className="h-4 w-4 text-teal" />}
              />
            )}

            {business?.verification?.is_verified && (
              <Highlight
                label="Verification"
                value="Verified"
                hint={
                  business.verification.verified_at
                    ? new Date(business.verification.verified_at).getFullYear().toString()
                    : undefined
                }
                icon={<CheckCircle2 className="h-4 w-4 text-teal" />}
              />
            )}
          </dl>
        </section>
      )}
    </div>
  );
}

function Fact({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <dt className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        <span className="text-teal">{icon}</span>
        {label}
      </dt>
      <dd className="mt-1 font-semibold text-navy">{children}</dd>
    </div>
  );
}

function Highlight({
  label,
  value,
  hint,
  icon,
}: {
  label: string;
  value: string;
  hint?: string;
  icon?: React.ReactNode;
}) {
  return (
    <div className="rounded-lg bg-page px-4 py-3">
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className="mt-0.5 flex items-center gap-1.5 font-bold text-navy">
        {icon}
        <span className="truncate">{value}</span>
      </dd>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/** Booleans arrive as true/false and must not render as "true". */
function formatAttributeValue(value: string | number | boolean | null): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "number") return value.toLocaleString();

  // Select attributes are stored as slugs ("semi-furnished"); the API sends a
  // label alongside, but a value that arrives without one still has to read.
  return value.replace(/[-_]/g, " ").replace(/^\w/, (letter) => letter.toUpperCase());
}

function membershipLength(since: string | null | undefined): string {
  if (!since) return "—";

  const years = (Date.now() - new Date(since).getTime()) / (365.25 * 24 * 60 * 60 * 1000);

  if (years < 1) return "New this year";
  if (years < 2) return "1 year";

  return `${Math.floor(years)} years`;
}

function formatResponseTime(minutes: number): string {
  if (minutes < 60) return `${Math.round(minutes)} min`;
  if (minutes < 60 * 24) return `${Math.round(minutes / 60)} hr`;

  return `${Math.round(minutes / (60 * 24))} days`;
}
