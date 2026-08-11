"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, CheckCircle2, Circle, MessageSquare } from "lucide-react";

import { ErrorState, Spinner } from "@/components/ui/states";
import { apiGet } from "@/lib/api/browser";
import type { ApiInquiry } from "@/lib/types";

type TimelineEvent = { event: string; label: string; at: string | null };

/**
 * One inquiry, with its timeline.
 *
 * The timeline shows only what actually happened — sent, seen, replied,
 * resolved. An inquiry is one message and at most one reply, so there is no
 * thread to render, and inventing one would imply a conversation the product
 * does not support.
 */
export default function InquiryDetailPage() {
  const params = useParams<{ uuid: string }>();

  const inquiry = useQuery({
    queryKey: ["account-inquiry", params.uuid],
    queryFn: () =>
      apiGet<{ data: ApiInquiry; meta: { timeline: TimelineEvent[]; business: { slug: string; display_name: string } | null } }>(
        `/account/inquiries/${params.uuid}`,
      ),
  });

  if (inquiry.isPending) return <Spinner label="Loading this conversation…" />;
  if (inquiry.error) {
    return <ErrorState error={inquiry.error} onRetry={() => void inquiry.refetch()} title="We couldn't load this inquiry" />;
  }

  const data = inquiry.data.data;
  const timeline = inquiry.data.meta.timeline;
  const business = inquiry.data.meta.business;

  return (
    <>
      <Link
        href="/account/inquiries"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-teal"
      >
        <ArrowLeft className="h-4 w-4" />
        Back to inquiries
      </Link>

      <h1 className="text-2xl font-extrabold text-navy">
        {data.listing?.title ?? "General enquiry"}
      </h1>

      <div className="mt-2 flex flex-wrap gap-4 text-sm">
        {data.listing && (
          <Link href={`/listings/${data.listing.slug}`} className="font-semibold text-teal">
            View the listing
          </Link>
        )}
        {business && (
          <Link href={`/businesses/${business.slug}`} className="font-semibold text-teal">
            {business.display_name}
          </Link>
        )}
      </div>

      <div className="mt-6 rounded-xl border border-border bg-white p-6">
        <p className="mb-1 text-xs font-bold uppercase tracking-wide text-muted-foreground">
          Your message
        </p>
        <p className="whitespace-pre-wrap text-navy">{data.message}</p>
      </div>

      {data.reply ? (
        <div className="mt-4 rounded-xl border border-teal/30 bg-teal/5 p-6">
          <p className="mb-1 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-teal">
            <MessageSquare className="h-3.5 w-3.5" />
            Their reply
            {data.reply.replied_at && (
              <span className="font-normal normal-case text-muted-foreground">
                · {new Date(data.reply.replied_at).toLocaleDateString()}
              </span>
            )}
          </p>
          <p className="whitespace-pre-wrap text-navy">{data.reply.body}</p>
        </div>
      ) : (
        <p className="mt-4 rounded-xl border border-dashed border-border bg-white p-6 text-center text-sm text-muted-foreground">
          No reply yet. We&apos;ll notify you as soon as there is one.
        </p>
      )}

      <div className="mt-8 rounded-xl border border-border bg-white p-6">
        <h2 className="mb-4 text-lg font-bold text-navy">What happened</h2>
        <ol className="space-y-4">
          {timeline.map((event) => (
            <li key={event.event} className="flex gap-3">
              <span className="mt-0.5 text-teal">
                {event.event === "sent" ? (
                  <Circle className="h-4 w-4" />
                ) : (
                  <CheckCircle2 className="h-4 w-4" />
                )}
              </span>
              <span>
                <span className="block text-sm font-semibold text-navy">{event.label}</span>
                {event.at && (
                  <span className="block text-xs text-muted-foreground">
                    {new Date(event.at).toLocaleString()}
                  </span>
                )}
              </span>
            </li>
          ))}
        </ol>
      </div>
    </>
  );
}
