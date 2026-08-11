import type { Metadata } from "next";
import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import { SearchHero } from "@/components/search/SearchHero";
import { getPlaceCategories, getPlaces } from "@/lib/api/public";
import { SafeImage } from "@/components/ui/SafeImage";

export const metadata: Metadata = {
  /*
   * Canonical URL.
   *
   * `metadataBase` in the root layout supplies the origin, so a relative path
   * is enough. Without this, every filtered and sorted variant of a listing
   * page — ?category=…&sort=…&page=2 — is a separate indexable URL competing
   * with the same content, which is how a catalogue site dilutes its own
   * ranking.
   */
  alternates: { canonical: "/public-places" },
  title: "Public Places",
  description: "Browse hospitals, banks, schools, hotels and more across Tanzania.",
};

// Renders `place_count` from Class A `getPlaceCategories()`. See the note on
// the same constant in app/listings/page.tsx for why this is 0 and not 3600.
export const revalidate = 0;

/** The public-places index, ported. Categories now come from the CMS. */
export default async function PublicPlacesPage() {
  const [categories, places] = await Promise.all([
    getPlaceCategories().catch(() => ({ data: [] })),
    getPlaces({ per_page: 1 }).catch(() => null),
  ]);

  return (
    <>
      {/*
        The index has no single category, so it borrows the first published
        one's artwork rather than hardcoding a stock URL. Falls back to the
        shared default when the CMS has nothing yet.
      */}
      <SearchHero
        title="Public Places"
        trail={["Public Places"]}
        image={categories.data[0]?.image_url ?? null}
      />

      <section className="bg-page py-10">
        <div className="mx-auto max-w-7xl px-4">
          {categories.data.length === 0 ? (
            <div className="rounded-[5px] border border-dashed border-border bg-white p-12 text-center text-muted-foreground">
              No public places have been published yet.
            </div>
          ) : (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
              {categories.data.map((category) => (
                <Link
                  key={category.slug}
                  href={`/public-places/${category.slug}`}
                  className="group bg-white border border-border rounded-[5px] overflow-hidden lift-card tap-scale hover:border-teal"
                >
                  <div className="h-40 overflow-hidden">
                    <SafeImage
                      src={category.image_url}
                      alt={category.name}
                      className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
                      fallbackClassName="h-full w-full bg-page text-5xl"
                      fallback={category.icon ?? "📍"}
                    />
                  </div>
                  <div className="p-4 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 rounded-[5px] bg-page flex items-center justify-center text-xl">
                        {category.icon ?? "📍"}
                      </div>
                      <div>
                        <div className="font-bold text-navy text-sm">{category.name}</div>
                        <div className="text-xs text-muted-foreground">
                          {category.place_count.toLocaleString()} nearby
                        </div>
                      </div>
                    </div>
                    <span className="h-8 w-8 rounded-full bg-teal text-white flex items-center justify-center">
                      <ArrowUpRight className="h-4 w-4" />
                    </span>
                  </div>
                </Link>
              ))}
            </div>
          )}

          {places && (
            <p className="mt-8 text-center text-sm text-muted-foreground">
              {places.meta.total.toLocaleString()} places listed across Tanzania.
            </p>
          )}
        </div>
      </section>
    </>
  );
}
