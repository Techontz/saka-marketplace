"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Bell } from "lucide-react";

import { apiGet } from "@/lib/api/browser";

/**
 * The unread badge.
 *
 * Polls rather than streams: there is no websocket layer, and a customer-facing
 * bell that is a minute stale is fine — pretending otherwise would mean
 * building infrastructure this milestone did not ask for. The interval is long
 * enough not to matter and the query is disabled while the tab is hidden.
 */
export function NotificationBell() {
  const { data } = useQuery({
    queryKey: ["notifications", "unread-count"],
    queryFn: () => apiGet<{ data: { unread_count: number } }>("/account/notifications/unread-count"),
    refetchInterval: 60 * 1000,
    refetchIntervalInBackground: false,
    staleTime: 30 * 1000,
  });

  const count = data?.data.unread_count ?? 0;

  return (
    <Link
      href="/account/notifications"
      aria-label={count > 0 ? `Notifications, ${count} unread` : "Notifications"}
      className="relative flex h-11 w-11 items-center justify-center rounded-full border border-border text-navy transition hover:bg-teal/5 hover:text-teal"
    >
      <Bell className="h-5 w-5" />
      {count > 0 && (
        <span className="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-orange px-1 text-[11px] font-bold text-white">
          {count > 9 ? "9+" : count}
        </span>
      )}
    </Link>
  );
}
