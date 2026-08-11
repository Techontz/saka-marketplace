"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Loader2, Star, Trash2 } from "lucide-react";

import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet, apiSend } from "@/lib/api/browser";
import { ApiError } from "@/lib/api/errors";
import type { ApiReview, Paginated } from "@/lib/types";

/**
 * Reviews this customer wrote — edit and delete live here.
 *
 * Editing an approved review sends it back for moderation, and the UI says so
 * before the customer commits, rather than letting their review silently
 * disappear from the listing.
 */
export default function MyReviewsPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<string | null>(null);
  const [draft, setDraft] = useState({ rating: 5, title: "", body: "" });
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const reviews = useQuery({
    queryKey: ["my-reviews"],
    queryFn: () => apiGet<Paginated<ApiReview>>("/account/reviews", { per_page: 30 }),
  });

  const update = useMutation({
    mutationFn: (uuid: string) => apiSend(`/account/reviews/${uuid}`, "PATCH", draft),
    onSuccess: async () => {
      setEditing(null);
      await queryClient.invalidateQueries({ queryKey: ["my-reviews"] });
    },
  });

  const remove = useMutation({
    mutationFn: (uuid: string) => apiSend(`/account/reviews/${uuid}`, "DELETE"),
    onSuccess: async () => {
      setConfirmDelete(null);
      await queryClient.invalidateQueries({ queryKey: ["my-reviews"] });
    },
  });

  const rows = reviews.data?.data ?? [];

  return (
    <>
      <h2 className="text-2xl font-extrabold text-navy">My reviews</h2>
      <p className="mt-1 mb-6 text-muted-foreground">What you have said about listings you used.</p>

      {reviews.isPending ? (
        <div className="space-y-3">
          <RowSkeleton count={3} />
        </div>
      ) : reviews.error ? (
        <ErrorState error={reviews.error} onRetry={() => void reviews.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="You haven't reviewed anything yet"
          description="Reviews help the next person decide. You can write one from any listing page."
          icon={<Star className="h-6 w-6" />}
          action={
            <Link href="/listings" className="inline-flex items-center rounded-full bg-teal px-5 py-2 font-semibold text-white">
              Browse listings
            </Link>
          }
        />
      ) : (
        <ul className="space-y-4">
          {rows.map((review) => (
            <li key={review.uuid} className="rounded-xl border border-border bg-white p-5">
              {editing === review.uuid ? (
                <form
                  onSubmit={(event) => {
                    event.preventDefault();
                    update.mutate(review.uuid);
                  }}
                >
                  <div className="mb-3 flex items-center gap-1">
                    {[1, 2, 3, 4, 5].map((value) => (
                      <button
                        key={value}
                        type="button"
                        onClick={() => setDraft({ ...draft, rating: value })}
                        aria-label={`${value} stars`}
                      >
                        <Star
                          className={`h-6 w-6 ${value <= draft.rating ? "fill-orange text-orange" : "text-border"}`}
                        />
                      </button>
                    ))}
                  </div>

                  <input
                    value={draft.title}
                    onChange={(event) => setDraft({ ...draft, title: event.target.value })}
                    placeholder="Title"
                    className="mb-2 w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
                  />
                  <textarea
                    value={draft.body}
                    onChange={(event) => setDraft({ ...draft, body: event.target.value })}
                    rows={4}
                    className="w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
                  />

                  {review.status === "approved" && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Edited reviews are checked again before they go back on the listing.
                    </p>
                  )}

                  {update.error && (
                    <p className="mt-2 text-sm text-destructive">
                      {update.error instanceof ApiError
                        ? (update.error.allFieldMessages()[0] ?? update.error.message)
                        : "That didn't save."}
                    </p>
                  )}

                  <div className="mt-3 flex gap-2">
                    <button
                      type="submit"
                      disabled={update.isPending}
                      className="inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white disabled:opacity-60"
                    >
                      {update.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                      Save changes
                    </button>
                    <button
                      type="button"
                      onClick={() => setEditing(null)}
                      className="rounded-full border border-border px-5 py-2 text-sm font-semibold text-navy"
                    >
                      Cancel
                    </button>
                  </div>
                </form>
              ) : (
                <>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex items-center gap-0.5">
                      {[1, 2, 3, 4, 5].map((value) => (
                        <Star
                          key={value}
                          className={`h-4 w-4 ${value <= review.rating ? "fill-orange text-orange" : "text-border"}`}
                        />
                      ))}
                    </span>

                    <span
                      className={`rounded-full px-3 py-1 text-xs font-semibold ${
                        review.status === "approved"
                          ? "bg-teal/10 text-teal"
                          : review.status === "rejected"
                            ? "bg-destructive/10 text-destructive"
                            : "bg-page text-muted-foreground"
                      }`}
                    >
                      {review.status === "approved"
                        ? "Published"
                        : review.status === "rejected"
                          ? "Not published"
                          : "Awaiting review"}
                    </span>
                  </div>

                  {review.listing && (
                    <Link
                      href={`/listings/${review.listing.slug}`}
                      className="mt-2 block truncate font-semibold text-navy hover:text-teal"
                    >
                      {review.listing.title}
                    </Link>
                  )}

                  {review.title && <p className="mt-1 font-semibold text-navy">{review.title}</p>}
                  {review.body && (
                    <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">{review.body}</p>
                  )}

                  {review.reply && (
                    <div className="mt-3 rounded-lg border-l-2 border-teal bg-teal/5 px-4 py-3">
                      <p className="text-xs font-bold text-teal">Reply from the business</p>
                      <p className="mt-1 text-sm text-muted-foreground">{review.reply.body}</p>
                    </div>
                  )}

                  <div className="mt-3 flex gap-3">
                    <button
                      onClick={() => {
                        setEditing(review.uuid);
                        setDraft({
                          rating: review.rating,
                          title: review.title ?? "",
                          body: review.body ?? "",
                        });
                      }}
                      className="text-sm font-semibold text-teal hover:underline"
                    >
                      Edit
                    </button>

                    {confirmDelete === review.uuid ? (
                      <span className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Delete this review?</span>
                        <button
                          onClick={() => remove.mutate(review.uuid)}
                          disabled={remove.isPending}
                          className="font-semibold text-destructive hover:underline"
                        >
                          Yes, delete
                        </button>
                        <button
                          onClick={() => setConfirmDelete(null)}
                          className="font-semibold text-navy hover:underline"
                        >
                          Keep
                        </button>
                      </span>
                    ) : (
                      <button
                        onClick={() => setConfirmDelete(review.uuid)}
                        className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-destructive"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        Delete
                      </button>
                    )}
                  </div>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
