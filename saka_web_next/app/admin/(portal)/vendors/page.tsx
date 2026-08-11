"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import { useUrlFilters } from "@/lib/admin/hooks";
import type { Paginated, VerificationRequest } from "@/lib/admin/api/types";

type PendingAction = { uuid: string; kind: "reject" | "request-info" };

/**
 * The vendor verification queue.
 *
 * Three exits rather than two. "Request more information" keeps the request
 * PENDING — most real submissions are neither approvable nor rejectable (a
 * cut-off ID photo is not grounds for rejection, and approving it is worse), so
 * without it reviewers were forced to pick the least wrong of two options.
 */
export default function VendorsPage() {
  return (
    <Suspense fallback={null}>
      <VendorsView />
    </Suspense>
  );
}

function VendorsView() {
  const queryClient = useQueryClient();

  const { filters, setFilters } = useUrlFilters({ status: "pending", type: "", page: "1" });

  const [pending, setPending] = useState<PendingAction | null>(null);
  const [message, setMessage] = useState("");

  const query = useQuery({
    queryKey: ["verifications", filters],
    queryFn: () =>
      apiGet<Paginated<VerificationRequest>>("/admin/verifications", {
        status: filters.status || undefined,
        type: filters.type || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["verifications"] });
    await queryClient.invalidateQueries({ queryKey: ["stats"] });
  };

  const approve = useMutation({
    mutationFn: (uuid: string) => apiSend(`/admin/verifications/${uuid}/approve`, "POST"),
    onSuccess: invalidate,
  });

  const decide = useMutation({
    mutationFn: ({ uuid, kind, text }: PendingAction & { text: string }) =>
      kind === "reject"
        ? apiSend(`/admin/verifications/${uuid}/reject`, "POST", { reason: text })
        : apiSend(`/admin/verifications/${uuid}/request-info`, "POST", { message: text }),
    onSuccess: async () => {
      setPending(null);
      setMessage("");
      await invalidate();
    },
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Vendor verification"
        description="Identity and business documents awaiting review. Oldest first — a review queue is a FIFO."
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-[180px]">
            <label htmlFor="vendor-status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Status
            </label>
            <Select
              id="vendor-status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
            </Select>
          </div>

          <div className="w-[190px]">
            <label htmlFor="vendor-type" className="mb-1.5 block text-[13px] font-medium text-ink">
              Document type
            </label>
            <Select
              id="vendor-type"
              value={filters.type}
              onChange={(event) => setFilters({ type: event.target.value || null })}
            >
              <option value="">All types</option>
              <option value="national_id">National ID</option>
              <option value="passport">Passport</option>
              <option value="business">Business</option>
              <option value="address">Address</option>
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
          emptyTitle={filters.status === "pending" ? "The queue is clear" : "Nothing to show"}
          emptyDescription={
            filters.status === "pending"
              ? "No vendor is waiting on a verification decision."
              : "No verification requests match these filters."
          }
        >
          <Table>
            <THead>
              <TH>Vendor</TH>
              <TH>Document</TH>
              <TH>Status</TH>
              <TH>Submitted</TH>
              <TH align="right">Decision</TH>
            </THead>
            <TBody>
              {rows.map((request) => (
                <TR key={request.uuid}>
                  <TD>
                    <p className="font-medium text-ink">{request.user.name}</p>
                    <p className="text-xs text-ink-faint">{request.user.email}</p>
                    {!request.user.phone_verified && (
                      <Badge tone="warn">Phone unverified</Badge>
                    )}
                  </TD>

                  <TD>
                    <p className="text-ink">{humanise(request.type)}</p>
                    {request.document_number && (
                      <p className="font-mono text-xs text-ink-faint">
                        {request.document_number}
                      </p>
                    )}

                    {/*
                      * Said explicitly on the row where the decision is made.
                      *
                      * There is no automated NIDA check to lean on, so the
                      * reviewer needs to know the responsibility is entirely
                      * theirs — an absent robot verdict silently defaulting to
                      * "nothing flagged" is how a queue gets rubber-stamped.
                      */}
                    {request.automated_check && (
                      <p className="text-[11px] text-ink-faint">
                        No automated check available — verify by eye
                      </p>
                    )}
                    {request.document_url ? (
                      <a
                        href={request.document_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs font-medium text-brand hover:underline"
                      >
                        View document
                      </a>
                    ) : (
                      <span className="text-xs text-ink-faint">No document attached</span>
                    )}
                  </TD>

                  <TD>
                    {/* Waiting on the VENDOR reads differently from waiting on
                        us, and a queue that shows both as "Pending" hides the
                        difference from whoever picks it up next. */}
                    <Badge tone={request.needs_correction ? "warn" : statusTone(request.status)}>
                      {request.needs_correction ? "Awaiting vendor" : humanise(request.status)}
                    </Badge>
                    {request.rejection_reason && (
                      <p className="mt-1 max-w-[220px] text-xs text-ink-soft">
                        {request.rejection_reason}
                      </p>
                    )}
                  </TD>

                  <TD className="text-ink-soft whitespace-nowrap">
                    {new Date(request.created_at).toLocaleDateString()}
                  </TD>

                  <TD align="right">
                    {request.status === "pending" ? (
                      <div className="flex justify-end gap-1.5">
                        <Button
                          size="sm"
                          variant="primary"
                          loading={approve.isPending && approve.variables === request.uuid}
                          onClick={() => approve.mutate(request.uuid)}
                        >
                          Approve
                        </Button>
                        <Button
                          size="sm"
                          variant="secondary"
                          onClick={() => setPending({ uuid: request.uuid, kind: "request-info" })}
                        >
                          Ask for more
                        </Button>
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => setPending({ uuid: request.uuid, kind: "reject" })}
                        >
                          Reject
                        </Button>
                      </div>
                    ) : (
                      <span className="text-xs text-ink-faint">
                        {request.reviewed_at
                          ? `Reviewed ${new Date(request.reviewed_at).toLocaleDateString()}`
                          : "—"}
                      </span>
                    )}
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
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

      <FormError error={approve.error} />

      <Modal
        open={pending !== null}
        onClose={() => setPending(null)}
        title={pending?.kind === "reject" ? "Reject this verification" : "Request more information"}
        description={
          pending?.kind === "reject"
            ? "The vendor is told why, and the request is closed."
            : "The request stays in the queue and can still be approved or rejected."
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setPending(null)}>
              Cancel
            </Button>
            <Button
              variant={pending?.kind === "reject" ? "danger" : "primary"}
              loading={decide.isPending}
              disabled={message.trim().length < 10}
              onClick={() => pending && decide.mutate({ ...pending, text: message.trim() })}
            >
              {pending?.kind === "reject" ? "Reject" : "Send request"}
            </Button>
          </>
        }
      >
        <Field
          label={pending?.kind === "reject" ? "Reason" : "What do you need?"}
          required
          hint="At least 10 characters. Shown to the vendor."
        >
          <Textarea rows={4} value={message} onChange={(event) => setMessage(event.target.value)} />
        </Field>
        <FormError error={decide.error} />
      </Modal>
    </>
  );
}
