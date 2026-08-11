"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Megaphone } from "lucide-react";
import { Suspense, useState } from "react";

import {
  Badge,
  Button,
  Card,
  FormError,
  ListState,
  Modal,
  PageHeader,
  Pagination,
  Select,
  type BadgeTone,
} from "@/components/ui";
import { PromotionWizard } from "@/components/promotions/PromotionWizard";
import { apiGet, apiSend } from "@/lib/api/browser";
import { formatCount } from "@/lib/format";
import { useUrlFilters } from "@/lib/hooks";
import type { Paginated, PromotionRequest } from "@/lib/api/types";

/**
 * The vendor's promotions.
 *
 * Everything on this screen is a REQUEST. Nothing a vendor does here puts
 * anything on the marketplace — an administrator reviews the request and then
 * activates the campaign it produces — and the copy is careful to say so at
 * every step. In particular nothing is ever labelled "Active" until the
 * campaign behind it is genuinely serving, and nothing anywhere says "Paid",
 * because SAKA cannot yet take money.
 */

/**
 * Request status -> badge tone.
 *
 * "Approved" is INFO rather than OK, which looks like a small thing and is not.
 * Approval means an administrator accepted the request; the promotion is still
 * a draft campaign nobody has switched on. Green here would tell a vendor their
 * advert is running when it is not, and they would go looking for impressions
 * that cannot exist yet. Green is reserved for `campaign.is_serving`.
 */
function promotionTone(status: string, isServing: boolean): BadgeTone {
  if (isServing) return "ok";

  switch (status) {
    case "approved":
      return "info";
    case "pending":
      return "warn";
    case "rejected":
      return "danger";
    case "draft":
      return "brand";
    default:
      return "muted";
  }
}

export default function PromotionsPage() {
  return (
    <Suspense fallback={null}>
      <PromotionsView />
    </Suspense>
  );
}

function PromotionsView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ status: "", page: "1" });

  const [wizardOpen, setWizardOpen] = useState(false);
  /** Set when the vendor resumes an unfinished draft rather than starting fresh. */
  const [resuming, setResuming] = useState<PromotionRequest | null>(null);
  const [cancelling, setCancelling] = useState<PromotionRequest | null>(null);

  const promotions = useQuery({
    queryKey: ["vendor-promotions", filters],
    queryFn: () =>
      apiGet<Paginated<PromotionRequest>>("/seller/promotions", {
        status: filters.status || undefined,
        page: filters.page,
      }),
  });

  const cancel = useMutation({
    mutationFn: (uuid: string) => apiSend(`/seller/promotions/${uuid}/cancel`, "POST"),
    onSuccess: async () => {
      setCancelling(null);
      await queryClient.invalidateQueries({ queryKey: ["vendor-promotions"] });
    },
  });

  const rows = promotions.data?.data ?? [];
  const meta = promotions.data?.meta;

  return (
    <>
      <PageHeader
        title="Promotions"
        description="Ask for one of your listings to be featured across SAKA. Every request is reviewed before it goes live."
        actions={
          <Button
            variant="primary"
            onClick={() => {
              setResuming(null);
              setWizardOpen(true);
            }}
          >
            <Megaphone aria-hidden className="h-4 w-4" />
            Promote a listing
          </Button>
        }
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-full sm:w-[220px]">
            <label htmlFor="status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Status
            </label>
            <Select
              id="status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">All</option>
              <option value="draft">Draft</option>
              <option value="pending">Pending review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
            </Select>
          </div>
        </div>
      </Card>

      <FormError error={cancel.error} />

      <Card>
        <ListState
          isLoading={promotions.isPending}
          error={promotions.error}
          isEmpty={rows.length === 0}
          onRetry={() => void promotions.refetch()}
          emptyTitle={filters.status ? "Nothing with that status" : "No promotions yet"}
          emptyDescription={
            filters.status
              ? "Try a different status filter."
              : "Promote a published listing to have it featured alongside search results across SAKA."
          }
          emptyAction={
            !filters.status && (
              <Button variant="primary" onClick={() => setWizardOpen(true)}>
                <Megaphone aria-hidden className="h-4 w-4" />
                Promote a listing
              </Button>
            )
          }
        >
          {/*
            * CARDS, not a table.
            *
            * Each row carries artwork, a date range, a status, sometimes a
            * rejection reason and sometimes delivery figures. That is too much
            * for a table on a 360px phone, where it would either scroll
            * sideways or crush the reason nobody would then read.
            */}
          <ul className="divide-y divide-line">
            {rows.map((promotion) => (
              <li key={promotion.uuid} className="p-4">
                <PromotionRow
                  promotion={promotion}
                  onCancel={() => setCancelling(promotion)}
                  onContinue={() => {
                    setResuming(promotion);
                    setWizardOpen(true);
                  }}
                />
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
            disabled={promotions.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>

      {wizardOpen && (
        <PromotionWizard
          resume={resuming}
          onClose={() => {
            setWizardOpen(false);
            setResuming(null);
          }}
          onSubmitted={async () => {
            setWizardOpen(false);
            setResuming(null);
            await queryClient.invalidateQueries({ queryKey: ["vendor-promotions"] });
          }}
        />
      )}

      <Modal
        open={cancelling !== null}
        onClose={() => setCancelling(null)}
        title="Withdraw this request?"
        description={
          cancelling
            ? `"${cancelling.creative.headline}" will no longer be reviewed. You can submit a new request at any time.`
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setCancelling(null)}>
              Keep it
            </Button>
            <Button
              variant="danger"
              loading={cancel.isPending}
              onClick={() => cancelling && cancel.mutate(cancelling.uuid)}
            >
              Withdraw
            </Button>
          </>
        }
      />
    </>
  );
}

function PromotionRow({
  promotion,
  onCancel,
  onContinue,
}: {
  promotion: PromotionRequest;
  onCancel: () => void;
  onContinue: () => void;
}) {
  const isServing = promotion.campaign?.is_serving ?? false;
  const artwork = promotion.creative.image?.url ?? null;

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
      {artwork ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={artwork}
          alt=""
          loading="lazy"
          className="h-20 w-full shrink-0 rounded border border-line object-cover sm:h-14 sm:w-32"
        />
      ) : (
        <div className="flex h-20 w-full shrink-0 items-center justify-center rounded border border-dashed border-line bg-muted-soft text-[11px] text-ink-faint sm:h-14 sm:w-32">
          No artwork
        </div>
      )}

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-sm font-semibold text-ink">{promotion.creative.headline}</p>

          <Badge tone={promotionTone(promotion.status, isServing)}>
            {/*
              * "Live" only when the campaign is genuinely serving. An approved
              * request whose campaign has not been switched on says "Approved",
              * which is the truth.
              */}
            {isServing ? "Live" : promotion.status_label}
          </Badge>
        </div>

        <p className="mt-0.5 truncate text-xs text-ink-soft">
          {promotion.promoted.label ?? "Item no longer available"} · {promotion.placement_label}
        </p>

        <p className="mt-0.5 text-xs text-ink-faint">
          {promotion.requested_start} → {promotion.requested_end}
        </p>

        {promotion.status === "approved" && !isServing && (
          <p className="mt-2 rounded-[var(--radius-control)] bg-info-soft px-3 py-2 text-xs text-info">
            Approved. Your promotion is scheduled and will start once SAKA switches it on.
          </p>
        )}

        {promotion.review.rejection_reason && (
          <p className="mt-2 rounded-[var(--radius-control)] bg-danger-soft px-3 py-2 text-xs text-danger">
            {promotion.review.rejection_reason}
          </p>
        )}

        {/*
          * Delivery, only once there is some.
          *
          * A promotion that has just gone live has genuinely been seen zero
          * times, and "0 impressions" reads as broken. The line appears when
          * there is something to report.
          */}
        {promotion.campaign && promotion.campaign.impressions > 0 && (
          <p className="mt-2 text-xs text-ink-soft">
            {formatCount(promotion.campaign.impressions)} impressions ·{" "}
            {formatCount(promotion.campaign.clicks)} clicks
            {promotion.campaign.ctr !== null && ` · ${promotion.campaign.ctr.toFixed(2)}% CTR`}
          </p>
        )}

        {isServing && promotion.campaign?.impressions === 0 && (
          <p className="mt-2 text-xs text-ink-faint">No performance data yet.</p>
        )}
      </div>

      <div className="flex shrink-0 flex-wrap gap-2">
        {promotion.status === "draft" && (
          <Button size="sm" variant="secondary" onClick={onContinue}>
            Finish
          </Button>
        )}
        {promotion.is_cancellable && (
          <Button size="sm" variant="ghost" onClick={onCancel}>
            Withdraw
          </Button>
        )}
      </div>
    </div>
  );
}
