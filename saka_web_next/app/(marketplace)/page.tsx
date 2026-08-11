import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import { AdSlot } from "@/components/ads/AdSlot";
import { HomeSearch } from "@/components/home/HomeSearch";
import { BrowseByCategory } from "@/components/home/BrowseByCategory";
import { Faq } from "@/components/home/Faq";
import { ListingCard } from "@/components/listings/ListingCard";
import { ListingCardHorizontal } from "@/components/listings/ListingCardHorizontal";
import { RecentlyViewed } from "@/components/listings/RecentlyViewed";
import { getCategories, getFaqs, getRecommended, getTrending } from "@/lib/api/public";
import { toListingView } from "@/lib/view-models";

/**
 * The homepage, section for section as the original.
 *
 * Browse by Category → Recommended → Trending → About → FAQ, with the same
 * headings, spacing and buttons. Two things are genuinely new, both required by
 * Milestone 13: recommendations are now personalised for a signed-in customer,
 * and a "recently viewed" rail appears for anyone who has looked at something.
 *
 * Rendered on the server so the page is indexable and fast; the per-customer
 * rails hydrate on top.
 */

/*
 * DYNAMIC, and deliberately.
 *
 * This page renders the live subcategory counts (Class A), whose `no-store`
 * fetch opts the route out of static rendering. The constant says 0 so the two
 * agree and nobody later "restores" a TTL without realising it re-freezes the
 * numbers this page exists to show.
 *
 * It is far from uncached: the rails (trending, featured, FAQs) keep their own
 * per-fetch caches, so a request here is one live call plus three cache hits.
 */
export const revalidate = 0;

export default async function HomePage() {
  // Parallel, and each failure is contained: a homepage that 500s because the
  // FAQ endpoint is down is a worse outcome than a homepage without an FAQ.
  const [categories, recommended, trending, faqs] = await Promise.all([
    getCategories().catch(() => ({ data: [] })),
    getRecommended().catch(() => ({ data: [] })),
    getTrending().catch(() => ({ data: [] })),
    getFaqs().catch(() => ({ data: [] })),
  ]);

  const recommendedViews = recommended.data.map(toListingView);
  const trendingViews = trending.data.map(toListingView);

  return (
    <>
      {/*
        The design opens on "Browse by Category" as an h2 and has no visible
        page title. A document still needs exactly one h1 — for screen-reader
        navigation and for search engines — so it is present and visually
        hidden. Nothing on screen changes.
      */}
      <h1 className="sr-only">
        SAKA — property, vehicles, services and businesses across Tanzania
      </h1>

      {/* Search first: it is the primary action on a marketplace and the
          homepage had no entry point to it at any width. */}
      <HomeSearch categories={categories.data} />

      <BrowseByCategory categories={categories.data} />

      {/*
        BETWEEN sections, never inside one.

        The hero unit sits after the category browser — past the first thing a
        visitor came for, before the rails they browse next. It renders nothing
        at all when no campaign is sold against it, so on an unsold day these
        two sections simply sit next to each other as they always did.
      */}
      <section className="bg-white">
        <div className="mx-auto max-w-7xl px-6 py-8">
          <AdSlot placement="homepage_hero" />
        </div>
      </section>

      <RecentlyViewed />

      {recommendedViews.length > 0 && (
        <section className="bg-white pt-8 pb-20">
          <div className="mx-auto max-w-7xl px-6">
            <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-12">
              <div>
                <h2 className="text-4xl md:text-5xl font-extrabold text-navy">Recommended Listings</h2>
                <p className="mt-4 text-muted-foreground">
                  Handpicked Listings just for you based on your preferences
                </p>
              </div>
              <Link
                href="/listings"
                className="inline-flex items-center gap-2 rounded-full bg-[#061C3F] pl-6 pr-2 py-2 text-white font-semibold tap-scale hover:shadow-xl self-start md:self-auto"
              >
                View All Property
                <span className="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
                  <ArrowUpRight className="h-4 w-4" />
                </span>
              </Link>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {recommendedViews.slice(0, 4).map((listing) => (
                <ListingCardHorizontal key={listing.slug} p={listing} />
              ))}
            </div>
          </div>
        </section>
      )}

      {trendingViews.length > 0 && (
        <section className="bg-page py-20">
          <div className="mx-auto max-w-7xl px-6">
            <div className="text-center mb-12">
              <h2 className="text-4xl md:text-5xl font-extrabold text-navy">Trending Listings</h2>
              <p className="mt-4 text-muted-foreground">
                Discover our most popular and trending Listings
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
              {trendingViews.slice(0, 8).map((listing) => (
                <ListingCard key={listing.slug} p={listing} />
              ))}
            </div>

            <div className="mt-12 flex justify-center">
              <Link
                href="/listings"
                className="inline-flex items-center gap-2 rounded-full bg-[#061C3F] pl-6 pr-2 py-2 text-white font-semibold tap-scale hover:shadow-xl"
              >
                View All Property
                <span className="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
                  <ArrowUpRight className="h-4 w-4" />
                </span>
              </Link>
            </div>
          </div>
        </section>
      )}

      {/* A thin strip, between sections. Collapses to nothing when unsold. */}
      <div className="mx-auto w-full max-w-7xl px-6">
        <AdSlot placement="homepage_strip" className="pb-4" />
      </div>

      <section className="bg-page py-20">
        <div className="mx-auto max-w-7xl px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
          <div>
            <h2 className="text-4xl md:text-5xl font-extrabold text-navy leading-tight">
              About Our SAKA Property
            </h2>
            <p className="mt-6 text-muted-foreground leading-relaxed">
              Property management involves daily oversight, control, and maintenance of real estate
              (residential, commercial, industrial) on behalf of owners, focusing on tenant
              relations, rent collection, repairs, legal compliance, and maximizing property value
              and profitability. It is a comprehensive service that handles everything from marketing
              vacancies and screening tenants to routine upkeep and financial reporting, essentially
              acting as a liaison between owners and renters to ensure smooth operations and asset
              protection.
            </p>
            <Link
              href="/about"
              className="mt-8 inline-flex items-center gap-2 rounded-full bg-[#061C3F] pl-6 pr-2 py-2 text-white font-semibold tap-scale hover:shadow-xl"
            >
              Learn More
              <span className="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
                <ArrowUpRight className="h-4 w-4" />
              </span>
            </Link>
          </div>

          {/*
            The play button that used to sit over this image has been removed.
            It had no handler and there is no video anywhere in the product to
            give it one — it rendered a 20px focus ring and an animated call to
            action over the whole panel and did nothing when pressed, which is
            worse for a screen-reader user than for anyone else: it was
            announced as "Play video, button" and was not.

            The image and its frame are unchanged.
          */}
          <div className="relative rounded-3xl overflow-hidden border border-border bg-white p-4 lift-card">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1400&q=80"
              alt="A residential building in Dar es Salaam"
              loading="lazy"
              className="rounded-2xl w-full h-[420px] object-cover zoom-img"
            />
          </div>
        </div>
      </section>

      <Faq faqs={faqs.data} />
    </>
  );
}
