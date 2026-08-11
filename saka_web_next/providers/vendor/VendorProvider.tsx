"use client";

import { useQuery } from "@tanstack/react-query";
import { createContext, useContext, useMemo, type ReactNode } from "react";

import { apiGet } from "@/lib/vendor/api/browser";
import type { BusinessType, OnboardingProgress, VendorProfile } from "@/lib/vendor/api/types";
import { useAuth } from "./AuthProvider";

/**
 * The vendor's business profile, and the per-vertical rules that follow from it.
 *
 * Loaded once and shared. Nearly every screen needs at least one of: the
 * business type's noun for a listing, whether opening hours apply, or how far
 * through onboarding the vendor is — and fetching that per screen would be
 * three requests on every navigation.
 *
 * `businessType` is the API's own descriptor, never a local table. That is what
 * keeps "a hotel calls them rooms" defined in exactly one place.
 */

type VendorContextValue = {
  profile: VendorProfile | null;
  progress: OnboardingProgress | null;
  businessType: BusinessType | null;
  isLoading: boolean;
  error: unknown;
  /** ["room", "rooms"], falling back to the generic noun. */
  noun: { singular: string; plural: string };
  refetch: () => void;
};

const VendorContext = createContext<VendorContextValue | null>(null);

export const PROFILE_QUERY_KEY = ["vendor", "profile"] as const;

const DEFAULT_NOUN = { singular: "listing", plural: "listings" };

export function VendorProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth();

  const query = useQuery({
    queryKey: PROFILE_QUERY_KEY,
    queryFn: () =>
      apiGet<{ data: VendorProfile; meta: { progress: OnboardingProgress; business_type: BusinessType | null } }>(
        "/seller/vendor-profile",
      ),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const value = useMemo<VendorContextValue>(
    () => ({
      profile: query.data?.data ?? null,
      progress: query.data?.meta.progress ?? null,
      businessType: query.data?.meta.business_type ?? null,
      isLoading: query.isPending && isAuthenticated,
      error: query.error,
      noun: query.data?.meta.business_type?.listing_noun ?? DEFAULT_NOUN,
      refetch: () => void query.refetch(),
    }),
    [query, isAuthenticated],
  );

  return <VendorContext.Provider value={value}>{children}</VendorContext.Provider>;
}

export function useVendor(): VendorContextValue {
  const context = useContext(VendorContext);
  if (!context) throw new Error("useVendor must be used inside <VendorProvider>.");
  return context;
}
