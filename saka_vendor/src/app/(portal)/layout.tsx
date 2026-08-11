"use client";

import { useQuery } from "@tanstack/react-query";
import { AlertTriangle, LogOut, Menu, X } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";

import { Sidebar } from "@/components/layout/Sidebar";
import { Logo } from "@/components/ui/Logo";
import { Button } from "@/components/ui";
import { apiGet } from "@/lib/api/browser";
import type { Envelope, VendorDashboard } from "@/lib/api/types";
import { useAuth } from "@/providers/AuthProvider";
import { useVendor } from "@/providers/VendorProvider";

/**
 * The signed-in shell, the route guard, and the onboarding redirect.
 *
 * THE GUARD IS NOT THE SECURITY BOUNDARY. Every seller endpoint enforces
 * ownership server-side; this only avoids rendering a portal to someone whose
 * session has ended.
 *
 * It waits for the session to RESOLVE before deciding. Redirecting while
 * loading would bounce every legitimate vendor to /login on each hard refresh.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading, user, logout } = useAuth();
  const { progress, profile } = useVendor();
  const router = useRouter();
  const pathname = usePathname();

  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  const dashboard = useQuery({
    queryKey: ["vendor", "dashboard"],
    queryFn: () => apiGet<Envelope<VendorDashboard>>("/seller/dashboard").then((r) => r.data),
    enabled: isAuthenticated,
  });

  useEffect(() => {
    if (!isLoading && !isAuthenticated) router.replace("/login");
  }, [isLoading, isAuthenticated, router]);

  /*
   * Send a brand-new vendor into onboarding, ONCE.
   *
   * Keyed on `onboarding_completed_at` being null rather than on the progress
   * percentage: a vendor who later changes their business type can drop below
   * 100%, and bouncing them back into a wizard mid-session over a field they
   * can fix in settings would be infuriating.
   */
  useEffect(() => {
    if (!profile || !progress) return;
    if (progress.onboarding_completed_at !== null) return;
    if (pathname.startsWith("/onboarding")) return;

    router.replace("/onboarding");
  }, [profile, progress, pathname, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-sm text-ink-soft" role="status">
          Loading…
        </p>
      </div>
    );
  }

  if (!isAuthenticated) return null;

  const unread = dashboard.data?.engagement.unread_inquiries ?? 0;

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[240px_1fr]">
      <aside className="hidden border-r border-line bg-surface lg:block">
        <div className="flex h-14 items-center border-b border-line px-5">
<span className="flex items-center gap-2">
            <Logo size="sm" alt="SAKA" />
            <span className="text-sm font-semibold tracking-tight text-brand">for Business</span>
          </span>
        </div>
        <Sidebar unreadInquiries={unread} />
      </aside>

      {mobileNavOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div
            className="absolute inset-0 bg-ink/30"
            onClick={() => setMobileNavOpen(false)}
            role="presentation"
          />
          <div className="absolute inset-y-0 left-0 w-64 border-r border-line bg-surface">
            <div className="flex h-14 items-center justify-between border-b border-line px-4">
              <span className="flex items-center gap-2">
                <Logo size="sm" alt="SAKA" />
                <span className="text-sm font-semibold text-brand">for Business</span>
              </span>
              <button
                type="button"
                onClick={() => setMobileNavOpen(false)}
                aria-label="Close navigation"
                className="text-ink-soft"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <Sidebar unreadInquiries={unread} onNavigate={() => setMobileNavOpen(false)} />
          </div>
        </div>
      )}

      <div className="flex min-w-0 flex-col">
        <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-line bg-surface px-4 sm:px-6">
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => setMobileNavOpen(true)}
              aria-label="Open navigation"
              className="text-ink-soft lg:hidden"
            >
              <Menu className="h-5 w-5" />
            </button>
            <span className="flex items-center gap-2 lg:hidden">
              <Logo size="sm" alt="SAKA" />
              <span className="text-sm font-semibold text-brand">for Business</span>
            </span>
          </div>

          <div className="flex items-center gap-3">
            <div className="hidden text-right sm:block">
              <p className="text-[13px] leading-tight font-medium text-ink">
                {profile?.business_name || profile?.display_name || user?.full_name}
              </p>
              <p className="text-[11px] leading-tight text-ink-faint">{user?.email}</p>
            </div>

            <Button
              size="sm"
              variant="ghost"
              loading={signingOut}
              onClick={() => {
                setSigningOut(true);
                void logout();
              }}
            >
              <LogOut aria-hidden className="h-4 w-4" />
              <span className="hidden sm:inline">Sign out</span>
            </Button>
          </div>
        </header>

        {/*
          The single most important banner in the portal.
          
          Publishing requires a verified phone. Without this a vendor writes a
          full listing, hits publish, gets a 403 they cannot interpret, and
          concludes the product is broken. Shown on every screen until resolved.
        */}
        {user && !user.can_publish_listings && !pathname.startsWith("/verification") && (
          <div className="flex flex-wrap items-center gap-3 border-b border-warn/30 bg-warn-soft px-4 py-2.5 sm:px-6">
            <AlertTriangle aria-hidden className="h-4 w-4 shrink-0 text-warn" />
            <p className="flex-1 text-sm text-ink">
              <strong className="font-medium">Verify your phone to publish.</strong> You can create
              and edit drafts now — they stay private until your number is confirmed.
            </p>
            <Link
              href="/verification"
              className="text-sm font-medium text-brand hover:underline"
            >
              Verify now
            </Link>
          </div>
        )}

        <main className="min-w-0 flex-1 p-4 sm:p-6">{children}</main>
      </div>
    </div>
  );
}
