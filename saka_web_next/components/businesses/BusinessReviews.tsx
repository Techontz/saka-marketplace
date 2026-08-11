"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Loader2, Star, Trash2 } from "lucide-react";

import { EmptyState, ErrorState, RowSkeleton } from "@/components/ui/states";
import { apiGet, apiSend } from "@/lib/api/browser";
import { ApiError } from "@/lib/api/errors";
import type { ApiListing, ApiReview, Paginated } from "@/lib/types";
import { useAuth } from "@/providers/AuthProvider";
import { useAuthDialog } from "@/providers/AuthDialogProvider";

/**
 * Rating and reviews for a business.
 *
 * ── Why a review is attached to a LISTING ────────────────────────────────
 * The API has no endpoint for reviewing a seller directly: `reviews` carries
 * both `listing_id` and `seller_id`, and the only write route is
 * `POST /account/reviews/{listing:slug}`. A business's rating is therefore the
 * aggregate of the reviews on its listings — which is also how most
 * marketplaces work, because a review anchored to a real listing is much
 * harder to fake than one attached to a company name.
 *
 * So the form asks which listing the review is about. That is not a
 * workaround: it is the shape of the data, and it makes each review traceable.
 * A direct seller-level endpoint is listed as a backend change in the report.
 */

const EMPTY_DRAFT = { rating: 5, title: "", body: "", listing: "" };

export function BusinessReviews({ slug, businessName }: { slug: string; businessName?: string }) {
  const queryClient = useQueryClient();
  const { isAuthenticated } = useAuth();
  const authDialog = useAuthDialog();

  const [composing, setComposing] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [draft, setDraft] = useState(EMPTY_DRAFT);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const reviews = useQuery({
    queryKey: ["business-reviews", slug],
    queryFn: () => apiGet<Paginated<ApiReview>>(`/businesses/${slug}/reviews`, { per_page: 20 }),
  });

  /** The business's listings — the review has to be about one of them. */
  const listings = useQuery({
    queryKey: ["business-listings-for-review", slug],
    queryFn: () => apiGet<Paginated<ApiListing>>(`/businesses/${slug}/listings`, { per_page: 50 }),
    enabled: composing,
  });

  /** Which of these reviews are mine — the only ones I may edit or delete. */
  const mine = useQuery({
    queryKey: ["my-reviews"],
    queryFn: () => apiGet<Paginated<ApiReview>>("/account/reviews", { per_page: 100 }),
    enabled: isAuthenticated,
    staleTime: 60 * 1000,
  });

  const myUuids = new Set((mine.data?.data ?? []).map((review) => review.uuid));

  const invalidate = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ["business-reviews", slug] }),
      queryClient.invalidateQueries({ queryKey: ["my-reviews"] }),
      queryClient.invalidateQueries({ queryKey: ["business", slug] }),
    ]);
  };

  const create = useMutation({
    mutationFn: () =>
      apiSend(`/account/reviews/${draft.listing}`, "POST", {
        rating: draft.rating,
        title: draft.title || undefined,
        body: draft.body || undefined,
      }),
    onSuccess: async () => {
      setComposing(false);
      setDraft(EMPTY_DRAFT);
      await invalidate();
    },
  });

  const update = useMutation({
    mutationFn: (uuid: string) =>
      apiSend(`/account/reviews/${uuid}`, "PATCH", {
        rating: draft.rating,
        title: draft.title || undefined,
        body: draft.body || undefined,
      }),
    onSuccess: async () => {
      setEditing(null);
      setDraft(EMPTY_DRAFT);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (uuid: string) => apiSend(`/account/reviews/${uuid}`, "DELETE"),
    onSuccess: async () => {
      setConfirmDelete(null);
      await invalidate();
    },
  });

  const rows = reviews.data?.data ?? [];

  // Computed here rather than read from the business payload so the summary
  // always agrees with the list directly beneath it.
  const count = rows.length;
  const average = count === 0 ? 0 : rows.reduce((sum, review) => sum + review.rating, 0) / count;

  const distribution = [5, 4, 3, 2, 1].map((star) => ({
    star,
    total: rows.filter((review) => review.rating === star).length,
  }));

  const pending = create.isPending || update.isPending;
  const error = create.error ?? update.error ?? remove.error;

  const startEdit = (review: ApiReview) => {
    setEditing(review.uuid);
    setComposing(false);
    setDraft({
      rating: review.rating,
      title: review.title ?? "",
      body: review.body ?? "",
      listing: review.listing?.slug ?? "",
    });
  };

  return (
    <section>
      <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
        <h2 className="text-2xl font-extrabold text-navy">
          What customers said{businessName ? ` about ${businessName}` : ""}
        </h2>

        <button
          type="button"
          onClick={() => {
            if (!isAuthenticated) {
              authDialog.open("login", "Sign in to review this business.");
              return;
            }
            setEditing(null);
            setDraft(EMPTY_DRAFT);
            setComposing((open) => !open);
          }}
          className="rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white transition hover:opacity-90"
        >
          {composing ? "Cancel" : "Write a review"}
        </button>
      </div>

      {/* ---------------------------------------------------------- summary */}
      {count > 0 && (
        <div className="mb-6 flex flex-wrap items-center gap-8 rounded-xl border border-border bg-white p-5">
          <div className="text-center">
            <p className="text-4xl font-extrabold text-navy">{average.toFixed(1)}</p>
            <Stars value={Math.round(average)} />
            <p className="mt-1 text-xs text-muted-foreground">
              {count} review{count !== 1 && "s"}
            </p>
          </div>

          <div className="min-w-[200px] flex-1 space-y-1.5">
            {distribution.map(({ star, total }) => (
              <div key={star} className="flex items-center gap-2 text-xs text-muted-foreground">
                <span className="w-3 text-right">{star}</span>
                <Star className="h-3 w-3 fill-orange text-orange" />
                <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-page">
                  <span
                    className="block h-full bg-orange"
                    style={{ width: `${count === 0 ? 0 : (total / count) * 100}%` }}
                  />
                </span>
                <span className="w-6 text-right">{total}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* ------------------------------------------------------ compose form */}
      {(composing || editing) && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            if (editing) update.mutate(editing);
            else create.mutate();
          }}
          className="mb-6 space-y-4 rounded-xl border border-border bg-white p-5"
        >
          <p className="font-bold text-navy">
            {editing ? "Edit your review" : "Rate this business"}
          </p>

          {/* Only when creating: an edit keeps its original listing. */}
          {!editing && (
            <label className="block">
              <span className="mb-1.5 block text-sm font-semibold text-navy">
                Which listing is this about?
              </span>
              <select
                required
                value={draft.listing}
                onChange={(event) => setDraft({ ...draft, listing: event.target.value })}
                className="w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
              >
                <option value="">
                  {listings.isPending ? "Loading listings…" : "Choose a listing"}
                </option>
                {(listings.data?.data ?? []).map((listing) => (
                  <option key={listing.slug} value={listing.slug}>
                    {listing.title}
                  </option>
                ))}
              </select>
            </label>
          )}

          <div>
            <span className="mb-1.5 block text-sm font-semibold text-navy">Your rating</span>
            <div className="flex gap-1">
              {[1, 2, 3, 4, 5].map((value) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => setDraft({ ...draft, rating: value })}
                  aria-label={`${value} star${value !== 1 ? "s" : ""}`}
                  aria-pressed={draft.rating === value}
                  className="transition hover:scale-110"
                >
                  <Star
                    className={`h-7 w-7 ${value <= draft.rating ? "fill-orange text-orange" : "text-border"}`}
                  />
                </button>
              ))}
            </div>
          </div>

          <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-navy">Title</span>
            <input
              value={draft.title}
              onChange={(event) => setDraft({ ...draft, title: event.target.value })}
              maxLength={200}
              placeholder="Sums up your experience"
              className="w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
            />
          </label>

          <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-navy">Your review</span>
            <textarea
              value={draft.body}
              onChange={(event) => setDraft({ ...draft, body: event.target.value })}
              rows={4}
              maxLength={2000}
              placeholder="What was the place, the seller and the process actually like?"
              className="w-full rounded-lg border border-border px-3 py-2 outline-none focus:border-teal"
            />
          </label>

          {error && (
            <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {error instanceof ApiError
                ? (error.allFieldMessages()[0] ?? error.message)
                : "That didn't save. Please try again."}
            </p>
          )}

          <p className="text-xs text-muted-foreground">
            Reviews are moderated before they appear, and editing an approved
            review sends it back for another look.
          </p>

          <div className="flex gap-2">
            <button
              type="submit"
              disabled={pending}
              className="inline-flex items-center gap-2 rounded-full bg-teal px-5 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
            >
              {pending && <Loader2 className="h-4 w-4 animate-spin" />}
              {editing ? "Save changes" : "Post review"}
            </button>
            <button
              type="button"
              onClick={() => {
                setComposing(false);
                setEditing(null);
                setDraft(EMPTY_DRAFT);
              }}
              className="rounded-full border border-border px-5 py-2 text-sm font-semibold text-navy"
            >
              Cancel
            </button>
          </div>
        </form>
      )}

      {/* ------------------------------------------------------------- list */}
      {reviews.isPending ? (
        <div className="space-y-3">
          <RowSkeleton count={2} />
        </div>
      ) : reviews.error ? (
        <ErrorState error={reviews.error} onRetry={() => void reviews.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No reviews yet"
          description="Be the first to rate this business."
          icon={<Star className="h-6 w-6" />}
        />
      ) : (
        <ul className="space-y-4">
          {rows.map((review) => {
            const isMine = myUuids.has(review.uuid);

            return (
              <li key={review.uuid} className="rounded-xl border border-border bg-white p-5">
                <div className="flex flex-wrap items-center gap-2">
                  <Stars value={review.rating} />
                  <span className="text-sm font-bold text-navy">
                    {isMine ? "You" : (review.reviewer?.name ?? "A customer")}
                  </span>
                  {review.listing && (
                    <span className="text-xs text-muted-foreground">on {review.listing.title}</span>
                  )}
                </div>

                {review.title && <p className="mt-2 font-semibold text-navy">{review.title}</p>}
                {review.body && <p className="mt-1 text-sm text-muted-foreground">{review.body}</p>}

                {review.reply && (
                  <div className="mt-3 rounded-lg border-l-2 border-teal bg-teal/5 px-4 py-3">
                    <p className="text-xs font-bold text-teal">Reply from the business</p>
                    <p className="mt-1 text-sm text-muted-foreground">{review.reply.body}</p>
                  </div>
                )}

                {isMine && (
                  <div className="mt-3 flex items-center gap-3 border-t border-border pt-3">
                    <button
                      type="button"
                      onClick={() => startEdit(review)}
                      className="text-sm font-semibold text-teal hover:underline"
                    >
                      Edit
                    </button>

                    {confirmDelete === review.uuid ? (
                      <>
                        <span className="text-sm text-muted-foreground">Delete this review?</span>
                        <button
                          type="button"
                          onClick={() => remove.mutate(review.uuid)}
                          disabled={remove.isPending}
                          className="text-sm font-semibold text-destructive hover:underline"
                        >
                          Yes, delete
                        </button>
                        <button
                          type="button"
                          onClick={() => setConfirmDelete(null)}
                          className="text-sm text-muted-foreground hover:underline"
                        >
                          Keep
                        </button>
                      </>
                    ) : (
                      <button
                        type="button"
                        onClick={() => setConfirmDelete(review.uuid)}
                        className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-destructive"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        Delete
                      </button>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

function Stars({ value }: { value: number }) {
  return (
    <span className="flex items-center gap-0.5" aria-label={`${value} out of 5`}>
      {[1, 2, 3, 4, 5].map((star) => (
        <Star
          key={star}
          className={`h-4 w-4 ${star <= value ? "fill-orange text-orange" : "text-border"}`}
        />
      ))}
    </span>
  );
}
