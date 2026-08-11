import type { Metadata } from "next";
import type { ReactNode } from "react";

import { SITE_URL } from "@/lib/config";

import "./globals.css";

/**
 * The document, and nothing else.
 *
 * Three applications share this file — the marketplace at `/`, the vendor
 * portal at `/vendor` and the admin portal at `/admin` — so it holds only what
 * all three genuinely share: the html element, the stylesheet and the font.
 * Chrome belongs to whichever shell is below it.
 *
 * The marketplace header and footer used to live here, back when this was the
 * only app in the project. They now sit in `(marketplace)/layout.tsx`; leaving
 * them here would have painted the storefront's navigation across both portals.
 *
 * Urbanist is loaded here rather than per-area because the portals use it too,
 * and a second copy fetched under `/vendor` would be a second network request
 * for a font the browser already has.
 */
export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: {
    default: "SAKA — Search listings with Sale and Rent in the World",
    template: "%s | SAKA",
  },
  description:
    "SAKA is a real estate platform to search, list, buy, sell, rent and lease listings worldwide.",
  openGraph: {
    title: "SAKA — Real Estate Marketplace",
    description: "Search listings with Sale and Rent in the World.",
    type: "website",
    url: SITE_URL,
  },
  twitter: { card: "summary_large_image" },
  /*
   * A self-referencing canonical for the homepage, which has no page-level
   * metadata of its own and therefore inherits this. Pages that set their own
   * `alternates` override it.
   */
  alternates: { canonical: "/" },
  /*
   * No explicit `icons` entry.
   *
   * This used to pin the icon to "/favicon.ico" — the stock Next.js triangle —
   * which overrode the file convention and meant `app/icon.png` and
   * `app/apple-icon.png` were generated but never linked. Removing it lets
   * Next emit both, at the right sizes and types, from the SAKA monogram.
   *
   * `public/favicon.ico` is still shipped for clients that request that path
   * by convention without reading the link tags.
   */
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap"
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
