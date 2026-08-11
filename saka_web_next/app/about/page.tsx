import type { Metadata } from "next";
import Link from "next/link";
import { ArrowUpRight, Play } from "lucide-react";

import { DEVELOPER, SHOW_DEVELOPER_CREDIT } from "@/lib/build-info";
import { getBusinesses, getCategoryTaxonomy, getPlaces } from "@/lib/api/public";

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
  alternates: { canonical: "/about" },
  title: "About Us",
  description: "Learn about SAKA, our mission, and our team of real estate professionals.",
};

/**
 * Editorial copy, plus three numbers that are actually true.
 *
 * The stats block used to read "500+ Listings Listed / 120+ Cities Covered /
 * 10k+ Happy Clients". None of those came from anywhere: they were the
 * template's placeholders, sitting on the page that exists to establish that
 * the business is real. They now come from the catalogue, and the labels say
 * what is being counted rather than implying a customer base we cannot
 * evidence.
 *
 * If the API is unreachable the section is omitted rather than falling back to
 * invented figures — an About page with two sections is better than one with a
 * confident lie.
 */
export default async function AboutPage() {
  const [categories, businesses, places] = await Promise.all([
    /*
     * The cached tree. This page shows a headline total ("199 listings on
     * SAKA"), which is a marketing figure rather than a number anyone acts on
     * — an hour old is fine, and it keeps the About page static.
     */
    getCategoryTaxonomy().catch(() => ({ data: [] })),
    getBusinesses({ per_page: 1 }).catch(() => null),
    getPlaces({ per_page: 1 }).catch(() => null),
  ]);

  const listingTotal = categories.data.reduce(
    // Root categories only: their counts already include every subcategory, so
    // summing children as well would double every listing.
    (total, category) => total + (category.listing_count ?? 0),
    0,
  );

  const stats = [
    listingTotal > 0
      ? { value: listingTotal.toLocaleString(), label: "Listings on SAKA" }
      : null,
    businesses?.meta?.total
      ? { value: businesses.meta.total.toLocaleString(), label: "Verified businesses" }
      : null,
    places?.meta?.total
      ? { value: places.meta.total.toLocaleString(), label: "Places in the directory" }
      : null,
  ].filter((stat): stat is { value: string; label: string } => stat !== null);

  return (
    <>
      <section className="bg-white py-20">
        <div className="mx-auto max-w-7xl px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
          <div>
            <h1 className="text-4xl md:text-6xl font-extrabold text-navy leading-tight">
              About <span className="text-teal">S</span>
              <span className="text-orange">A</span>KA Property
            </h1>
            <p className="mt-6 text-muted-foreground leading-relaxed">
              Property management involves daily oversight, control, and maintenance of real estate
              (residential, commercial, industrial) on behalf of owners, focusing on tenant
              relations, rent collection, repairs, legal compliance, and maximizing property value
              and profitability.
            </p>
            <p className="mt-4 text-muted-foreground leading-relaxed">
              SAKA is a comprehensive service that handles everything from marketing vacancies and
              screening tenants to routine upkeep and financial reporting — a liaison between owners
              and renters to ensure smooth operations and asset protection.
            </p>
            <Link
              href="/contact"
              className="mt-8 inline-flex items-center gap-2 rounded-full bg-teal pl-6 pr-2 py-2 text-white font-semibold"
            >
              Contact Us
              <span className="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-white text-teal">
                <ArrowUpRight className="h-4 w-4" />
              </span>
            </Link>
          </div>

          <div className="relative rounded-3xl overflow-hidden border border-border bg-white p-4">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1400&q=80"
              className="rounded-2xl w-full h-[460px] object-cover"
              alt="Building"
            />
            <span className="absolute inset-0 flex items-center justify-center">
              <span className="h-20 w-20 rounded-full bg-white/95 flex items-center justify-center text-teal shadow-xl">
                <Play className="h-8 w-8 fill-current ml-1" />
              </span>
            </span>
          </div>
        </div>
      </section>

      {stats.length > 0 && (
        <section className="bg-page py-20">
          <div className="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-6 md:grid-cols-3">
            {stats.map((stat) => (
              <div key={stat.label} className="rounded-2xl border border-border bg-white p-8 text-center">
                <div className="text-5xl font-extrabold text-teal">{stat.value}</div>
                <div className="mt-2 font-medium text-muted-foreground">{stat.label}</div>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* --------------------------------------------- technology partner --- */}
      {SHOW_DEVELOPER_CREDIT && (
        <section className="bg-white py-16">
          <div className="mx-auto max-w-7xl px-6">
            <div className="rounded-2xl border border-border bg-page p-8 md:flex md:items-center md:justify-between md:gap-8">
              <div>
                <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-muted-foreground">
                  Technology partner
                </p>
                <h2 className="mt-2 text-2xl font-extrabold text-navy">{DEVELOPER.name}</h2>
                <p className="mt-2 max-w-xl text-sm text-muted-foreground">
                  SAKA is designed, built and maintained by {DEVELOPER.name}. Platform issues and
                  integration enquiries go to them; anything about a listing or an account is
                  handled by the SAKA team.
                </p>
              </div>

              <div className="mt-6 flex flex-wrap gap-3 md:mt-0 md:shrink-0">
                <a
                  href={DEVELOPER.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-2 rounded-full border-2 border-border bg-white px-5 py-2.5 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
                >
                  Visit {DEVELOPER.name.split(" ")[0]}
                  <ArrowUpRight className="h-4 w-4" />
                </a>
                <a
                  href={`mailto:${DEVELOPER.supportEmail}`}
                  className="inline-flex items-center gap-2 rounded-full border-2 border-border bg-white px-5 py-2.5 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
                >
                  Technical support
                </a>
              </div>
            </div>
          </div>
        </section>
      )}
    </>
  );
}
