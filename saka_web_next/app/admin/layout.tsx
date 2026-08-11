import type { Metadata } from "next";

import { AuthProvider } from "@/providers/admin/AuthProvider";
import { QueryProvider } from "@/providers/admin/QueryProvider";

export const metadata: Metadata = {
  title: "SAKA Admin",
  description: "Administration portal for the SAKA marketplace.",
  // An admin portal must never be indexed. The API also refuses anonymous
  // access to every endpoint behind it, but a crawler finding a login page and
  // listing it in search results is its own small problem.
  robots: { index: false, follow: false, nocache: true },
};

/**
 * The admin portal's shell. Was its own root layout until the three web apps
 * moved into one deployment; the providers and their order are unchanged.
 *
 * See the vendor layout for why `theme-admin` is here: both portals define the
 * same design-token names with different values, so the values are scoped by
 * class rather than fighting over one global `@theme`.
 */
export default function AdminLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="theme-admin bg-canvas text-ink min-h-screen">
      <QueryProvider>
        <AuthProvider>{children}</AuthProvider>
      </QueryProvider>
    </div>
  );
}
