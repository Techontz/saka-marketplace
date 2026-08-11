"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/cn";
import { NAV_GROUPS, NAV_ITEMS } from "@/lib/nav";
import { useAuth } from "@/providers/AuthProvider";

/**
 * The portal's navigation.
 *
 * Entries the operator has no permission for are hidden. That is presentation,
 * not access control — see the note on NAV_ITEMS.
 */
export function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { can } = useAuth();

  const visible = NAV_ITEMS.filter((item) => !item.permission || can(...item.permission));

  return (
    <nav aria-label="Main" className="flex h-full flex-col gap-6 overflow-y-auto p-4">
      {NAV_GROUPS.map((group) => {
        const items = visible.filter((item) => item.group === group);
        if (items.length === 0) return null;

        return (
          <div key={group}>
            <p className="mb-2 px-3 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
              {group}
            </p>
            <ul className="space-y-0.5">
              {items.map((item) => {
                // Exact for the dashboard, prefix for everything else — so
                // /listings/{uuid} still highlights "Listings".
                const active =
                  item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      onClick={onNavigate}
                      aria-current={active ? "page" : undefined}
                      className={cn(
                        "flex items-center gap-2.5 rounded-[var(--radius-control)] px-3 py-2 text-sm transition-colors",
                        active
                          ? "bg-brand-soft font-medium text-brand-ink"
                          : "text-ink-soft hover:bg-muted-soft hover:text-ink",
                      )}
                    >
                      <item.icon aria-hidden className="h-4 w-4 shrink-0" />
                      {item.label}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );
}
