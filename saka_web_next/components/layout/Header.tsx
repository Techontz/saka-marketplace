"use client";

import Link from "next/link";
import { LocationChip } from "@/components/location/LocationChip";
import { Logo } from "@/components/ui/Logo";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { ArrowUpRight, Heart, Menu, X } from "lucide-react";

import { MessagesBell } from "@/components/layout/MessagesBell";
import { NotificationBell } from "@/components/layout/NotificationBell";
import { AccountMenu } from "@/components/layout/AccountMenu";
import { useAuth } from "@/providers/AuthProvider";
import { useAuthDialog } from "@/providers/AuthDialogProvider";

/**
 * The site header.
 *
 * Ported from the original `__root.tsx` and visually unchanged: same height,
 * same blur, same nav weights, same teal pill with its white circular arrow.
 *
 * What is new is only what Milestone 13 requires — the heart now goes to saved
 * listings, and the "Login Now" pill becomes a notification bell and an account
 * menu once someone is signed in. Signed out, the header is byte-identical to
 * the original.
 */

/*
 * `min-h-11` gives every nav link a 44px hit area.
 *
 * Used by BOTH the desktop bar and the mobile drawer. On desktop the bar is
 * 80px tall so nothing moves; in the drawer it turns 17px text links into
 * targets a thumb can actually hit, which is where it matters.
 */
const navClass =
  "flex min-h-11 items-center text-[15px] font-semibold text-navy/80 hover:text-teal transition-colors";

function BrandLink() {
  return (
    <Link href="/" aria-label="SAKA home" className="flex items-center">
      {/* `priority`: on a cold load this is the LCP element on most pages. */}
      <Logo size="brand" priority />
    </Link>
  );
}

const NAV = [
  { href: "/", label: "Home", exact: true },
  { href: "/listings", label: "Listings" },
  { href: "/businesses", label: "Businesses" },
  // Beside the other verticals rather than under Listings: a specialist is
  // hired, not bought, and people look for one by profession rather than by
  // browsing the marketplace grid.
  { href: "/specialists", label: "Specialists" },
  { href: "/about", label: "About Us" },
  { href: "/contact", label: "Contact Us" },
];

export function Header() {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();
  const { isAuthenticated, isLoading } = useAuth();
  const authDialog = useAuthDialog();

  const isActive = (href: string, exact?: boolean) =>
    exact ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);

  return (
    <header className="fixed top-0 left-0 right-0 z-50 w-full bg-white/5 backdrop-blur-2xl border-b border-white/20 shadow-[0_4px_30px_rgba(0,0,0,0.08)]">
      <div className="mx-auto flex h-20 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6">
        <BrandLink />

        <nav className="hidden md:flex items-center gap-8">
          {NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={`${navClass} ${isActive(item.href, item.exact) ? "!text-teal" : ""}`}
            >
              {item.label}
            </Link>
          ))}
        </nav>

        <div className="flex items-center gap-3">
          {/* Shows what "near me" means, and is the only route back to changing
              it — the welcome dialog is shown once and never again. */}
          <LocationChip className="hidden lg:inline-flex" />

          <Link
            href="/account/favorites"
            aria-label="Saved listings"
            className="hidden md:flex h-11 w-11 items-center justify-center rounded-full border border-border text-teal hover:bg-teal/5 transition"
          >
            <Heart className="h-5 w-5" />
          </Link>

          {/* While the session is still loading, neither state is shown: a
              "Login Now" pill that flips to an avatar a moment later reads as
              a bug on every page load. */}
          {!isLoading && isAuthenticated && (
            <>
              {/* Messages sits beside Favourites and Notifications, and carries
                  the same style of unread badge. */}
              <MessagesBell />
              <NotificationBell />
              <AccountMenu />
            </>
          )}

          {!isLoading && !isAuthenticated && (
            <button
              type="button"
              onClick={() => authDialog.open("login")}
              className="inline-flex items-center gap-2 rounded-full bg-teal pl-5 pr-2 py-2 text-white font-semibold hover:opacity-90 transition"
            >
              Login Now
              <span className="ml-1 flex h-8 w-8 items-center justify-center rounded-full bg-white text-teal">
                <ArrowUpRight className="h-4 w-4" />
              </span>
            </button>
          )}

          <button
            /*
             * 44x44, not the 28x28 the bare icon measured.
             *
             * The negative right margin absorbs the extra padding so the header
             * layout is pixel-identical — the hit area grows, the design does
             * not move.
             */
            className="-mr-2.5 flex h-11 w-11 items-center justify-center text-navy md:hidden"
            onClick={() => setOpen(!open)}
            aria-label="Menu"
            aria-expanded={open}
          >
            {open ? <X className="h-7 w-7" /> : <Menu className="h-7 w-7" />}
          </button>
        </div>
      </div>

      {open && (
        <div className="md:hidden border-t border-border bg-white px-4 py-4 flex flex-col gap-4">
          <LocationChip />
          {NAV.map((item) => (
            <Link key={item.href} href={item.href} className={navClass} onClick={() => setOpen(false)}>
              {item.label}
            </Link>
          ))}
          <Link href="/account/favorites" className={navClass} onClick={() => setOpen(false)}>
            Saved
          </Link>
          {isAuthenticated && (
            <Link href="/account/inquiries" className={navClass} onClick={() => setOpen(false)}>
              Messages
            </Link>
          )}

          {isAuthenticated ? (
            <Link href="/account" className={navClass} onClick={() => setOpen(false)}>
              My account
            </Link>
          ) : (
            <button
              type="button"
              onClick={() => {
                setOpen(false);
                authDialog.open("login");
              }}
              className="inline-flex items-center justify-center rounded-full bg-teal px-5 py-2 text-white font-semibold"
            >
              Login
            </button>
          )}
        </div>
      )}
    </header>
  );
}
