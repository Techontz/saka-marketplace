import type { Metadata } from "next";
import Link from "next/link";
import { ArrowUpRight } from "lucide-react";

import { AdSlot } from "@/components/ads/AdSlot";
import { ListingCard } from "@/components/listings/ListingCard";
import { SearchHero } from "@/components/search/SearchHero";
import { getCategories, getListings } from "@/lib/api/public";
import { toListingView } from "@/lib/view-models";

export const metadata: Metadata = {
  title: "Specialists",
  description:
    "Find lawyers, teachers, engineers, architects, accountants, developers and other professionals across Tanzania on SAKA. Compare, contact and book.",
  alternates: { canonical: "/specialists" },
  openGraph: {
    title: "Specialists on SAKA",
    description: "Professionals across Tanzania — compare, contact and book.",
    type: "website",
  },
};

/*
 * Live counts, so the route is dynamic.
 *
 * The category tiles render "N professionals", which changes every time a
 * specialist is published. See the note in lib/api/public.ts about why a count
 * is never cached on this side.
 */
export const revalidate = 0;

/**
 * The specialist directory.
 *
 * Deliberately a DIRECTORY rather than a second search page. Browsing,
 * filtering and location search for specialists all run through
 * `/listings?category=specialists`, which already has the category-aware EAV
 * filters — a lawyer is filtered by practice area and a teacher by subject
 * because the taxonomy says so, with no conditionals here.
 *
 * What this page adds is the way in: the professions, with real counts, and the
 * most recently published specialists.
 */
export default async function SpecialistsPage() {
  const [categories, recent] = await Promise.all([
    getCategories().catch(() => ({ data: [] })),
    // Newest first — a directory's job is to show the platform is alive.
    getListings({ category: "specialists", per_page: 8, sort: "newest" }).catch(() => ({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, from: null, to: null },
    })),
  ]);

  const vertical = categories.data.find((category) => category.slug === "specialists");
  const professions = vertical?.children ?? [];
  const specialists = recent.data.map(toListingView);

  return (
    <div className="bg-page min-h-screen">
      <SearchHero
        title="Specialists"
        trail={["Specialists"]}
        image={vertical?.image_url ?? null}
      />

      <section className="mx-auto max-w-7xl px-6 py-12">
        <div className="mb-8">
          <h2 className="text-3xl font-extrabold text-navy md:text-4xl">Browse by profession</h2>
          <p className="mt-3 text-muted-foreground">
            Lawyers, tutors, engineers and more — verified professionals across Tanzania.
          </p>
        </div>

        {professions.length === 0 ? (
          <p className="rounded-[8px] border border-border bg-white px-5 py-8 text-center text-muted-foreground">
            No specialist categories are published yet.
          </p>
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {professions.map((profession) => (
              <Link
                key={profession.slug}
                href={`/listings?category=specialists&subcategory=${profession.slug}`}
                className="group flex flex-col rounded-[8px] border border-[#DCE6EF] bg-white p-4 transition-all duration-300 hover:-translate-y-1 hover:border-[#0B8E95]/40 hover:shadow-[0_18px_40px_-18px_rgba(6,28,63,0.28)]"
              >
                <span aria-hidden="true" className="text-2xl">
                  {profession.icon ?? "🎓"}
                </span>
                <span className="mt-2 text-[15px] font-bold text-[#17233C] transition-colors group-hover:text-[#0B8E95]">
                  {profession.name}
                </span>
                {/*
                  * A real count from the taxonomy, refreshed hourly by
                  * `saka:taxonomy:recount`. Zero is shown as "None yet" rather
                  * than "0 professionals", which reads as broken.
                  */}
                <span className="mt-1 text-[12px] text-[#6B7280]">
                  {profession.listing_count > 0
                    ? `${profession.listing_count} professional${profession.listing_count === 1 ? "" : "s"}`
                    : "None yet"}
                </span>
              </Link>
            ))}
          </div>
        )}
      </section>

      {/* Between sections, never inside one. Collapses when unsold. */}
      <div className="mx-auto w-full max-w-7xl px-6">
        <AdSlot placement="specialists" className="pb-8" />
      </div>

      {specialists.length > 0 && (
        <section className="bg-white py-14">
          <div className="mx-auto max-w-7xl px-6">
            <div className="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
              <div>
                <h2 className="text-3xl font-extrabold text-navy md:text-4xl">
                  Recently joined
                </h2>
                <p className="mt-3 text-muted-foreground">
                  Professionals who have just published their profile.
                </p>
              </div>

              <Link
                href="/listings?category=specialists"
                className="inline-flex items-center gap-2 self-start rounded-full bg-[#061C3F] py-2 pl-6 pr-2 font-semibold text-white tap-scale hover:shadow-xl md:self-auto"
              >
                Browse all specialists
                <span className="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
                  <ArrowUpRight className="h-4 w-4" />
                </span>
              </Link>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:gap-6 md:grid-cols-3 xl:grid-cols-4">
              {specialists.map((specialist) => (
                <ListingCard key={specialist.slug} p={specialist} />
              ))}
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
