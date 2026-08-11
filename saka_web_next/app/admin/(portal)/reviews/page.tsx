"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Star } from "lucide-react";
import { useState } from "react";

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
  Textarea,
} from "@/components/admin/ui";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import type { Paginated, Review } from "@/lib/admin/api/types";

/** The review moderation queue. */
export default function ReviewsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [note, setNote] = useState("");

  const query = useQuery({
    queryKey: ["reviews", "pending", page],
    queryFn: () =>
      apiGet<Paginated<Review>>("/admin/reviews/pending", { page, per_page: 25 }),
  });

  const moderate = useMutation({
    mutationFn: ({ uuid, status, text }: { uuid: string; status: string; text?: string }) =>
      apiSend(`/admin/reviews/${uuid}/moderate`, "POST", { status, note: text }),
    onSuccess: async () => {
      setRejecting(null);
      setNote("");
      await queryClient.invalidateQueries({ queryKey: ["reviews"] });
      await queryClient.invalidateQueries({ queryKey: ["stats"] });
    },
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Reviews"
        description="Reviews awaiting moderation. Approved reviews shape a vendor's public reputation."
      />

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="Nothing to moderate"
          emptyDescription="No reviews are waiting for a decision."
        >
          <ul className="divide-y divide-line">
            {rows.map((review) => (
              <li key={review.uuid} className="p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="mb-1 flex items-center gap-2">
                      <span
                        className="flex items-center gap-0.5"
                        aria-label={`${review.rating} out of 5`}
                      >
                        {Array.from({ length: 5 }).map((_, index) => (
                          <Star
                            key={index}
                            aria-hidden
                            className={
                              index < review.rating
                                ? "h-3.5 w-3.5 fill-warn text-warn"
                                : "h-3.5 w-3.5 text-line-strong"
                            }
                          />
                        ))}
                      </span>
                      <Badge tone="warn">Pending</Badge>
                    </div>

                    {review.title && <p className="font-medium text-ink">{review.title}</p>}
                    {review.body && (
                      <p className="mt-0.5 text-sm whitespace-pre-wrap text-ink-soft">
                        {review.body}
                      </p>
                    )}
                    <p className="mt-1.5 text-xs text-ink-faint">
                      {review.reviewer?.first_name ?? "Anonymous"}
                      {review.created_at
                        ? ` · ${new Date(review.created_at).toLocaleDateString()}`
                        : ""}
                    </p>
                  </div>

                  <div className="flex shrink-0 gap-2">
                    <Button
                      size="sm"
                      variant="primary"
                      loading={moderate.isPending && moderate.variables?.uuid === review.uuid}
                      onClick={() => moderate.mutate({ uuid: review.uuid, status: "approved" })}
                    >
                      Approve
                    </Button>
                    <Button size="sm" variant="danger" onClick={() => setRejecting(review.uuid)}>
                      Reject
                    </Button>
                  </div>
                </div>
              </li>
            ))}
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
            onPage={setPage}
          />
        )}
      </Card>

      <FormError error={moderate.error} />

      <Modal
        open={rejecting !== null}
        onClose={() => setRejecting(null)}
        title="Reject this review"
        description="The note is recorded for the audit trail."
        footer={
          <>
            <Button variant="ghost" onClick={() => setRejecting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={moderate.isPending}
              onClick={() =>
                rejecting &&
                moderate.mutate({ uuid: rejecting, status: "rejected", text: note.trim() || undefined })
              }
            >
              Reject
            </Button>
          </>
        }
      >
        <Field label="Note" hint="Optional, but useful when someone asks later.">
          <Textarea rows={3} value={note} onChange={(event) => setNote(event.target.value)} />
        </Field>
      </Modal>
    </>
  );
}
