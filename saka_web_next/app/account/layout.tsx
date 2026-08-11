"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";

import { AccountHeader } from "@/components/account/AccountHeader";
import { BrandedLoader } from "@/components/ui/states";
import { useAuth } from "@/providers/AuthProvider";

/**
 * The signed-in account area.
 *
 * The guard is client-side because the session lives in an httpOnly cookie the
 * layout cannot read without becoming dynamic — and every page beneath it is
 * per-customer anyway. Nothing is rendered until the session resolves, so a
 * signed-out visitor never sees a flash of someone's account chrome.
 *
 * The sidebar nav that used to live here now sits inside <AccountHeader>,
 * beside the avatar and the counts. Two reasons: the account area was the only
 * part of the site that did not open with the marketplace's photographic hero,
 * so it read as a different product; and on a phone the sidebar rendered as six
 * links stacked ABOVE the content, so opening Notifications meant scrolling
 * past the navigation to reach the notifications.
 */
export default function AccountLayout({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [isLoading, isAuthenticated, router, pathname]);

  if (isLoading) return <BrandedLoader label="Loading your account…" />;
  if (!isAuthenticated) return null;

  return (
    <div className="min-h-screen bg-page">
      <AccountHeader />

      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-10">{children}</div>
    </div>
  );
}
