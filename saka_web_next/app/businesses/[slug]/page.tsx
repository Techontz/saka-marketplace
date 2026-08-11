import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Building2,
  Globe,
  Mail,
  MapPin,
  MessageCircle,
  Navigation,
  Phone,
  ShieldCheck,
  Star,
} from "lucide-react";

import { BusinessCard } from "@/components/businesses/BusinessCard";
import { BusinessReviews } from "@/components/businesses/BusinessReviews";
import { OpeningHours } from "@/components/businesses/OpeningHours";
import { SocialLinks } from "@/components/businesses/SocialLinks";
import { NearbyPlaces } from "@/components/businesses/NearbyPlaces";
import { ShareButton } from "@/components/businesses/ShareButton";
import { FavoriteButton } from "@/components/listings/FavoriteButton";
import { ListingCard } from "@/components/listings/ListingCard";
import { SafeImage } from "@/components/ui/SafeImage";
import { DirectionsLinks } from "@/components/map/DirectionsLinks";
// The direct import, not LazyMapView: this is a SERVER component, and
// next/dynamic with `ssr: false` is not permitted in one. The business map is
// also always visible here, so deferring it would buy nothing.
import { MapView } from "@/components/map/MapView";
import { SearchHero } from "@/components/search/SearchHero";
import { ApiError } from "@/lib/api/errors";
import { getBusiness, getBusinessListings, getSimilarBusinesses } from "@/lib/api/public";
import { googleDirectionsUrl } from "@/lib/config";
import { toListingView } from "@/lib/view-models";

type Props = { params: Promise<{ slug: string }> };

/**
 * A business page: who they are, what they have, where they are, and what
 * people said. Server-rendered — this is a page businesses will share.
 *
 * The layout follows the convention every marketplace of this kind has settled
 * on, because it is the one visitors already know how to read: a wide cover, a
 * card straddling its lower edge carrying identity and trust signals, then one
 * row of actions. Everything above the fold answers "who is this, are they
 * real, and how do I reach them".
 */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;

  try {
    const { data } = await getBusiness(slug);

    return {
      /*
       * Canonical URL — the slug route is the one true address for this
       * record. Without it, a listing reachable through a filtered path or a
       * tracking parameter competes with itself in the index.
       */
      alternates: { canonical: `/businesses/${slug}` },
      title: data.display_name,
      description:
        data.bio?.slice(0, 160) ??
        `${data.business_type_label ?? "Business"} in ${data.location.region ?? "Tanzania"} on SAKA.`,
      openGraph: {
        title: data.display_name,
        description: data.bio?.slice(0, 200) ?? undefined,
        images: data.cover_url ? [{ url: data.cover_url }] : undefined,
      },
    };
  } catch {
    return { title: "Business" };
  }
}

export default async function BusinessPage({ params }: Props) {
  const { slug } = await params;

  let business;

  try {
    business = (await getBusiness(slug)).data;
  } catch (error) {
    if (error instanceof ApiError && error.isNotFound) notFound();
    throw error;
  }

  const [listings, similar] = await Promise.all([
    getBusinessListings(slug).catch(() => null),
    getSimilarBusinesses(slug).catch(() => ({ data: [] })),
  ]);

  const views = (listings?.data ?? []).map(toListingView);

  const lat = business.location.latitude;
  const lng = business.location.longitude;
  const hasCoords = lat !== null && lng !== null;

  const address = [
    business.location.street,
    business.location.ward,
    business.location.district,
    business.location.region,
  ]
    .filter(Boolean)
    .join(", ");

  /*
   * Most sellers never upload a cover. Rather than shipping a stock photo that
   * has nothing to do with them, the first listing photo stands in — it is
   * their own work, and it makes the page look finished from day one.
   */
  const cover = business.cover_url ?? views.find((view) => view.image)?.image ?? null;

  const liveListings = business.stats?.active_listings ?? business.listing_count;
  const whatsapp = business.contact?.whatsapp?.replace(/[^0-9]/g, "");

  const actionBase =
    "inline-flex items-center justify-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold transition";
  const actionGhost = `${actionBase} border border-border bg-white text-navy hover:border-teal hover:text-teal`;

  return (
    <div className="bg-page min-h-screen">
      {/*
        The SAME hero as /listings and /businesses — same component, so the
        height, padding, gradient and type scale cannot drift apart.

        It replaces a full-bleed cover with a card pulled up over it by a
        negative margin. That arrangement was both taller than every other
        page's header and overlapping: the card sat on top of the image, so the
        image was partly unviewable and everything below started lower than it
        should. The cover photo is still used — as the hero background.
      */}
      <SearchHero
        title={business.display_name}
        trail={["Businesses", business.display_name]}
        image={cover}
        description={business.business_type_label ?? undefined}
      />

      <div className="mx-auto max-w-7xl px-6 pt-8">
        {/* ------------------------------------------------------ identity */}
        <div className="rounded-2xl border border-border bg-white p-6 shadow-[0_18px_50px_-24px_rgba(6,28,63,0.35)] md:p-8">
          <div className="flex flex-wrap items-start gap-5">
            <SafeImage
              src={business.logo_url}
              alt={`${business.display_name} logo`}
              className="h-24 w-24 shrink-0 rounded-2xl object-cover ring-4 ring-white md:h-28 md:w-28"
              fallbackClassName="h-24 w-24 shrink-0 rounded-2xl bg-teal/10 text-teal ring-4 ring-white md:h-28 md:w-28"
              fallback={<Building2 className="h-10 w-10" />}
            />

            <div className="min-w-0 flex-1">
              {/*
                The NAME is the hero's h1 and is not repeated here — a document
                gets exactly one h1, and printing the same words twice, forty
                pixels apart, reads as a rendering bug. What survives is what
                the hero cannot carry: the trust badge, the address and the
                counters.
              */}
              <div className="flex flex-wrap items-center gap-3">
                <h2 className="text-xl font-extrabold text-navy">
                  {business.business_type_label ?? "Business"}
                </h2>

                {business.is_verified && (
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-teal/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-teal">
                    <ShieldCheck className="h-3.5 w-3.5" strokeWidth={2.5} />
                    Verified
                  </span>
                )}
              </div>

              {address && (
                <p className="mt-1 flex items-start gap-1.5 text-sm text-muted-foreground">
                  <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal" />
                  {address}
                </p>
              )}

              {/* Trust signals, in the order a buyer weighs them. */}
              <dl className="mt-5 flex flex-wrap items-center gap-x-8 gap-y-3">
                <Stat
                  label={
                    business.rating.count > 0
                      ? `${business.rating.count} review${business.rating.count !== 1 ? "s" : ""}`
                      : "No reviews yet"
                  }
                  value={
                    business.rating.count > 0 ? (
                      <span className="flex items-center gap-1">
                        <Star className="h-4 w-4 fill-orange text-orange" />
                        {business.rating.average?.toFixed(1)}
                      </span>
                    ) : (
                      "—"
                    )
                  }
                />

                <Stat label="Live listings" value={(liveListings ?? 0).toLocaleString()} />

                {business.member_since && (
                  <Stat
                    label="On SAKA since"
                    value={new Date(business.member_since).getFullYear().toString()}
                  />
                )}

                {business.stats?.response_rate_pct !== null &&
                  business.stats?.response_rate_pct !== undefined && (
                    <Stat label="Responds to" value={`${business.stats.response_rate_pct}%`} />
                  )}

                {business.stats?.response_time_minutes !== null &&
                  business.stats?.response_time_minutes !== undefined && (
                    <Stat
                      label="Replies in"
                      value={formatResponseTime(business.stats.response_time_minutes)}
                    />
                  )}
              </dl>
            </div>
          </div>

          {business.bio && (
            <p className="mt-6 max-w-3xl whitespace-pre-wrap leading-relaxed text-muted-foreground">
              {business.bio}
            </p>
          )}

          {/* ------------------------------------------------------ actions */}
          <div className="mt-6 flex flex-wrap items-center gap-2.5 border-t border-border pt-6">
            {business.contact?.phone && (
              <a
                href={`tel:${business.contact.phone}`}
                className={`${actionBase} bg-teal text-white hover:opacity-90`}
              >
                <Phone className="h-4 w-4" />
                Call
              </a>
            )}

            {whatsapp && (
              <a
                href={`https://wa.me/${whatsapp}`}
                target="_blank"
                rel="noopener noreferrer"
                className={`${actionBase} bg-[#25D366] text-white hover:opacity-90`}
              >
                <MessageCircle className="h-4 w-4" />
                WhatsApp
              </a>
            )}

            {business.contact?.email && (
              <a href={`mailto:${business.contact.email}`} className={actionGhost}>
                <Mail className="h-4 w-4" />
                Email
              </a>
            )}

            {business.contact?.website && (
              <a
                href={business.contact.website}
                target="_blank"
                rel="noopener noreferrer"
                className={actionGhost}
              >
                <Globe className="h-4 w-4" />
                Website
              </a>
            )}

            {hasCoords && (
              <a
                href={googleDirectionsUrl(lat, lng)}
                target="_blank"
                rel="noopener noreferrer"
                className={actionGhost}
              >
                <Navigation className="h-4 w-4" />
                Directions
              </a>
            )}

            <ShareButton
              title={business.display_name}
              text={business.bio ?? undefined}
              className={actionGhost}
            />

            <FavoriteButton
              kind="business"
              slug={business.slug}
              label={business.display_name}
              withLabel
              className={actionGhost}
            />
          </div>
        </div>

        {/* --------------------------------------------------------- body */}
        <div className="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">
          <div className="space-y-8 lg:col-span-2">
            <section>
              <h2 className="mb-5 text-2xl font-extrabold text-navy">
                Listings from {business.display_name}
              </h2>

              {views.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border bg-white p-10 text-center text-muted-foreground">
                  This business has nothing listed right now.
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                  {views.map((listing) => (
                    <ListingCard key={listing.slug} p={listing} />
                  ))}
                </div>
              )}

              {listings && listings.meta.last_page > 1 && (
                <p className="mt-4 text-sm text-muted-foreground">
                  Showing {views.length} of {listings.meta.total}.{" "}
                  <Link
                    href={`/listings?q=${encodeURIComponent(business.display_name)}`}
                    className="font-semibold text-teal"
                  >
                    See all
                  </Link>
                </p>
              )}
            </section>

            <BusinessReviews slug={business.slug} businessName={business.display_name} />
          </div>

          <aside className="space-y-6">
            <div className="rounded-xl border border-border bg-white p-6">
              <h3 className="mb-4 text-lg font-bold text-navy">Contact</h3>
              <ul className="space-y-3 text-sm">
                {business.contact?.phone && (
                  <li>
                    <a
                      href={`tel:${business.contact.phone}`}
                      className="flex items-center gap-2 text-navy hover:text-teal"
                    >
                      <Phone className="h-4 w-4 text-teal" /> {business.contact.phone}
                    </a>
                  </li>
                )}
                {business.contact?.email && (
                  <li>
                    <a
                      href={`mailto:${business.contact.email}`}
                      className="flex items-center gap-2 text-navy hover:text-teal"
                    >
                      <Mail className="h-4 w-4 text-teal" /> {business.contact.email}
                    </a>
                  </li>
                )}
                {business.contact?.website && (
                  <li>
                    <a
                      href={business.contact.website}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center gap-2 truncate text-navy hover:text-teal"
                    >
                      <Globe className="h-4 w-4 shrink-0 text-teal" />
                      <span className="truncate">{business.contact.website}</span>
                    </a>
                  </li>
                )}
                {address && (
                  <li className="flex items-start gap-2 text-muted-foreground">
                    <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal" /> {address}
                  </li>
                )}
                {!business.contact?.phone &&
                  !business.contact?.email &&
                  !business.contact?.website &&
                  !whatsapp && (
                    <li className="text-muted-foreground">
                      No contact details published. Message them from one of their listings.
                    </li>
                  )}
              </ul>

              {/*
                * Icons, not text pills.
                *
                * The pills rendered the raw map key — so an entry stored as
                * "x" showed a chip reading "x", and every network was labelled
                * only by whatever string happened to be in the JSON. SocialLinks
                * renders a known icon per network, drops anything it does not
                * recognise, and gives each link an accessible name that includes
                * the business — a screen-reader user tabbing the row otherwise
                * hears "Instagram, link. Facebook, link." with no idea whose.
                *
                * It renders nothing at all when there are no links, so the
                * border-top divider lives inside it rather than here.
                */}
              {business.social_links && Object.keys(business.social_links).length > 0 && (
                <div className="mt-4 border-t border-border pt-4">
                  <SocialLinks
                    links={business.social_links}
                    businessName={business.display_name}
                  />
                </div>
              )}
            </div>

            <OpeningHours hours={business.opening_hours ?? null} />

            {hasCoords && (
              <div className="rounded-xl border border-border bg-white p-6">
                <h3 className="mb-4 flex items-center gap-2 text-lg font-bold text-navy">
                  <MapPin className="h-4 w-4 text-teal" />
                  Find them
                </h3>
                <MapView
                  pins={[
                    {
                      id: business.slug,
                      lat,
                      lng,
                      label: business.display_name,
                      meta: address || undefined,
                      image: business.logo_url ?? null,
                      tone: "business",
                    },
                  ]}
                  center={{ lat, lng }}
                  zoom={15}
                  height={220}
                />
                <DirectionsLinks
                  className="mt-4"
                  lat={lat}
                  lng={lng}
                  label={business.display_name}
                />
              </div>
            )}

            {hasCoords && <NearbyPlaces lat={lat} lng={lng} />}
          </aside>
        </div>

        {similar.data.length > 0 && (
          <section className="py-14">
            <h2 className="mb-6 text-2xl font-extrabold text-navy">Similar businesses</h2>
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {similar.data.map((item) => (
                <BusinessCard key={item.slug} business={item} />
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dd className="text-lg font-extrabold text-navy">{value}</dd>
      <dt className="text-xs text-muted-foreground">{label}</dt>
    </div>
  );
}

/** "Replies in 2 hours" reads better than "in 120 minutes". */
function formatResponseTime(minutes: number): string {
  if (minutes < 60) return `${Math.round(minutes)} min`;
  if (minutes < 60 * 24) {
    const hours = Math.round(minutes / 60);
    return `${hours} hour${hours !== 1 ? "s" : ""}`;
  }

  const days = Math.round(minutes / (60 * 24));
  return `${days} day${days !== 1 ? "s" : ""}`;
}
