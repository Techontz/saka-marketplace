"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Flag, Loader2, Star } from "lucide-react";

import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet, apiSend } from "@/lib/api/browser";
import { ApiError } from "@/lib/api/errors";
import type { ApiReview, Paginated } from "@/lib/types";
import { useAuth } from "@/providers/AuthProvider";
import { useAuthDialog } from "@/providers/AuthDialogProvider";

/**
 * Reviews on a listing: read, write, and report.
 *
 * Editing and deleting live in the customer's own account area rather than
 * here — this panel is the public view, and putting destructive controls on a
 * public page beside other people's reviews invites mistakes.
 */
export function ListingReviews({ slug }: { slug: string }) {
  const { user, isAuthenticated } = useAuth();
  const authDialog = useAuthDialog();
  const queryClient = useQueryClient();

  const [writing, setWriting] = useState(false);
  const [rating, setRating] = useState(5);
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [reporting, setReporting] = useState<string | null>(null);
  const [reportDetails, setReportDetails] = useState("");

  const reviews = useQuery({
    queryKey: ["listing-reviews", slug],
    queryFn: () => apiGet<Paginated<ApiReview>>(`/listings/${slug}/reviews`),
  });

  const submit = useMutation({
    mutationFn: () =>
      apiSend(`/account/reviews/${slug}`, "POST", { rating, title: title || null, body: body || null }),
    onSuccess: async () => {
      setWriting(false);
      setTitle("");
      setBody("");
      await queryClient.invalidateQueries({ queryKey: ["listing-reviews", slug] });
    },
  });

  const report = useMutation({
    mutationFn: (uuid: string) =>
      apiSend(`/account/reviews/${uuid}/report`, "POST", {
        reason: "offensive",
        details: reportDetails,
      }),
    onSuccess: () => {
      setReporting(null);
      setReportDetails("");
    },
  });

  const rows = reviews.data?.data ?? [];
  const mine = rows.find((review) => review.reviewer?.uuid === user?.uuid);

  return (
    <div className="mt-6 bg-white border border-border p-8 animate-fade-up">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-2xl font-extrabold text-navy">Reviews</h3>

        {!mine && (
          <button
            onClick={() => {
              if (!isAuthenticated) {
                authDialog.open("login", "Sign in to review this listing.");
                return;
              }
              setWriting((value) => !value);
            }}
            className="rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white transition hover:opacity-90"
          >
            {writing ? "Cancel" : "Write a review"}
          </button>
        )}
      </div>

      {writing && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            submit.mutate();
          }}
          className="mb-8 rounded-xl border border-border p-5"
        >
          <div className="mb-4 flex items-center gap-1">
            {[1, 2, 3, 4, 5].map((value) => (
              <button
                key={value}
                type="button"
                onClick={() => setRating(value)}
                aria-label={`${value} star${value === 1 ? "" : "s"}`}
              >
                <Star
                  className={`h-7 w-7 transition ${value <= rating ? "fill-orange text-orange" : "text-border"}`}
                />
              </button>
            ))}
          </div>

          <input
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            placeholder="Sum it up in a few words"
            maxLength={200}
            className="mb-3 w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
          />

          <textarea
            value={body}
            onChange={(event) => setBody(event.target.value)}
            rows={4}
            maxLength={2000}
            placeholder="What was it actually like?"
            className="w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
          />

          {submit.error && (
            <p className="mt-3 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {submit.error instanceof ApiError
                ? (submit.error.allFieldMessages()[0] ?? submit.error.message)
                : "That didn't send."}
            </p>
          )}

          <button
            type="submit"
            disabled={submit.isPending}
            className="mt-4 inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
          >
            {submit.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            Post review
          </button>

          <p className="mt-2 text-xs text-muted-foreground">
            Reviews are checked before they appear.
          </p>
        </form>
      )}

      {reviews.isPending ? (
        <div className="space-y-3">
          <RowSkeleton count={3} />
        </div>
      ) : reviews.error ? (
        <ErrorState error={reviews.error} onRetry={() => void reviews.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No reviews yet"
          description="Be the first to say what this place is actually like."
          icon={<Star className="h-6 w-6" />}
        />
      ) : (
        <ul className="divide-y divide-border">
          {rows.map((review) => (
            <li key={review.uuid} className="py-5 first:pt-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="flex items-center gap-0.5">
                  {[1, 2, 3, 4, 5].map((value) => (
                    <Star
                      key={value}
                      className={`h-4 w-4 ${value <= review.rating ? "fill-orange text-orange" : "text-border"}`}
                    />
                  ))}
                </span>
                <span className="text-sm font-bold text-navy">
                  {review.reviewer?.name ?? "A customer"}
                </span>
                {review.created_at && (
                  <span className="text-xs text-muted-foreground">
                    {new Date(review.created_at).toLocaleDateString()}
                  </span>
                )}
              </div>

              {review.title && <p className="mt-2 font-semibold text-navy">{review.title}</p>}
              {review.body && (
                <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">{review.body}</p>
              )}

              {review.reply && (
                <div className="mt-3 rounded-lg border-l-2 border-teal bg-teal/5 px-4 py-3">
                  <p className="text-xs font-bold text-teal">Reply from the business</p>
                  <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">
                    {review.reply.body}
                  </p>
                </div>
              )}

              {isAuthenticated && review.reviewer?.uuid !== user?.uuid && (
                <div className="mt-3">
                  {reporting === review.uuid ? (
                    <div className="rounded-lg border border-border p-3">
                      <textarea
                        rows={3}
                        value={reportDetails}
                        onChange={(event) => setReportDetails(event.target.value)}
                        placeholder="What is wrong with this review? At least a sentence."
                        className="w-full rounded-lg border border-border px-3 py-2 text-sm outline-none focus:border-teal"
                      />
                      <div className="mt-2 flex gap-2">
                        <button
                          onClick={() => report.mutate(review.uuid)}
                          disabled={reportDetails.trim().length < 10 || report.isPending}
                          className="rounded-full bg-teal px-4 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                        >
                          Send report
                        </button>
                        <button
                          onClick={() => setReporting(null)}
                          className="rounded-full border border-border px-4 py-1.5 text-xs font-semibold text-navy"
                        >
                          Cancel
                        </button>
                      </div>
                      <p className="mt-2 text-xs text-muted-foreground">
                        Reporting asks a moderator to look. The review stays visible meanwhile.
                      </p>
                    </div>
                  ) : (
                    <button
                      onClick={() => setReporting(review.uuid)}
                      className="inline-flex items-center gap-1 text-xs text-muted-foreground transition hover:text-destructive"
                    >
                      <Flag className="h-3 w-3" /> Report
                    </button>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
