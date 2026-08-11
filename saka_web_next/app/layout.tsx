import type { Metadata } from "next";
import type { ReactNode } from "react";

import { Footer } from "@/components/layout/Footer";
import { Header } from "@/components/layout/Header";
import { ADSENSE_CLIENT, ADSENSE_ENABLED, SITE_URL } from "@/lib/config";
import { AuthDialogProvider } from "@/providers/AuthDialogProvider";
import { AuthProvider } from "@/providers/AuthProvider";
import { FavoritesProvider } from "@/providers/FavoritesProvider";
import { LocationProvider } from "@/providers/LocationProvider";
import { LocationWelcome } from "@/components/location/LocationWelcome";
import { QueryProvider } from "@/providers/QueryProvider";

import "./globals.css";

/**
 * The shell, ported from the original `__root.tsx`.
 *
 * Same chrome, same order, same `pt-20` under a fixed 80px header. The fonts
 * are still Urbanist from Google Fonts, preconnected exactly as before.
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

        {/*
          * Google AdSense, loaded ONLY when a publisher id is configured.
          *
          * A plain <script async>, not next/script: this has to be in <head>
          * with the crossOrigin attribute AdSense requires, and it must be
          * absent entirely — not merely inert — when ads are off. A deployment
          * with no id emits no tag, makes no request to Google, and keeps the
          * narrower CSP (see next.config.ts).
          *
          * `async` rather than `defer`: the units below the fold are not
          * render-blocking either way, and AdSense's own guidance is async.
          */}
        {ADSENSE_ENABLED && (
          <script
            async
            src={`https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${ADSENSE_CLIENT}`}
            crossOrigin="anonymous"
          />
        )}
      </head>
      <body>
        <QueryProvider>
          <AuthProvider>
            <FavoritesProvider>
              <AuthDialogProvider>
                {/*
                  LocationProvider sits INSIDE the auth chain but outside the
                  page, because "where am I browsing from" outlives any single
                  route and the header's location chip reads it too.
                */}
                <LocationProvider>
                  <div className="flex min-h-screen flex-col bg-page">
                    <Header />
                    <main className="flex-1 pt-20">{children}</main>
                    <Footer />
                  </div>

                  {/* Renders nothing once a choice has been recorded. */}
                  <LocationWelcome />
                </LocationProvider>
              </AuthDialogProvider>
            </FavoritesProvider>
          </AuthProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
