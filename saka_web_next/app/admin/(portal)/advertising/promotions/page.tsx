"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, ExternalLink } from "lucide-react";
import Link from "next/link";
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
  type BadgeTone,
} from "@/components/admin/ui";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import { useUrlFilters } from "@/lib/admin/hooks";
import type { AdminPromotionRequest, Paginated } from "@/lib/admin/api/types";

/**
 * Vendor promotion requests, for review.
 *
 * Drafts never appear here — a draft is a vendor mid-wizard, and showing other
 * people's unfinished work would be both noise and a small privacy leak.
 *
 * Approval mints a DRAFT campaign rather than putting anything live, so the
 * success path deliberately hands the operator a link to activate it rather
 * than implying the promotion is now running. That two-step is Phase 11A's
 * lifecycle and this screen does not shortcut it.
 */

function requestTone(status: string): BadgeTone {
  switch (status) {
    case "pending":
      return "warn";
    case "approved":
      return "ok";
    case "rejected":
      return "danger";
    default:
      return "muted";
  }
}

export default function PromotionRequestsPage() {
  return (
    <Suspense fallback={null}>
      <PromotionRequestsView />
    </Suspense>
  );
}

function PromotionRequestsView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ status: "pending", page: "1" });

  const [reviewing, setReviewing] = useState<AdminPromotionRequest | null>(null);
  const [rejecting, setRejecting] = useState<AdminPromotionRequest | null>(null);
  const [reason, setReason] = useState("");
  /** Set after an approval, so the operator is told the next step. */
  const [approved, setApproved] = useState<{ campaignUuid: string } | null>(null);

  const requests = useQuery({
    queryKey: ["promotion-requests", filters],
    queryFn: () =>
      apiGet<Paginated<AdminPromotionRequest>>("/admin/promotion-requests", {
        status: filters.status || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["promotion-requests"] });
    // Approval mints a campaign, which the campaign list must now show.
    await queryClient.invalidateQueries({ queryKey: ["ad-campaigns"] });
  };

  const approve = useMutation({
    mutationFn: (uuid: string) =>
      apiSend<{ meta: { campaign_uuid: string } }>(
        `/admin/promotion-requests/${uuid}/approve`,
        "POST",
      ),
    onSuccess: async (response) => {
      setReviewing(null);
      setApproved({ campaignUuid: response.meta.campaign_uuid });
      await invalidate();
    },
  });

  const reject = useMutation({
    mutationFn: ({ uuid, reason: why }: { uuid: string; reason: string }) =>
      apiSend(`/admin/promotion-requests/${uuid}/reject`, "POST", { reason: why }),
    onSuccess: async () => {
      setRejecting(null);
      setReviewing(null);
      setReason("");
      await invalidate();
    },
  });

  const rows = requests.data?.data ?? [];
  const meta = requests.data?.meta;

  return (
    <>
      <PageHeader
        title="Promotion requests"
        description="Vendors asking to promote their own listings. Approving one creates a draft campaign, which you then activate."
      />

      <Card>
        <div className="flex flex-wrap gap-3 border-b border-line px-4 py-3">
          <Select
            aria-label="Filter by status"
            className="w-auto"
            value={filters.status}
            onChange={(event) => setFilters({ status: event.target.value || null })}
          >
            <option value="pending">Awaiting review</option>
            <option value="">All (except drafts)</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Cancelled</option>
            <option value="expired">Expired</option>
          </Select>
        </div>

        <ListState
          isLoading={requests.isPending}
          error={requests.error}
          isEmpty={rows.length === 0}
          onRetry={() => void requests.refetch()}
          emptyTitle={filters.status === "pending" ? "Nothing waiting" : "No requests"}
          emptyDescription={
            filters.status === "pending"
              ? "Every promotion request has been reviewed."
              : "Nothing matches this filter."
          }
        >
          <ul className="divide-y divide-line">
            {rows.map((request) => (
              <li key={request.uuid} className="flex flex-col gap-3 p-4 sm:flex-row">
                {request.creative.image?.url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={request.creative.image.url}
                    alt=""
                    loading="lazy"
                    className="h-20 w-full shrink-0 rounded border border-line object-cover sm:h-16 sm:w-40"
                  />
                ) : (
                  <div className="flex h-20 w-full shrink-0 items-center justify-center rounded border border-dashed border-line bg-muted-soft text-[11px] text-ink-faint sm:h-16 sm:w-40">
                    No artwork
                  </div>
                )}

                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-ink">{request.creative.headline}</p>
                    <Badge tone={requestTone(request.status)}>{request.status_label}</Badge>
                  </div>

                  <p className="mt-0.5 text-xs text-ink-soft">
                    {request.vendor?.name ?? "Unknown vendor"} ·{" "}
                    {request.promoted.label ?? "Item deleted"} · {request.placement_label}
                  </p>

                  <p className="mt-0.5 text-xs text-ink-faint">
                    {request.requested_start} → {request.requested_end}
                  </p>

                  {/*
                    * Why approval would fail, before the operator tries.
                    * Computed server-side from the same facts the approval path
                    * re-checks, so the two cannot disagree.
                    */}
                  {request.blockers.length > 0 && (
                    <ul className="mt-2 space-y-1">
                      {request.blockers.map((blocker) => (
                        <li
                          key={blocker}
                          className="flex items-start gap-1.5 text-xs text-warn"
                        >
                          <AlertTriangle aria-hidden className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                          {blocker}
                        </li>
                      ))}
                    </ul>
                  )}

                  {request.review.rejection_reason && (
                    <p className="mt-2 rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-xs text-danger">
                      {request.review.rejection_reason}
                    </p>
                  )}

                  {request.campaign && (
                    <p className="mt-2 text-xs text-ink-soft">
                      Campaign{" "}
                      <Link
                        href={`/advertising/${request.campaign.uuid}`}
                        className="font-medium text-brand hover:underline"
                      >
                        {request.campaign.status_label}
                      </Link>
                      {!request.campaign.is_serving && " — not yet activated"}
                    </p>
                  )}
                </div>

                {request.is_reviewable && (
                  <div className="flex shrink-0 flex-wrap gap-2 sm:flex-col">
                    <Button size="sm" variant="secondary" onClick={() => setReviewing(request)}>
                      Review
                    </Button>
                  </div>
                )}
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
            disabled={requests.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>

      {/* ---- review ---- */}
      <Modal
        open={reviewing !== null}
        onClose={() => setReviewing(null)}
        title="Review promotion request"
        description={reviewing?.vendor?.name ?? undefined}
        footer={
          <>
            <Button variant="ghost" onClick={() => setReviewing(null)}>
              Close
            </Button>
            <Button
              variant="danger"
              onClick={() => {
                setRejecting(reviewing);
                setReviewing(null);
              }}
            >
              Reject
            </Button>
            <Button
              variant="primary"
              loading={approve.isPending}
              // Blocked requests cannot be approved; the server refuses them
              // too, so offering the button would just produce a 422.
              disabled={(reviewing?.blockers.length ?? 0) > 0}
              onClick={() => reviewing && approve.mutate(reviewing.uuid)}
            >
              Approve
            </Button>
          </>
        }
      >
        {reviewing && (
          <div className="space-y-4">
            <FormError error={approve.error} />

            {reviewing.creative.image?.url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={reviewing.creative.image.url}
                alt=""
                className="w-full rounded border border-line object-cover"
              />
            )}

            <dl className="space-y-2 text-sm">
              <Row label="Headline" value={reviewing.creative.headline} />
              {reviewing.creative.body && <Row label="Body" value={reviewing.creative.body} />}
              {reviewing.creative.cta_label && (
                <Row label="Button" value={reviewing.creative.cta_label} />
              )}
              <Row label="Placement" value={reviewing.placement_label} />
              <Row
                label="Dates"
                value={`${reviewing.requested_start} → ${reviewing.requested_end}`}
              />
              <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
                <dt className="shrink-0 text-xs text-ink-soft sm:w-24 sm:pt-0.5">Links to</dt>
                <dd className="min-w-0 break-words text-sm">
                  {reviewing.promoted.destination_url ? (
                    <a
                      href={reviewing.promoted.destination_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-brand hover:underline"
                    >
                      {reviewing.promoted.destination_url}
                      <ExternalLink aria-hidden className="h-3 w-3" />
                    </a>
                  ) : (
                    "—"
                  )}
                </dd>
              </div>
            </dl>

            {reviewing.blockers.length > 0 && (
              <div className="rounded-[var(--radius-control)] bg-warn-soft px-3 py-2 text-xs text-warn">
                <p className="font-semibold">This cannot be approved yet:</p>
                <ul className="mt-1 list-disc pl-4">
                  {reviewing.blockers.map((blocker) => (
                    <li key={blocker}>{blocker}</li>
                  ))}
                </ul>
              </div>
            )}

            <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
              Approving creates a draft campaign. It will not serve until you activate it.
            </p>
          </div>
        )}
      </Modal>

      {/* ---- reject ---- */}
      <Modal
        open={rejecting !== null}
        onClose={() => {
          setRejecting(null);
          setReason("");
        }}
        title="Reject this request"
        description="The vendor sees this reason, so make it something they can act on."
        footer={
          <>
            <Button
              variant="ghost"
              onClick={() => {
                setRejecting(null);
                setReason("");
              }}
            >
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={reject.isPending}
              // The API requires at least ten characters: "no" is not a reason,
              // and a vendor told only "Rejected" resubmits the same thing.
              disabled={reason.trim().length < 10}
              onClick={() => rejecting && reject.mutate({ uuid: rejecting.uuid, reason })}
            >
              Reject
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <FormError error={reject.error} />
          <Field label="Reason" required hint="At least 10 characters.">
            <Textarea
              rows={3}
              value={reason}
              maxLength={1000}
              placeholder="The artwork covers the headline and is unreadable on mobile."
              onChange={(event) => setReason(event.target.value)}
            />
          </Field>
        </div>
      </Modal>

      {/* ---- approved ---- */}
      <Modal
        open={approved !== null}
        onClose={() => setApproved(null)}
        title="Request approved"
        description="A draft campaign has been created. It will not serve until you activate it."
        footer={
          <>
            <Button variant="ghost" onClick={() => setApproved(null)}>
              Later
            </Button>
            {approved && (
              <Link href={`/advertising/${approved.campaignUuid}`}>
                <Button variant="primary">Open the campaign</Button>
              </Link>
            )}
          </>
        }
      />
    </>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
      <dt className="shrink-0 text-xs text-ink-soft sm:w-24 sm:pt-0.5">{label}</dt>
      <dd className="min-w-0 break-words text-sm text-ink">{value}</dd>
    </div>
  );
}
