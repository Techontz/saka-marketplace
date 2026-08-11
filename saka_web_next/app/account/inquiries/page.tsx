"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Inbox } from "lucide-react";

import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiInquiry, Paginated } from "@/lib/types";

const STATUS_LABELS: Record<string, { label: string; tone: string }> = {
  new: { label: "Sent", tone: "bg-page text-muted-foreground" },
  read: { label: "Seen", tone: "bg-teal/10 text-teal" },
  replied: { label: "Replied", tone: "bg-teal text-white" },
  closed: { label: "Resolved", tone: "bg-page text-muted-foreground" },
  spam: { label: "Closed", tone: "bg-page text-muted-foreground" },
};

/** Every message this customer has sent, and what happened to it. */
export default function InquiriesPage() {
  const inquiries = useQuery({
    queryKey: ["account-inquiries"],
    queryFn: () => apiGet<Paginated<ApiInquiry>>("/account/inquiries", { per_page: 30 }),
  });

  const rows = inquiries.data?.data ?? [];

  return (
    <>
      <h2 className="text-2xl font-extrabold text-navy">My inquiries</h2>
      <p className="mt-1 mb-6 text-muted-foreground">
        Messages you have sent, and the replies you have had.
      </p>

      {inquiries.isPending ? (
        <div className="space-y-3">
          <RowSkeleton count={4} />
        </div>
      ) : inquiries.error ? (
        <ErrorState error={inquiries.error} onRetry={() => void inquiries.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No inquiries yet"
          description="When you message a seller from a listing, the conversation appears here."
          icon={<Inbox className="h-6 w-6" />}
          action={
            <Link
              href="/listings"
              className="inline-flex items-center rounded-full bg-teal px-5 py-2 font-semibold text-white"
            >
              Browse listings
            </Link>
          }
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((inquiry) => {
            const status = STATUS_LABELS[inquiry.status] ?? STATUS_LABELS.new;

            return (
              <li key={inquiry.uuid}>
                <Link
                  href={`/account/inquiries/${inquiry.uuid}`}
                  className="block rounded-xl border border-border bg-white p-5 transition hover:border-teal"
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="truncate font-bold text-navy">
                      {inquiry.listing?.title ?? "General enquiry"}
                    </p>
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${status.tone}`}>
                      {status.label}
                    </span>
                  </div>

                  <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">{inquiry.message}</p>

                  {inquiry.reply && (
                    <p className="mt-2 line-clamp-1 rounded-lg border-l-2 border-teal bg-teal/5 px-3 py-2 text-sm text-navy">
                      Reply: {inquiry.reply.body}
                    </p>
                  )}

                  {inquiry.created_at && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Sent {new Date(inquiry.created_at).toLocaleDateString()}
                    </p>
                  )}
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </>
  );
}
