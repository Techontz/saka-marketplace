"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";

import {
  Badge,
  Button,
  Card,
  ErrorState,
  Field,
  FormError,
  Modal,
  PageHeader,
  TableSkeleton,
  Textarea,
  humanise,
  statusTone,
} from "@/components/ui";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { Envelope, ListingDetail } from "@/lib/api/types";
import { useAuth } from "@/providers/AuthProvider";
import { formatNumber } from "@/lib/format";

/**
 * One listing, with the moderation controls that apply to it.
 *
 * The action buttons are derived from what the API says is REACHABLE, not from
 * a hardcoded list. A 409 from an illegal transition carries `details.allowed`,
 * and the same transition table is mirrored here so the buttons offered are the
 * ones that will work — offering "Publish" on an archived listing and then
 * showing an error is a worse experience than not offering it.
 */

/** Mirrors ListingStatus::allowedTransitions() in the API. */
const TRANSITIONS: Record<string, string[]> = {
  draft: ["pending_review", "archived"],
  pending_review: ["published", "rejected", "draft", "archived"],
  published: ["paused", "sold", "expired", "archived"],
  rejected: ["draft", "archived"],
  paused: ["published", "archived"],
  expired: ["pending_review", "archived"],
  sold: ["archived"],
  archived: [],
};

const TRANSITION_LABELS: Record<string, string> = {
  published: "Approve & publish",
  rejected: "Reject",
  archived: "Archive",
  draft: "Send back to draft",
  pending_review: "Send for review",
  paused: "Pause",
  sold: "Mark sold",
  expired: "Mark expired",
};

/** Transitions that must not be one click away. */
const NEEDS_REASON = new Set(["rejected"]);

export default function ListingDetailPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const router = useRouter();
  const queryClient = useQueryClient();
  const { can, isSuperAdmin } = useAuth();

  const [pendingTransition, setPendingTransition] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [confirmForce, setConfirmForce] = useState(false);

  const query = useQuery({
    queryKey: ["admin-listing", uuid],
    queryFn: () => apiGet<Envelope<ListingDetail>>(`/admin/listings/${uuid}`).then((r) => r.data),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["admin-listing", uuid] });
    await queryClient.invalidateQueries({ queryKey: ["admin-listings"] });
    await queryClient.invalidateQueries({ queryKey: ["stats"] });
  };

  const transition = useMutation({
    mutationFn: (input: { status: string; reason?: string }) =>
      apiSend(`/admin/listings/${uuid}/transition`, "POST", input),
    onSuccess: async () => {
      setPendingTransition(null);
      setReason("");
      await invalidate();
    },
  });

  const feature = useMutation({
    mutationFn: (featured: boolean) =>
      apiSend(`/admin/listings/${uuid}/feature`, "POST", { featured }),
    onSuccess: invalidate,
  });

  const verify = useMutation({
    mutationFn: () => apiSend(`/admin/listings/${uuid}/verify`, "POST", { verified: true }),
    onSuccess: invalidate,
  });

  const softDelete = useMutation({
    mutationFn: () => apiSend(`/admin/listings/${uuid}`, "DELETE"),
    onSuccess: invalidate,
  });

  const restore = useMutation({
    mutationFn: () => apiSend(`/admin/listings/${uuid}/restore`, "POST"),
    onSuccess: invalidate,
  });

  const forceDelete = useMutation({
    mutationFn: () => apiSend(`/admin/listings/${uuid}/force`, "DELETE"),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["admin-listings"] });
      // The listing no longer exists, so there is nothing to return to.
      router.replace("/listings");
    },
  });

  if (query.isPending) {
    return (
      <Card>
        <TableSkeleton rows={8} />
      </Card>
    );
  }

  if (query.error) {
    return (
      <Card>
        <ErrorState
          error={query.error}
          onRetry={() => void query.refetch()}
          title="We couldn't load this listing"
        />
      </Card>
    );
  }

  const listing = query.data!;
  const available = TRANSITIONS[listing.status] ?? [];
  const busy =
    transition.isPending ||
    feature.isPending ||
    verify.isPending ||
    softDelete.isPending ||
    restore.isPending;

  return (
    <>
      <Link
        href="/listings"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink"
      >
        <ArrowLeft aria-hidden className="h-4 w-4" />
        Back to listings
      </Link>

      <PageHeader
        title={listing.title}
        description={listing.location.address_line ?? listing.location.region ?? undefined}
        actions={
          <div className="flex flex-wrap gap-2">
            {listing.deleted_at ? (
              <Button
                variant="primary"
                loading={restore.isPending}
                onClick={() => restore.mutate()}
              >
                Restore
              </Button>
            ) : (
              <>
                {available.map((status) => (
                  <Button
                    key={status}
                    variant={status === "rejected" || status === "archived" ? "danger" : "primary"}
                    disabled={busy}
                    onClick={() => {
                      if (NEEDS_REASON.has(status)) setPendingTransition(status);
                      else transition.mutate({ status });
                    }}
                  >
                    {TRANSITION_LABELS[status] ?? humanise(status)}
                  </Button>
                ))}

                {can("listing.feature") && (
                  <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => feature.mutate(!listing.is_featured)}
                  >
                    {listing.is_featured ? "Remove feature" : "Feature"}
                  </Button>
                )}

                {can("listing.verify") && !listing.is_verified && (
                  <Button variant="secondary" disabled={busy} onClick={() => verify.mutate()}>
                    Mark verified
                  </Button>
                )}

                {can("listing.delete_any") && (
                  <Button
                    variant="danger"
                    loading={softDelete.isPending}
                    onClick={() => softDelete.mutate()}
                  >
                    Delete
                  </Button>
                )}
              </>
            )}
          </div>
        }
      />

      {listing.deleted_at && (
        <div className="mb-4 rounded-[var(--radius-card)] border border-danger/30 bg-danger-soft px-4 py-3">
          <p className="text-sm font-medium text-danger">This listing is deleted</p>
          <p className="mt-0.5 text-xs text-ink-soft">
            It is hidden from the marketplace and every index. Restoring puts it back in the
            status it had.
          </p>
        </div>
      )}

      <FormError error={transition.error ?? feature.error ?? softDelete.error ?? restore.error} />

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card>
            <SectionTitle>Details</SectionTitle>
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-2">
              <Detail label="Status">
                <Badge tone={statusTone(listing.status)}>{humanise(listing.status)}</Badge>
              </Detail>
              <Detail label="Price">
                {listing.price
                  ? `${listing.price.currency} ${listing.price.amount.toLocaleString()}${
                      listing.price.unit && listing.price.unit !== "total"
                        ? ` / ${listing.price.unit}`
                        : ""
                    }`
                  : "—"}
              </Detail>
              <Detail label="Category">
                {listing.category
                  ? `${listing.category.parent ? `${listing.category.parent.name} → ` : ""}${listing.category.name}`
                  : "—"}
              </Detail>
              <Detail label="Purpose">{listing.purpose ? humanise(listing.purpose) : "—"}</Detail>
              <Detail label="Seller">
                {listing.seller?.display_name ?? "—"}
                {listing.seller?.is_verified && (
                  <Badge tone="info">Verified</Badge>
                )}
              </Detail>
              <Detail label="Created">
                {listing.created_at ? new Date(listing.created_at).toLocaleString() : "—"}
              </Detail>
            </dl>

            {listing.description && (
              <div className="border-t border-line px-5 py-4">
                <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                  Description
                </p>
                <p className="text-sm whitespace-pre-wrap text-ink-soft">{listing.description}</p>
              </div>
            )}

            {listing.rejection_reason && (
              <div className="border-t border-line px-5 py-4">
                <p className="mb-1 text-[11px] font-semibold tracking-wide text-danger uppercase">
                  Rejection reason
                </p>
                <p className="text-sm text-ink-soft">{listing.rejection_reason}</p>
              </div>
            )}
          </Card>

          {listing.images.length > 0 && (
            <Card>
              <SectionTitle>Images ({listing.images.length})</SectionTitle>
              <div className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                {listing.images.map((image) => (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    key={image.uuid}
                    src={image.url}
                    alt=""
                    loading="lazy"
                    className="aspect-[4/3] w-full rounded-[var(--radius-control)] border border-line object-cover"
                  />
                ))}
              </div>
            </Card>
          )}

          {(listing.amenities.length > 0 || listing.facilities.length > 0) && (
            <Card>
              <SectionTitle>Amenities & facilities</SectionTitle>
              <div className="flex flex-wrap gap-1.5 p-4">
                {listing.amenities.map((item) => (
                  <Badge key={item.slug} tone="muted">
                    {item.name}
                  </Badge>
                ))}
                {listing.facilities.map((item) => (
                  <Badge key={item.slug} tone="info">
                    {item.name}
                  </Badge>
                ))}
              </div>
            </Card>
          )}
        </div>

        <div className="space-y-4">
          <Card>
            <SectionTitle>Engagement</SectionTitle>
            <dl className="grid grid-cols-3 gap-2 px-5 py-4 text-center">
              <Metric label="Views" value={listing.stats.views} />
              <Metric label="Favourites" value={listing.stats.favorites} />
              <Metric label="Inquiries" value={listing.stats.inquiries} />
            </dl>
          </Card>

          <Card>
            <SectionTitle>Status history</SectionTitle>
            {listing.status_history.length === 0 ? (
              <p className="px-5 py-6 text-center text-sm text-ink-soft">
                No recorded status changes.
              </p>
            ) : (
              <ol className="divide-y divide-line">
                {listing.status_history.map((entry, index) => (
                  <li key={index} className="px-5 py-3">
                    <p className="text-sm text-ink">
                      {entry.from ? `${humanise(entry.from)} → ` : ""}
                      <span className="font-medium">{humanise(entry.to)}</span>
                    </p>
                    {entry.reason && (
                      <p className="mt-0.5 text-xs text-ink-soft">{entry.reason}</p>
                    )}
                    <p className="mt-0.5 text-[11px] text-ink-faint">
                      {new Date(entry.at).toLocaleString()}
                    </p>
                  </li>
                ))}
              </ol>
            )}
          </Card>

          {/*
            Permanent deletion sits apart from every other control, behind its
            own confirmation, and only for a super admin. Everything else on
            this page is reversible; this is not.
          */}
          {isSuperAdmin && (
            <Card className="border-danger/30">
              <SectionTitle>Danger zone</SectionTitle>
              <div className="px-5 py-4">
                <p className="text-sm text-ink-soft">
                  Permanently delete this listing and its record. This cannot be undone, and the
                  only trace left will be the audit entry.
                </p>
                <Button
                  variant="danger"
                  className="mt-3"
                  onClick={() => setConfirmForce(true)}
                >
                  Delete permanently
                </Button>
              </div>
            </Card>
          )}
        </div>
      </div>

      <Modal
        open={pendingTransition !== null}
        onClose={() => setPendingTransition(null)}
        title={pendingTransition ? (TRANSITION_LABELS[pendingTransition] ?? "Change status") : ""}
        description="The reason is recorded in the status history and shown to the seller."
        footer={
          <>
            <Button variant="ghost" onClick={() => setPendingTransition(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={transition.isPending}
              // A rejection with no explanation is one the seller cannot act on.
              disabled={reason.trim().length < 5}
              onClick={() =>
                pendingTransition &&
                transition.mutate({ status: pendingTransition, reason: reason.trim() })
              }
            >
              Confirm
            </Button>
          </>
        }
      >
        <Field label="Reason" required hint="At least 5 characters.">
          <Textarea rows={4} value={reason} onChange={(event) => setReason(event.target.value)} />
        </Field>
        <FormError error={transition.error} />
      </Modal>

      <Modal
        open={confirmForce}
        onClose={() => setConfirmForce(false)}
        title="Permanently delete this listing?"
        description="This removes the row entirely. It cannot be restored."
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmForce(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={forceDelete.isPending}
              onClick={() => forceDelete.mutate()}
            >
              Delete permanently
            </Button>
          </>
        }
      >
        <p className="text-sm text-ink-soft">
          Consider <strong>Delete</strong> instead — it hides the listing everywhere and can be
          undone.
        </p>
        <FormError error={forceDelete.error} />
      </Modal>
    </>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <div className="border-b border-line px-5 py-3">
      <h2 className="text-sm font-semibold text-ink">{children}</h2>
    </div>
  );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-[11px] font-semibold tracking-wide text-ink-faint uppercase">{label}</dt>
      <dd className="mt-0.5 flex flex-wrap items-center gap-1.5 text-sm text-ink">{children}</dd>
    </div>
  );
}

function Metric({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <dt className="text-[11px] text-ink-faint">{label}</dt>
      <dd className="text-lg font-semibold text-ink">{formatNumber(value)}</dd>
    </div>
  );
}
