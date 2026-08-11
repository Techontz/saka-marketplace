"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { createContext, useContext, useMemo, type ReactNode } from "react";

import { apiGet, apiSend } from "@/lib/api/browser";
import type { ApiBusiness, ApiListing, Paginated } from "@/lib/types";
import { useAuth } from "./AuthProvider";

/**
 * Which listings and businesses this customer has saved.
 *
 * Held once, centrally, because a heart appears on every card on every page.
 * Fetching per card would be one request per tile; deriving it from the card's
 * own payload is impossible because the public listing response is shared and
 * cacheable, and therefore cannot carry a per-viewer flag.
 *
 * Toggling updates the local set FIRST and rolls back if the request fails: a
 * heart that waits for a round trip before filling feels broken, and this is
 * the single most-tapped control on the site.
 */

type FavoritesContextValue = {
  listingSlugs: Set<string>;
  businessSlugs: Set<string>;
  isReady: boolean;
  isListingSaved: (slug: string) => boolean;
  isBusinessSaved: (slug: string) => boolean;
  toggleListing: (slug: string) => Promise<void>;
  toggleBusiness: (slug: string) => Promise<void>;
};

const FavoritesContext = createContext<FavoritesContextValue | null>(null);

export const FAVORITE_LISTINGS_KEY = ["favorites", "listings"] as const;
export const FAVORITE_BUSINESSES_KEY = ["favorites", "businesses"] as const;

export function FavoritesProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth();
  const queryClient = useQueryClient();

  const listings = useQuery({
    queryKey: FAVORITE_LISTINGS_KEY,
    queryFn: () => apiGet<Paginated<ApiListing>>("/account/favorites/listings", { per_page: 100 }),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const businesses = useQuery({
    queryKey: FAVORITE_BUSINESSES_KEY,
    queryFn: () => apiGet<Paginated<ApiBusiness>>("/account/favorites/businesses", { per_page: 100 }),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const listingSlugs = useMemo(
    () => new Set((listings.data?.data ?? []).map((listing) => listing.slug)),
    [listings.data],
  );

  const businessSlugs = useMemo(
    () => new Set((businesses.data?.data ?? []).map((business) => business.slug)),
    [businesses.data],
  );

  const toggle = useMutation({
    mutationFn: async ({ kind, slug, saved }: { kind: "listing" | "business"; slug: string; saved: boolean }) => {
      const path =
        kind === "listing" ? `/account/favorites/${slug}` : `/account/favorites/businesses/${slug}`;

      await apiSend(path, saved ? "DELETE" : "POST");
    },
    onSettled: async (_data, _error, variables) => {
      await queryClient.invalidateQueries({
        queryKey: variables.kind === "listing" ? FAVORITE_LISTINGS_KEY : FAVORITE_BUSINESSES_KEY,
      });
    },
  });

  const value = useMemo<FavoritesContextValue>(
    () => ({
      listingSlugs,
      businessSlugs,
      isReady: !isAuthenticated || (!listings.isPending && !businesses.isPending),
      isListingSaved: (slug) => listingSlugs.has(slug),
      isBusinessSaved: (slug) => businessSlugs.has(slug),
      toggleListing: async (slug) => {
        await toggle.mutateAsync({ kind: "listing", slug, saved: listingSlugs.has(slug) });
      },
      toggleBusiness: async (slug) => {
        await toggle.mutateAsync({ kind: "business", slug, saved: businessSlugs.has(slug) });
      },
    }),
    [listingSlugs, businessSlugs, isAuthenticated, listings.isPending, businesses.isPending, toggle],
  );

  return <FavoritesContext.Provider value={value}>{children}</FavoritesContext.Provider>;
}

export function useFavorites(): FavoritesContextValue {
  const context = useContext(FavoritesContext);
  if (!context) throw new Error("useFavorites must be used inside <FavoritesProvider>.");
  return context;
}
