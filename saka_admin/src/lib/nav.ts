import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  CalendarDays,
  Megaphone,
  FileText,
  Image as ImageIcon,
  Inbox,
  LayoutDashboard,
  ListChecks,
  MapPin,
  Package,
  ScrollText,
  Settings,
  ShieldCheck,
  Star,
  Tags,
  TrendingUp,
  Users,
} from "lucide-react";

/**
 * The portal's navigation, and the permission each entry needs.
 *
 * `permission` here is UI-shaping ONLY. Hiding a link the operator cannot use
 * is a courtesy, not a control — the API enforces every permission on every
 * request, and a hidden link is still reachable by typing the URL, where it
 * will correctly 403. Treating this list as the security boundary is the
 * classic admin-portal mistake.
 */
export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Any ONE of these is enough. Undefined means every signed-in operator. */
  permission?: string[];
  group: "Overview" | "Marketplace" | "Advertising" | "People" | "Content" | "System";
};

export const NAV_ITEMS: NavItem[] = [
  { href: "/", label: "Dashboard", icon: LayoutDashboard, group: "Overview", permission: ["analytics.view"] },
  { href: "/analytics", label: "Analytics", icon: BarChart3, group: "Overview", permission: ["analytics.view"] },

  { href: "/listings", label: "Listings", icon: Package, group: "Marketplace", permission: ["listing.moderate"] },
  { href: "/reviews", label: "Reviews", icon: Star, group: "Marketplace", permission: ["review.moderate"] },
  { href: "/categories", label: "Categories", icon: Tags, group: "Marketplace", permission: ["category.manage"] },
  { href: "/attributes", label: "Attributes", icon: ListChecks, group: "Marketplace", permission: ["attribute.manage"] },
  { href: "/places", label: "Public places", icon: MapPin, group: "Marketplace", permission: ["location.manage"] },
  /*
   * Bookings sits under Marketplace, not in its own group.
   *
   * It is a READ surface for support — "the customer says they booked and the
   * lawyer says they did not" — and giving it top-level billing would suggest
   * administrators run the diary. They do not: confirming and declining are the
   * specialist's calls.
   */
  { href: "/bookings", label: "Bookings", icon: CalendarDays, group: "Marketplace", permission: ["inquiry.view_any"] },

  /*
   * Advertising is its own group, not a sub-item of Marketplace.
   *
   * It is the only part of this portal where an action costs an external party
   * money, and it is gated on its own permission — an operator who can moderate
   * listings deliberately cannot book inventory. Burying it under Marketplace
   * would suggest the two are the same job.
   */
  { href: "/advertising", label: "Campaigns", icon: Megaphone, group: "Advertising", permission: ["advertising.manage"] },
  { href: "/advertising/promotions", label: "Promotion requests", icon: Inbox, group: "Advertising", permission: ["advertising.manage"] },
  { href: "/advertising/performance", label: "Performance", icon: TrendingUp, group: "Advertising", permission: ["advertising.manage"] },

  { href: "/users", label: "Users", icon: Users, group: "People", permission: ["user.view_any"] },
  { href: "/vendors", label: "Vendor verification", icon: ShieldCheck, group: "People", permission: ["verification.review"] },
  { href: "/activity", label: "Activity log", icon: ScrollText, group: "People", permission: ["activity_log.view"] },

  { href: "/cms/banners", label: "Banners & sections", icon: ImageIcon, group: "Content", permission: ["cms.manage"] },
  { href: "/cms/pages", label: "Pages & FAQs", icon: FileText, group: "Content", permission: ["cms.manage"] },

  { href: "/settings", label: "Settings", icon: Settings, group: "System", permission: ["settings.manage"] },
];

export const NAV_GROUPS = [
  "Overview",
  "Marketplace",
  "Advertising",
  "People",
  "Content",
  "System",
] as const;
