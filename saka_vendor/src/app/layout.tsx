import type { Metadata } from "next";

import "./globals.css";
import { AuthProvider } from "@/providers/AuthProvider";
import { QueryProvider } from "@/providers/QueryProvider";
import { VendorProvider } from "@/providers/VendorProvider";

export const metadata: Metadata = {
  title: "SAKA for Business",
  description: "Manage your business on the SAKA marketplace.",
  // Not indexable: this is a private tool, and the public storefront is the
  // marketplace's job.
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        {/*
          VendorProvider sits inside AuthProvider because the profile query is
          gated on being signed in. Neither renders markup.
        */}
        <QueryProvider>
          <AuthProvider>
            <VendorProvider>{children}</VendorProvider>
          </AuthProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
