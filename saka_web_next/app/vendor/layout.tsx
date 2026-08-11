import type { Metadata } from "next";

import { AuthProvider } from "@/providers/vendor/AuthProvider";
import { QueryProvider } from "@/providers/vendor/QueryProvider";
import { VendorProvider } from "@/providers/vendor/VendorProvider";

export const metadata: Metadata = {
  title: "SAKA for Business",
  description: "Manage your business on the SAKA marketplace.",
  // Not indexable: this is a private tool, and the public storefront is the
  // marketplace's job.
  robots: { index: false, follow: false },
};

/**
 * The vendor portal's shell. Was its own root layout until the three web apps
 * moved into one deployment; the providers and their order are unchanged.
 *
 * `theme-vendor` is the one addition. The vendor and admin portals were built
 * with the SAME token names (`--color-brand`, `--color-canvas`, `--color-ink`…)
 * and deliberately different values — teal for vendors, blue for staff. Two
 * global `@theme` blocks in one stylesheet means one set of values wins and the
 * other portal silently renders in the wrong brand. Scoping the values to a
 * class here keeps both exactly as they were built.
 */
export default function VendorLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="theme-vendor bg-canvas text-ink min-h-screen">
      {/*
        VendorProvider sits inside AuthProvider because the profile query is
        gated on being signed in. Neither renders markup.
      */}
      <QueryProvider>
        <AuthProvider>
          <VendorProvider>{children}</VendorProvider>
        </AuthProvider>
      </QueryProvider>
    </div>
  );
}
