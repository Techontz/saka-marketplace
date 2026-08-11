import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { BookingPanel } from "@/components/specialists/BookingPanel";
import { ListingDetail } from "@/components/listings/ListingDetail";
import { ListingCard } from "@/components/listings/ListingCard";
import { RecentlyViewed } from "@/components/listings/RecentlyViewed";
import { ApiError } from "@/lib/api/errors";
import { DEFAULT_HERO_IMAGE, SearchHero } from "@/components/search/SearchHero";
import {
  getCategoryTaxonomy,
  getListing,
  getSimilarListings,
  getSpecialistServices,
} from "@/lib/api/public";
import { toListingView } from "@/lib/view-models";

type Props = { params: Promise<{ slug: string }> };

/**
 * One listing.
 *
 * Server-rendered: this is the page that gets shared on WhatsApp and indexed by
 * search engines, so the content has to be in the HTML rather than fetched
 * after hydration. The interactive parts — gallery, tabs, inquiry form, reviews
 * — hydrate on top.
 */
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;

  try {
    const { data } = await getListing(slug);
    const view = toListingView(data);

    return {
      /*
       * Canonical URL — the slug route is the one true address for this
       * record. Without it, a listing reachable through a filtered path or a
       * tracking parameter competes with itself in the index.
       */
      alternates: { canonical: `/listings/${slug}` },
      title: data.title,
      description:
        data.description?.slice(0, 160) ??
        `${view.subcategory} in ${view.location} on SAKA.`,
      openGraph: {
        title: data.title,
        description: data.description?.slice(0, 200) ?? view.location,
        images: view.image ? [{ url: view.image }] : undefined,
        type: "website",
      },
    };
  } catch {
    return { title: "Listing" };
  }
}

export default async function ListingPage({ params }: Props) {
  const { slug } = await params;

  let listing;

  try {
    listing = (await getListing(slug)).data;
  } catch (error) {
    // A 404 from the API is a 404 here; anything else is a real failure and
    // should surface as one rather than being disguised as "not found".
    if (error instanceof ApiError && error.isNotFound) notFound();
    throw error;
  }

  const [similar, categories] = await Promise.all([
    getSimilarListings(slug)
      .then((response) => response.data.map(toListingView))
      .catch(() => []),
    // The CACHED tree: this page reads `image_url` for the vertical's banner
    // and renders no count, so it has no reason to give up static rendering.
    getCategoryTaxonomy().catch(() => ({ data: [] })),
  ]);

  /*
   * The banner takes the vertical's own artwork rather than a fixed stock
   * photo, so a car listing is not framed by a picture of apartments. Only the
   * verticals carry an image, so the leaf category resolves through its parent.
   */
  const verticalSlug = listing.category?.parent?.slug ?? listing.category?.slug;
  const heroImage =
    categories.data.find((category) => category.slug === verticalSlug)?.image_url ??
    DEFAULT_HERO_IMAGE;

  /*
   * The heading is the LISTING, not the word "Property Details".
   *
   * That literal string was on every detail page, so a Toyota Hilux and a
   * five-acre plot were both introduced as "Property Details" — wrong for
   * eight of the nine verticals, and the h1 is what a search engine reads as
   * the page's subject.
   */
  const trail = [
    "Listings",
    listing.category?.parent?.name ?? listing.category?.name ?? "Listing",
  ].filter(Boolean);

  /*
   * Is this a specialist?
   *
   * Answered from the CATEGORY LINEAGE rather than a flag on the listing, so
   * a subcategory added through the admin taxonomy is included automatically —
   * the whole point of putting specialists in the existing EAV vertical.
   */
  const isSpecialist =
    listing.category?.parent?.slug === "specialists" ||
    listing.category?.slug === "specialists";

  // Only requested for specialists; every other vertical skips the round trip.
  const specialistMenu = isSpecialist
    ? await getSpecialistServices(listing.slug).catch(() => null)
    : null;

  const specialistServices = specialistMenu?.data ?? [];
  const specialistTimezone = specialistMenu?.meta.timezone ?? "Africa/Dar_es_Salaam";

  return (
    <div className="bg-page min-h-screen">
      <SearchHero title={listing.title} trail={trail} image={heroImage} />

      <ListingDetail listing={listing} />

      {/*
        * The booking panel, for specialists only.
        *
        * A specialist IS a listing in the `specialists` vertical, so this page
        * already renders their photo, bio, category attributes, location,
        * reviews and contact card. The one thing a listing cannot express is
        * what they SELL and when they are FREE — which is all this adds.
        *
        * Fetched on the server and rendered only when there is something to
        * show: a listing outside the vertical never pays for the request, and a
        * specialist with no services gets the panel's own honest empty state
        * rather than a booking box that cannot book.
        */}
      {isSpecialist && specialistServices.length > 0 && (
        <section className="mx-auto max-w-7xl px-6 pb-4">
          <div className="lg:max-w-md">
            <BookingPanel
              slug={listing.slug}
              services={specialistServices}
              timezone={specialistTimezone}
            />
          </div>
        </section>
      )}

      <RecentlyViewed />

      {similar.length > 0 && (
        <section className="mx-auto max-w-7xl px-6 py-16">
          <h2 className="text-2xl font-extrabold text-navy mb-8">More Listings</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {similar.slice(0, 4).map((item) => (
              <ListingCard key={item.slug} p={item} />
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
