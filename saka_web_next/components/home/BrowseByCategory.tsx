"use client";

import Link from "next/link";
import { useRef, useState } from "react";
import { ArrowUpRight } from "lucide-react";

import type { ApiCategory } from "@/lib/types";
import { SafeImage } from "@/components/ui/SafeImage";

/**
 * "Browse by Category", ported from the original.
 *
 * Every dimension is unchanged — the tab strip with its two rounded notch
 * pseudo-corners, the #EEF4FF tray, the 4-column subcategory grid. What changed
 * is the source: the verticals and their children now come from the catalog
 * API, and the per-subcategory count is the API's own `listing_count` rather
 * than a length of a hardcoded array.
 */
export function BrowseByCategory({ categories }: { categories: ApiCategory[] }) {
  const [selected, setSelected] = useState(categories[0]);
  const categoryScrollRef = useRef<HTMLDivElement>(null);

  if (!selected) return null;

  const scrollCategories = (direction: "left" | "right") => {
    categoryScrollRef.current?.scrollBy({
      left: direction === "left" ? -320 : 320,
      behavior: "smooth",
    });
  };

  const children = selected.children ?? [];

  return (
    <section className="bg-white pt-8 pb-8 md:pt-10 md:pb-10">
      <div className="mx-auto max-w-7xl px-5 md:px-6">
        <div className="mb-7 flex items-start justify-between">
          <div>
            <h2 className="text-[22px] sm:text-[28px] md:text-[36px] lg:text-[40px] leading-tight font-extrabold tracking-[-0.03em] text-[#061C3F]">
              Browse by Category
            </h2>
            <p className="mt-2 text-[13px] sm:text-[14px] md:text-[15px] text-[#8B95A7]">
              Select a category to explore subcategories
            </p>
          </div>

          <div className="hidden md:flex items-center gap-3">
            <button
              onClick={() => scrollCategories("left")}
              aria-label="Scroll categories left"
              className="h-11 w-11 rounded-full border border-[#E8EDF4] bg-white flex items-center justify-center hover:border-[#061C3F] hover:shadow-md transition"
            >
              ←
            </button>
            <button
              onClick={() => scrollCategories("right")}
              aria-label="Scroll categories right"
              className="h-11 w-11 rounded-full border border-[#E8EDF4] bg-white flex items-center justify-center hover:border-[#061C3F] hover:shadow-md transition"
            >
              →
            </button>
          </div>
        </div>

        <div className="relative mt-6 rounded-[15px] bg-[#EEF4FF] overflow-hidden">
          <div
            ref={categoryScrollRef}
            className="flex items-end gap-1.5 md:gap-2 px-3 md:px-5 pt-3 md:pt-5 overflow-x-auto whitespace-nowrap"
          >
            {categories.map((item) => {
              const isSelected = selected.slug === item.slug;

              return (
                <button
                  key={item.slug}
                  onClick={() => setSelected(item)}
                  className={`relative flex items-center gap-2 sm:gap-2.5 md:gap-3 h-[46px] sm:h-[52px] md:h-[58px] px-4 sm:px-5 md:px-6 shrink-0 rounded-t-[18px] rounded-b-none ${
                    isSelected
                      ? "bg-white text-[#2563EB] z-30 translate-y-[1px] shadow-[0_-8px_24px_rgba(0,0,0,0.06)]"
                      : "bg-transparent text-[#0F2B46] hover:bg-white/35"
                  }`}
                >
                  <span className="text-[18px] sm:text-[20px] md:text-[22px]">{item.icon ?? "🏷️"}</span>
                  <span className="shrink-0 text-[12px] sm:text-[14px] md:text-[15px] font-semibold">
                    {item.name}
                  </span>

                  {isSelected && (
                    <>
                      <div className="absolute bottom-0 left-[-18px] w-[18px] h-[18px] rounded-br-[18px] shadow-[9px_9px_0_9px_white] pointer-events-none" />
                      <div className="absolute bottom-0 right-[-18px] w-[18px] h-[18px] rounded-bl-[18px] shadow-[-9px_9px_0_9px_white] pointer-events-none" />
                    </>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        <div className="mt-7 rounded-xl border border-[#E8EDF4] bg-white shadow-[0_8px_24px_rgba(6,28,63,0.05)] p-4 sm:p-5">
          <div className="grid lg:grid-cols-12 gap-8">
            <div className="lg:col-span-3">
              <SafeImage
                src={selected.image_url}
                alt={selected.name}
                className="w-full h-[170px] sm:h-[200px] lg:h-[220px] rounded-lg object-cover"
                fallbackClassName="w-full h-[170px] sm:h-[200px] lg:h-[220px] rounded-lg text-5xl"
                fallback={selected.icon ?? "🏷️"}
              />
            </div>

            <div className="lg:col-span-9">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-4">
                  <h3 className="text-[22px] sm:text-[26px] lg:text-[30px] font-bold text-[#061C3F] tracking-[-0.02em]">
                    {selected.name}
                  </h3>
                  <span className="rounded-full bg-[#FFF4DB] text-[#C79A2B] text-[10px] sm:text-[11px] md:text-[12px] font-semibold px-3 py-1">
                    {children.length} Subcategories
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-2 xl:grid-cols-4 gap-4 mt-6">
                {children.map((sub) => (
                  <Link
                    key={sub.slug}
                    href={`/listings?category=${encodeURIComponent(selected.slug)}&subcategory=${encodeURIComponent(sub.slug)}`}
                    className="h-[64px] sm:h-[70px] md:h-[78px] rounded-lg border border-[#E8EDF4] bg-white px-3 sm:px-4 flex items-center justify-between transition-all duration-200 hover:border-[#061C3F] hover:shadow-[0_8px_20px_rgba(6,28,63,0.08)] cursor-pointer"
                  >
                    <div>
                      <h4 className="text-[13px] sm:text-[14px] md:text-[15px] font-semibold text-[#061C3F]">
                        {sub.name}
                      </h4>
                      <p className="text-[13px] text-[#8B95A7] mt-0.5">
                        {sub.listing_count.toLocaleString()} Listings
                      </p>
                    </div>
                    <ArrowUpRight className="h-4 w-4 text-[#8B95A7]" />
                  </Link>
                ))}

                {children.length === 0 && (
                  <p className="col-span-full text-[14px] text-[#8B95A7]">
                    Nothing listed under {selected.name} yet.
                  </p>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
