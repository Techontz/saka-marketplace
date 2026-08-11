import type { Metadata } from "next";
import { Suspense } from "react";

import { PlaceCategoryBrowser } from "@/components/places/PlaceCategoryBrowser";
import { SearchHero } from "@/components/search/SearchHero";
import { getPlaceCategoryTaxonomy } from "@/lib/api/public";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const categories = await getPlaceCategoryTaxonomy().catch(() => ({ data: [] }));
  const category = categories.data.find((item) => item.slug === slug);

  return {
    /* The slug route is this category's one true address. */
    alternates: { canonical: `/public-places/${slug}` },
    title: category?.name ?? "Public Places",
    description: category
      ? `Find ${category.name.toLowerCase()} near you, with businesses and listings nearby.`
      : undefined,
  };
}

/**
 * A place category: the places themselves, on a map, and — the point of it for
 * a marketplace — what is available NEAR the one you pick.
 */
export default async function PlaceCategoryPage({ params }: Props) {
  const { slug } = await params;
  const categories = await getPlaceCategoryTaxonomy().catch(() => ({ data: [] }));
  const category = categories.data.find((item) => item.slug === slug);

  const name = category?.name ?? "Public Places";

  return (
    <>
      <SearchHero
        title={name}
        trail={["Public Places", name]}
        image={category?.image_url ?? null}
      />

      <Suspense>
        <PlaceCategoryBrowser slug={slug} name={name} />
      </Suspense>
    </>
  );
}
