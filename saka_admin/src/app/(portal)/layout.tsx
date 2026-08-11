"use client";

import { Menu, X } from "lucide-react";
import { useRouter } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";

import { Sidebar } from "@/components/layout/Sidebar";
import { Logo } from "@/components/ui/Logo";
import { Topbar } from "@/components/layout/Topbar";
import { useAuth } from "@/providers/AuthProvider";

/**
 * The signed-in shell, and the client-side route guard.
 *
 * THE GUARD IS NOT THE SECURITY BOUNDARY. Every admin endpoint enforces its own
 * permission server-side; this only avoids rendering a portal to someone whose
 * session has ended, and sends them somewhere useful. Anyone can bypass it with
 * devtools and will simply receive 401s and 403s from the API.
 *
 * It waits for the session to RESOLVE before deciding. Redirecting while
 * `isLoading` is true would bounce every legitimate operator to /login on each
 * hard refresh, which is the most common way this pattern is got wrong.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) router.replace("/login");
  }, [isLoading, isAuthenticated, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-sm text-ink-soft" role="status">
          Loading…
        </p>
      </div>
    );
  }

  // The redirect above is in flight; rendering the shell would flash it.
  if (!isAuthenticated) return null;

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[248px_1fr]">
      <aside className="hidden border-r border-line bg-surface lg:block">
        <div className="flex h-14 items-center border-b border-line px-5">
          {/* "Admin" stays TEXT beside the mark: it is a qualifier, not part
              of the logo, and baking it into an image would make it invisible
              to a screen reader. */}
          <span className="flex items-center gap-2">
            <Logo size="sm" alt="SAKA" />
            <span className="text-sm font-semibold tracking-tight text-ink-faint">Admin</span>
          </span>
        </div>
        <Sidebar />
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
                <span className="text-sm font-semibold text-ink-faint">Admin</span>
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
            <Sidebar onNavigate={() => setMobileNavOpen(false)} />
          </div>
        </div>
      )}

      <div className="flex min-w-0 flex-col">
        <Topbar
          onOpenNav={() => setMobileNavOpen(true)}
          navButton={
            <button
              type="button"
              onClick={() => setMobileNavOpen(true)}
              aria-label="Open navigation"
              className="text-ink-soft lg:hidden"
            >
              <Menu className="h-5 w-5" />
            </button>
          }
        />
        <main className="min-w-0 flex-1 p-4 sm:p-6">{children}</main>
      </div>
    </div>
  );
}
