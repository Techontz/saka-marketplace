"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { ChevronDown, Heart, Inbox, LogOut, Star, User as UserIcon } from "lucide-react";

import { useAuth } from "@/providers/AuthProvider";
import { SafeImage } from "@/components/ui/SafeImage";

/** The signed-in menu, in the slot the "Login Now" pill occupies when signed out. */
export function AccountMenu() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handler = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  if (!user) return null;

  const initials = [user.first_name?.[0], user.last_name?.[0]].filter(Boolean).join("").toUpperCase();

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-haspopup="menu"
        aria-expanded={open}
        className="flex items-center gap-2 rounded-full border border-border py-1 pl-1 pr-3 transition hover:border-teal"
      >
        <span className="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-teal text-sm font-bold text-white">
          {user.avatar_url ? (
            <SafeImage
              src={user.avatar_url}
              alt=""
              className="h-full w-full object-cover"
              fallbackClassName="h-full w-full bg-teal text-white"
              fallback={initials || <UserIcon className="h-4 w-4" />}
            />
          ) : (
            initials || <UserIcon className="h-4 w-4" />
          )}
        </span>
        <span className="hidden text-sm font-semibold text-navy sm:block">{user.first_name}</span>
        <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 top-full z-50 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-white shadow-2xl animate-slide-down"
        >
          <div className="border-b border-border px-4 py-3">
            <p className="truncate text-sm font-bold text-navy">{user.full_name}</p>
            <p className="truncate text-xs text-muted-foreground">{user.email}</p>
          </div>

          <nav className="py-1">
            {[
              { href: "/account", label: "My account", icon: UserIcon },
              { href: "/account/favorites", label: "Saved", icon: Heart },
              { href: "/account/inquiries", label: "My inquiries", icon: Inbox },
              { href: "/account/reviews", label: "My reviews", icon: Star },
            ].map((item) => (
              <Link
                key={item.href}
                href={item.href}
                onClick={() => setOpen(false)}
                className="flex items-center gap-2.5 px-4 py-2.5 text-sm font-medium text-navy transition hover:bg-teal/5 hover:text-teal"
              >
                <item.icon className="h-4 w-4" />
                {item.label}
              </Link>
            ))}
          </nav>

          <button
            type="button"
            onClick={() => {
              setOpen(false);
              void logout();
            }}
            className="flex w-full items-center gap-2.5 border-t border-border px-4 py-2.5 text-sm font-medium text-navy transition hover:bg-destructive/5 hover:text-destructive"
          >
            <LogOut className="h-4 w-4" />
            Sign out
          </button>
        </div>
      )}
    </div>
  );
}
