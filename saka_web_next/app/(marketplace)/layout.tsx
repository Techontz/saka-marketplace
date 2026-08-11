import type { ReactNode } from "react";

import { Footer } from "@/components/layout/Footer";
import { Header } from "@/components/layout/Header";
import { ADSENSE_CLIENT, ADSENSE_ENABLED } from "@/lib/config";
import { AuthDialogProvider } from "@/providers/AuthDialogProvider";
import { AuthProvider } from "@/providers/AuthProvider";
import { FavoritesProvider } from "@/providers/FavoritesProvider";
import { LocationProvider } from "@/providers/LocationProvider";
import { LocationWelcome } from "@/components/location/LocationWelcome";
import { QueryProvider } from "@/providers/QueryProvider";

/**
 * The marketplace chrome — header, footer, and the provider chain the storefront
 * needs. Unchanged from when this was the root layout; it only moved down one
 * level.
 *
 * It had to move. /vendor and /admin are their own applications with their own
 * shells, and a root layout that renders the marketplace Header would have
 * stamped it across both portals. A route group keeps the URLs identical —
 * `(marketplace)` contributes nothing to the path — while giving the storefront
 * a layout the portals do not inherit.
 */


export default function MarketplaceLayout({ children }: { children: ReactNode }) {
  return (
    <>
      {ADSENSE_ENABLED && (
        <script
          async
          src={`https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${ADSENSE_CLIENT}`}
          crossOrigin="anonymous"
        />
      )}
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
    </>
  );
}
