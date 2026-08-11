"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Flag, Star } from "lucide-react";
import { Suspense, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Field,
  FormError,
  ListState,
  Modal,
  PageHeader,
  Pagination,
  Select,
  Textarea,
  humanise,
  statusTone,
} from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import { useUrlFilters } from "@/lib/vendor/hooks";
import type { Envelope, Paginated, Review } from "@/lib/vendor/api/types";

const REPORT_REASONS = [
  { value: "not_a_customer", label: "This person was never a customer" },
  { value: "false_information", label: "It contains false information" },
  { value: "offensive", label: "It is offensive" },
  { value: "spam", label: "It is spam" },
  { value: "other", label: "Something else" },
];

/**
 * Reviews received.
 *
 * A vendor gets exactly one public answer per review and a way to escalate one
 * they believe is illegitimate. Reporting does NOT hide the review — the copy
 * here says so plainly, because a vendor who expects criticism to disappear
 * will report everything and then distrust the whole system when it doesn't.
 */
export default function ReviewsPage() {
  return (
    <Suspense fallback={null}>
      <ReviewsView />
    </Suspense>
  );
}

function ReviewsView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ rating: "", replied: "", page: "1" });

  const [replyingTo, setReplyingTo] = useState<string | null>(null);
  const [replyBody, setReplyBody] = useState("");
  const [reporting, setReporting] = useState<Review | null>(null);
  const [reportReason, setReportReason] = useState(REPORT_REASONS[0].value);
  const [reportDetails, setReportDetails] = useState("");

  const query = useQuery({
    queryKey: ["vendor-reviews", filters],
    queryFn: () =>
      apiGet<Paginated<Review>>("/seller/reviews", {
        rating: filters.rating || undefined,
        replied: filters.replied || undefined,
        page: filters.page,
        per_page: 20,
      }),
  });

  const reply = useMutation({
    mutationFn: ({ uuid, body }: { uuid: string; body: string }) =>
      apiSend<Envelope<Review>>(`/seller/reviews/${uuid}/reply`, "POST", { body }),
    onSuccess: async () => {
      setReplyingTo(null);
      setReplyBody("");
      await queryClient.invalidateQueries({ queryKey: ["vendor-reviews"] });
    },
  });

  const report = useMutation({
    mutationFn: (review: Review) =>
      apiSend(`/seller/reviews/${review.uuid}/report`, "POST", {
        reason: reportReason,
        details: reportDetails,
      }),
    onSuccess: () => {
      setReporting(null);
      setReportDetails("");
    },
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Reviews"
        description="What customers said, and your answers. Replies appear publicly under the review."
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-[160px]">
            <label htmlFor="rating" className="mb-1.5 block text-[13px] font-medium text-ink">
              Rating
            </label>
            <Select
              id="rating"
              value={filters.rating}
              onChange={(event) => setFilters({ rating: event.target.value || null })}
            >
              <option value="">Any</option>
              {[5, 4, 3, 2, 1].map((rating) => (
                <option key={rating} value={rating}>
                  {rating} star{rating === 1 ? "" : "s"}
                </option>
              ))}
            </Select>
          </div>

          <div className="w-[180px]">
            <label htmlFor="replied" className="mb-1.5 block text-[13px] font-medium text-ink">
              Answered
            </label>
            <Select
              id="replied"
              value={filters.replied}
              onChange={(event) => setFilters({ replied: event.target.value || null })}
            >
              <option value="">Any</option>
              <option value="0">Not yet answered</option>
              <option value="1">Answered</option>
            </Select>
          </div>
        </div>
      </Card>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle={
            filters.rating || filters.replied ? "Nothing matches those filters" : "No reviews yet"
          }
          emptyDescription={
            filters.rating || filters.replied
              ? "Try widening the filters."
              : "Reviews appear here once customers start leaving them."
          }
        >
          <ul className="divide-y divide-line">
            {rows.map((review) => {
              const isReplying = replyingTo === review.uuid;

              return (
                <li key={review.uuid} className="p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex flex-wrap items-center gap-2">
                        <Stars rating={review.rating} />
                        <span className="text-sm font-medium text-ink">
                          {review.reviewer?.name ?? "A customer"}
                        </span>
                        {review.status !== "approved" && (
                          <Badge tone={statusTone(review.status)}>{humanise(review.status)}</Badge>
                        )}
                        {review.created_at && (
                          <span className="text-[11px] text-ink-faint">
                            {new Date(review.created_at).toLocaleDateString()}
                          </span>
                        )}
                      </div>

                      {review.listing && (
                        <p className="mb-1.5 text-xs text-ink-faint">About: {review.listing.title}</p>
                      )}

                      {review.title && (
                        <p className="text-sm font-medium text-ink">{review.title}</p>
                      )}
                      {review.body && (
                        <p className="mt-0.5 text-sm whitespace-pre-wrap text-ink-soft">
                          {review.body}
                        </p>
                      )}

                      {review.reply && (
                        <div className="mt-3 rounded-[var(--radius-control)] border-l-2 border-brand bg-brand-soft/40 px-3 py-2">
                          <p className="text-[11px] font-medium text-brand-ink">
                            Your reply
                            {review.reply.replied_at &&
                              ` · ${new Date(review.reply.replied_at).toLocaleDateString()}`}
                          </p>
                          <p className="mt-0.5 text-sm whitespace-pre-wrap text-ink-soft">
                            {review.reply.body}
                          </p>
                        </div>
                      )}
                    </div>

                    <div className="flex shrink-0 flex-wrap gap-1.5">
                      {/* Only published reviews can be answered — a reply to a
                          review still in moderation would have nothing to
                          appear under, and the API rejects it. */}
                      {review.status === "approved" && (
                        <Button
                          size="sm"
                          variant={review.reply ? "ghost" : "primary"}
                          onClick={() => {
                            setReplyingTo(isReplying ? null : review.uuid);
                            setReplyBody(review.reply?.body ?? "");
                          }}
                        >
                          {isReplying ? "Cancel" : review.reply ? "Edit reply" : "Reply"}
                        </Button>
                      )}

                      <Button size="sm" variant="ghost" onClick={() => setReporting(review)}>
                        <Flag aria-hidden className="h-3.5 w-3.5" />
                        Report
                      </Button>
                    </div>
                  </div>

                  {isReplying && (
                    <div className="mt-3 rounded-[var(--radius-control)] border border-line p-3">
                      <Field
                        label="Your public reply"
                        hint="Everyone who reads this review will see your answer."
                      >
                        <Textarea
                          rows={4}
                          value={replyBody}
                          onChange={(event) => setReplyBody(event.target.value)}
                          maxLength={2000}
                          autoFocus
                        />
                      </Field>

                      <FormError error={reply.error} />

                      <div className="mt-3 flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => setReplyingTo(null)}>
                          Cancel
                        </Button>
                        <Button
                          size="sm"
                          variant="primary"
                          loading={reply.isPending}
                          disabled={replyBody.trim().length < 2}
                          onClick={() => reply.mutate({ uuid: review.uuid, body: replyBody.trim() })}
                        >
                          {review.reply ? "Update reply" : "Post reply"}
                        </Button>
                      </div>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </ListState>

        {meta && (
          <Pagination
            page={meta.current_page}
            lastPage={meta.last_page}
            total={meta.total}
            from={meta.from}
            to={meta.to}
            disabled={query.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>

      <Modal
        open={reporting !== null}
        onClose={() => setReporting(null)}
        title="Report this review"
        description="A moderator will look at it. The review stays visible in the meantime — reporting is not a way to remove criticism."
        footer={
          <>
            <Button variant="ghost" onClick={() => setReporting(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={report.isPending}
              disabled={reportDetails.trim().length < 10}
              onClick={() => reporting && report.mutate(reporting)}
            >
              Send report
            </Button>
          </>
        }
      >
        <Field label="What is wrong with it?">
          <Select value={reportReason} onChange={(event) => setReportReason(event.target.value)}>
            {REPORT_REASONS.map((reason) => (
              <option key={reason.value} value={reason.value}>
                {reason.label}
              </option>
            ))}
          </Select>
        </Field>

        <div className="mt-4">
          <Field label="Tell the moderator more" hint="At least a sentence — 10 characters minimum.">
            <Textarea
              rows={4}
              value={reportDetails}
              onChange={(event) => setReportDetails(event.target.value)}
              maxLength={1000}
            />
          </Field>
        </div>

        <FormError error={report.error} />
      </Modal>
    </>
  );
}

function Stars({ rating }: { rating: number }) {
  return (
    <span className="flex items-center gap-0.5" aria-label={`${rating} out of 5`}>
      {[1, 2, 3, 4, 5].map((value) => (
        <Star
          key={value}
          aria-hidden
          className={
            "h-3.5 w-3.5 " +
            (value <= rating ? "fill-warn text-warn" : "text-line-strong")
          }
        />
      ))}
    </span>
  );
}
