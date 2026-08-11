import type { Metadata } from "next";

import "./globals.css";
import { AuthProvider } from "@/providers/AuthProvider";
import { QueryProvider } from "@/providers/QueryProvider";

export const metadata: Metadata = {
  title: "SAKA Admin",
  description: "Administration portal for the SAKA marketplace.",
  // An admin portal must never be indexed. The API also refuses anonymous
  // access to every endpoint behind it, but a crawler finding a login page and
  // listing it in search results is its own small problem.
  robots: { index: false, follow: false, nocache: true },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <QueryProvider>
          <AuthProvider>{children}</AuthProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
