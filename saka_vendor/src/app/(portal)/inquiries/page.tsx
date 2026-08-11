"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Mail, Phone } from "lucide-react";
import { Suspense, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Field,
  FormError,
  ListState,
  PageHeader,
  Pagination,
  Select,
  Textarea,
  humanise,
  statusTone,
} from "@/components/ui";
import { apiGet, apiSend } from "@/lib/api/browser";
import { useUrlFilters } from "@/lib/hooks";
import type { Inquiry, Paginated } from "@/lib/api/types";

/**
 * The inquiry inbox.
 *
 * A list-and-detail split rather than a table: an inquiry is a message, and the
 * message body is the point. Replying is inline, because a reply screen you
 * have to navigate to is a reply that gets postponed.
 */
export default function InquiriesPage() {
  return (
    <Suspense fallback={null}>
      <InquiriesView />
    </Suspense>
  );
}

function InquiriesView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ status: "", page: "1" });

  const [replyingTo, setReplyingTo] = useState<string | null>(null);
  const [replyBody, setReplyBody] = useState("");

  const query = useQuery({
    queryKey: ["inquiries", filters],
    queryFn: () =>
      apiGet<Paginated<Inquiry>>("/seller/inquiries", {
        status: filters.status || undefined,
        page: filters.page,
        per_page: 20,
      }),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["inquiries"] });
    await queryClient.invalidateQueries({ queryKey: ["vendor", "dashboard"] });
  };

  const reply = useMutation({
    mutationFn: ({ uuid, body }: { uuid: string; body: string }) =>
      apiSend(`/seller/inquiries/${uuid}/reply`, "POST", { body }),
    onSuccess: async () => {
      setReplyingTo(null);
      setReplyBody("");
      await invalidate();
    },
  });

  const setStatus = useMutation({
    mutationFn: ({ uuid, status }: { uuid: string; status: string }) =>
      apiSend(`/seller/inquiries/${uuid}`, "PATCH", { status }),
    onSuccess: invalidate,
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Inquiries"
        description="Messages from people interested in what you've listed."
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-[200px]">
            <label htmlFor="status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Show
            </label>
            <Select
              id="status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">Everything</option>
              <option value="new">Unread</option>
              <option value="read">Read</option>
              <option value="replied">Replied</option>
              <option value="closed">Resolved</option>
              <option value="spam">Spam</option>
            </Select>
          </div>
        </div>
      </Card>

      <FormError error={setStatus.error} />

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle={filters.status ? "Nothing here" : "No inquiries yet"}
          emptyDescription={
            filters.status
              ? "Try a different filter."
              : "When someone messages you about a listing, it lands here."
          }
        >
          <ul className="divide-y divide-line">
            {rows.map((inquiry) => {
              const name = [inquiry.first_name, inquiry.last_name].filter(Boolean).join(" ");
              const isReplying = replyingTo === inquiry.uuid;

              return (
                <li key={inquiry.uuid} className="p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-ink">{name || "Someone"}</span>
                        <Badge tone={statusTone(inquiry.status)}>{humanise(inquiry.status)}</Badge>
                        {inquiry.created_at && (
                          <span className="text-[11px] text-ink-faint">
                            {new Date(inquiry.created_at).toLocaleString()}
                          </span>
                        )}
                      </div>

                      {inquiry.listing && (
                        <p className="mb-1.5 text-xs text-ink-faint">
                          About: {inquiry.listing.title}
                        </p>
                      )}

                      <p className="text-sm whitespace-pre-wrap text-ink-soft">{inquiry.message}</p>

                      <div className="mt-2 flex flex-wrap gap-3 text-xs">
                        <a
                          href={`mailto:${inquiry.email}`}
                          className="inline-flex items-center gap-1 text-brand hover:underline"
                        >
                          <Mail aria-hidden className="h-3 w-3" />
                          {inquiry.email}
                        </a>
                        {inquiry.phone && (
                          <a
                            href={`tel:${inquiry.phone}`}
                            className="inline-flex items-center gap-1 text-brand hover:underline"
                          >
                            <Phone aria-hidden className="h-3 w-3" />
                            {inquiry.phone}
                          </a>
                        )}
                      </div>

                      {inquiry.reply && (
                        <div className="mt-3 rounded-[var(--radius-control)] border-l-2 border-brand bg-brand-soft/40 px-3 py-2">
                          <p className="text-[11px] font-medium text-brand-ink">
                            Your reply
                            {inquiry.reply.replied_at &&
                              ` · ${new Date(inquiry.reply.replied_at).toLocaleDateString()}`}
                          </p>
                          <p className="mt-0.5 text-sm whitespace-pre-wrap text-ink-soft">
                            {inquiry.reply.body}
                          </p>
                        </div>
                      )}
                    </div>

                    <div className="flex shrink-0 flex-wrap gap-1.5">
                      {!inquiry.reply && (
                        <Button
                          size="sm"
                          variant="primary"
                          onClick={() => {
                            setReplyingTo(isReplying ? null : inquiry.uuid);
                            setReplyBody("");
                          }}
                        >
                          {isReplying ? "Cancel" : "Reply"}
                        </Button>
                      )}

                      {inquiry.status !== "closed" && (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setStatus.mutate({ uuid: inquiry.uuid, status: "closed" })}
                        >
                          Mark resolved
                        </Button>
                      )}

                      {inquiry.status !== "spam" && (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => setStatus.mutate({ uuid: inquiry.uuid, status: "spam" })}
                        >
                          Spam
                        </Button>
                      )}
                    </div>
                  </div>

                  {isReplying && (
                    <div className="mt-3 rounded-[var(--radius-control)] border border-line p-3">
                      <Field label="Your reply" hint="Sent to their email address.">
                        <Textarea
                          rows={4}
                          value={replyBody}
                          onChange={(event) => setReplyBody(event.target.value)}
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
                          onClick={() =>
                            reply.mutate({ uuid: inquiry.uuid, body: replyBody.trim() })
                          }
                        >
                          Send reply
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
    </>
  );
}
