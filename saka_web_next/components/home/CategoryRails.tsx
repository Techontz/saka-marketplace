import { DiscoveryRail, RailItem } from "@/components/home/DiscoveryRail";
import { ListingCard } from "@/components/listings/ListingCard";
import { getListings } from "@/lib/api/public";
import type { ApiCategory } from "@/lib/types";
import { toListingView } from "@/lib/view-models";

/**
 * "Explore by category" — one rail per vertical, built from the live taxonomy.
 *
 * Nothing here is hardcoded. The verticals, their names and their listing
 * counts all come from `/categories`, so a vertical an administrator adds
 * tomorrow gets a rail without a deploy, and one that empties out loses its
 * rail the same way.
 */

/**
 * How many rails the homepage carries.
 *
 * Not "all of them". Production has thirteen verticals; thirteen near-identical
 * strips is a page nobody reaches the bottom of, and the ones at the end are
 * seen by no one. Four is enough to establish that SAKA is broad without
 * turning the homepage into a directory.
 */
const MAX_RAILS = 4;

/**
 * Below this a rail cannot fill itself and looks like a mistake rather than a
 * small category. Those verticals are still reachable from the category
 * browser above — they just do not get a homepage strip.
 */
const MIN_LISTINGS = 4;

export async function CategoryRails({ categories }: { categories: ApiCategory[] }) {
  const chosen = [...categories]
    .filter((category) => (category.listing_count ?? 0) >= MIN_LISTINGS)
    .sort((a, b) => (b.listing_count ?? 0) - (a.listing_count ?? 0))
    .slice(0, MAX_RAILS);

  if (chosen.length === 0) return null;

  /*
   * One request per rail, in parallel, each contained.
   *
   * `newest` rather than the API's default ordering: a homepage rail that
   * never changes is one a returning visitor learns to skip.
   */
  const rails = await Promise.all(
    chosen.map(async (category) => ({
      category,
      listings: await getListings({ category: category.slug, per_page: 8, sort: "newest" })
        .then((page) => page.data.map(toListingView))
        .catch(() => []),
    })),
  );

  return (
    <>
      {rails.map(({ category, listings }, index) =>
        listings.length === 0 ? null : (
          <DiscoveryRail
            key={category.slug}
            title={category.name}
            subtitle={
              category.listing_count
                ? `${category.listing_count.toLocaleString()} on SAKA`
                : undefined
            }
            href={`/listings?category=${category.slug}`}
            // Alternate the ground so consecutive rails do not merge into one
            // long scroll with headings floating in it.
            tone={index % 2 === 0 ? "white" : "page"}
          >
            {listings.map((listing) => (
              <RailItem key={listing.slug}>
                <ListingCard p={listing} />
              </RailItem>
            ))}
          </DiscoveryRail>
        ),
      )}
    </>
  );
}
