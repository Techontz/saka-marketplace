import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  Building2,
  GraduationCap,
  Megaphone,
  Inbox,
  LayoutDashboard,
  Package,
  ShieldCheck,
  Star,
} from "lucide-react";

/**
 * The portal's navigation.
 *
 * `label` may be a function of the business type, because "Listings" is the
 * wrong word for most of the trades this one portal serves — a hotel manages
 * rooms, a dealer manages vehicles. Everything else is the same for everyone.
 */
export type NavItem = {
  href: string;
  label: string | ((noun: { singular: string; plural: string }) => string);
  icon: LucideIcon;
  /** Shows a count badge from the dashboard payload, when non-zero. */
  badge?: "unread_inquiries";
};

export const NAV_ITEMS: NavItem[] = [
  { href: "/", label: "Dashboard", icon: LayoutDashboard },
  { href: "/listings", label: (noun) => capitalise(noun.plural), icon: Package },
  { href: "/inquiries", label: "Inquiries", icon: Inbox, badge: "unread_inquiries" },
  { href: "/reviews", label: "Reviews", icon: Star },
  { href: "/specialist", label: "Specialist", icon: GraduationCap },
  { href: "/promotions", label: "Promotions", icon: Megaphone },
  { href: "/analytics", label: "Analytics", icon: BarChart3 },
  { href: "/business", label: "Business profile", icon: Building2 },
  { href: "/verification", label: "Verification", icon: ShieldCheck },
];

function capitalise(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1);
}

export function navLabel(item: NavItem, noun: { singular: string; plural: string }): string {
  return typeof item.label === "function" ? item.label(noun) : item.label;
}
