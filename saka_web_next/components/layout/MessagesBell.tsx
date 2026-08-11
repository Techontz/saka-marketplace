"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { MessageSquare } from "lucide-react";

import { apiGet } from "@/lib/api/browser";
import type { ApiInquiry, Paginated } from "@/lib/types";

/**
 * Messages, beside the heart and the notification bell.
 *
 * Counts inquiry threads that have a reply the customer has not opened yet.
 * There is no unread-message endpoint — messaging is the inquiry thread here,
 * and `GET /account/inquiries` is the only list of them — so the badge is
 * derived: a thread counts as unread when the seller has replied and the
 * status has not moved past `replied`.
 *
 * That is honest about what the API knows. A per-thread read receipt would
 * need a backend field; it is listed in the report rather than faked with
 * localStorage, which would make the badge disagree across devices.
 */
export function MessagesBell() {
  const inquiries = useQuery({
    queryKey: ["account-inquiries", "unread"],
    queryFn: () => apiGet<Paginated<ApiInquiry>>("/account/inquiries", { per_page: 100 }),
    staleTime: 60 * 1000,
    // Same cadence as the notification bell, so the two never disagree.
    refetchInterval: 120 * 1000,
  });

  const unread = (inquiries.data?.data ?? []).filter(
    (inquiry) => inquiry.reply !== null && inquiry.status === "replied",
  ).length;

  return (
    <Link
      href="/account/inquiries"
      aria-label={unread > 0 ? `Messages, ${unread} with a new reply` : "Messages"}
      className="relative flex h-11 w-11 items-center justify-center rounded-full border border-border text-teal transition hover:bg-teal/5"
    >
      <MessageSquare className="h-5 w-5" />

      {unread > 0 && (
        <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-orange px-1 text-[10px] font-bold text-white">
          {unread > 9 ? "9+" : unread}
        </span>
      )}
    </Link>
  );
}
