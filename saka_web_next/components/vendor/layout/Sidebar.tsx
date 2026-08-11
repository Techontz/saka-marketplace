"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/vendor/cn";
import { NAV_ITEMS, navLabel } from "@/lib/vendor/nav";
import { useVendor } from "@/providers/vendor/VendorProvider";

/**
 * The portal's navigation.
 *
 * Labels come from the business type — a hotel sees "Rooms", a dealer sees
 * "Vehicles". One portal, ten trades, and the wording is the cheapest part of
 * making it feel built for each of them.
 */
export function Sidebar({
  onNavigate,
  unreadInquiries = 0,
}: {
  onNavigate?: () => void;
  unreadInquiries?: number;
}) {
  const pathname = usePathname();
  const { noun } = useVendor();

  return (
    <nav aria-label="Main" className="flex h-full flex-col gap-1 overflow-y-auto p-3">
      {NAV_ITEMS.map((item) => {
        // Exact for the dashboard, prefix for everything else — so
        // /vendor/listings/{uuid} still highlights "Listings". The dashboard is
        // "/vendor" now that the portal is mounted under a path rather than a
        // subdomain; a prefix test there would light up on every page.
        const active =
          item.href === "/vendor" ? pathname === "/vendor" : pathname.startsWith(item.href);
        const badge = item.badge === "unread_inquiries" ? unreadInquiries : 0;

        return (
          <Link
            key={item.href}
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
            <span className="flex-1">{navLabel(item, noun)}</span>

            {badge > 0 && (
              <span className="rounded-full bg-warn-soft px-1.5 py-0.5 text-[11px] font-semibold text-warn">
                {badge}
              </span>
            )}
          </Link>
        );
      })}
    </nav>
  );
}
