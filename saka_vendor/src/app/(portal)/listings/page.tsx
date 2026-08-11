"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Copy, ExternalLink, Megaphone, Plus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
  humanise,
  statusTone,
} from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet, apiSend } from "@/lib/api/browser";
import { marketplaceListingUrl } from "@/lib/config";
import { useUrlFilters } from "@/lib/hooks";
import type { Envelope, ListingDetail, ListingSummary, Paginated } from "@/lib/api/types";
import { useAuth } from "@/providers/AuthProvider";
import { useVendor } from "@/providers/VendorProvider";
import { formatCount, formatMoney } from "@/lib/format";

const STATUSES = ["draft", "pending_review", "published", "paused", "rejected", "expired", "sold", "archived"];

/**
 * The vendor's own inventory.
 *
 * Lifecycle actions are offered per row and derived from the current status —
 * a paused listing gets Resume, a live one gets Pause. Offering every action
 * always and failing on click teaches vendors to distrust the buttons.
 */
export default function ListingsPage() {
  return (
    <Suspense fallback={null}>
      <ListingsView />
    </Suspense>
  );
}

function ListingsView() {
  const queryClient = useQueryClient();
  const router = useRouter();
  const { noun } = useVendor();
  const { canPublish } = useAuth();

  const { filters, setFilters } = useUrlFilters({ status: "", page: "1" });
  const [confirmArchive, setConfirmArchive] = useState<ListingSummary | null>(null);

  const query = useQuery({
    queryKey: ["vendor-listings", filters],
    queryFn: () =>
      apiGet<Paginated<ListingSummary>>("/seller/listings", {
        status: filters.status || undefined,
        page: filters.page,
        per_page: 20,
      }),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["vendor-listings"] });
    await queryClient.invalidateQueries({ queryKey: ["vendor", "dashboard"] });
  };

  const act = useMutation({
    mutationFn: ({ uuid, action }: { uuid: string; action: string }) =>
      apiSend(`/seller/listings/${uuid}/${action}`, "POST"),
    onSuccess: async () => {
      setConfirmArchive(null);
      await invalidate();
    },
  });

  const duplicate = useMutation({
    mutationFn: (uuid: string) =>
      apiSend<Envelope<ListingDetail>>(`/seller/listings/${uuid}/duplicate`, "POST").then((r) => r.data),
    onSuccess: async (copy) => {
      await invalidate();
      // Straight into the copy — duplicating is always the first half of
      // "make a similar one", and leaving them on the list means hunting for
      // the new draft.
      router.push(`/listings/${copy.uuid}`);
    },
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title={noun.plural.charAt(0).toUpperCase() + noun.plural.slice(1)}
        description={`Everything you've listed, live or not.`}
        actions={
          <Link href="/listings/new">
            <Button variant="primary">
              <Plus aria-hidden className="h-4 w-4" />
              New {noun.singular}
            </Button>
          </Link>
        }
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-[200px]">
            <label htmlFor="status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Status
            </label>
            <Select
              id="status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">All</option>
              {STATUSES.map((status) => (
                <option key={status} value={status}>
                  {humanise(status)}
                </option>
              ))}
            </Select>
          </div>
        </div>
      </Card>

      <FormError error={act.error ?? duplicate.error} />

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle={filters.status ? `No ${noun.plural} with that status` : `No ${noun.plural} yet`}
          emptyDescription={
            filters.status
              ? "Try a different status filter."
              : canPublish
                ? `Create your first ${noun.singular} — it takes a couple of minutes.`
                : `You can create a ${noun.singular} now and publish it once your phone is verified.`
          }
          emptyAction={
            !filters.status && (
              <Link href="/listings/new">
                <Button variant="primary">
                  <Plus aria-hidden className="h-4 w-4" />
                  New {noun.singular}
                </Button>
              </Link>
            )
          }
          skeletonColumns={5}
        >
          <Table>
            <THead>
              <TH>{noun.singular.charAt(0).toUpperCase() + noun.singular.slice(1)}</TH>
              <TH>Status</TH>
              <TH align="right">Views</TH>
              <TH align="right">Inquiries</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((listing) => (
                <TR key={listing.uuid}>
                  <TD>
                    <div className="flex items-center gap-3">
                      {listing.primary_image ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          src={listing.primary_image.url}
                          alt=""
                          loading="lazy"
                          className="h-10 w-14 shrink-0 rounded border border-line object-cover"
                        />
                      ) : (
                        <div className="flex h-10 w-14 shrink-0 items-center justify-center rounded border border-line bg-muted-soft text-[10px] text-ink-faint">
                          No photo
                        </div>
                      )}
                      <div className="min-w-0">
                        <Link
                          href={`/listings/${listing.uuid}`}
                          className="font-medium text-ink hover:text-brand"
                        >
                          {listing.title}
                        </Link>
                        <p className="text-xs text-ink-faint">
                          {formatMoney(listing.price) === "Price on request" ? "No price" : formatMoney(listing.price)}
                          {listing.category ? ` · ${listing.category.name}` : ""}
                        </p>
                      </div>
                    </div>
                  </TD>

                  <TD>
                    <Badge tone={statusTone(listing.status)}>{humanise(listing.status)}</Badge>
                    {listing.is_featured && <Badge tone="brand">Featured</Badge>}
                  </TD>

                  <TD align="right" className="text-ink-soft">
                    {formatCount(listing.stats?.views)}
                  </TD>
                  <TD align="right" className="text-ink-soft">
                    {formatCount(listing.stats?.inquiries)}
                  </TD>

                  <TD align="right">
                    <div className="flex flex-wrap justify-end gap-1">
                      {listing.status === "published" && (
                        <>
                          {/* Only rendered when a marketplace origin is
                              configured — a link to `undefined/listings/x` is
                              worse than no link. */}
                          {marketplaceListingUrl(listing.slug) && (
                            <a
                              href={marketplaceListingUrl(listing.slug)!}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="inline-flex h-8 items-center gap-1 rounded-[var(--radius-control)] px-2 text-[13px] text-ink-soft hover:text-ink"
                            >
                              <ExternalLink aria-hidden className="h-3.5 w-3.5" />
                              View
                            </a>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => act.mutate({ uuid: listing.uuid, action: "pause" })}
                          >
                            Pause
                          </Button>
                        </>
                      )}

                      {listing.status === "paused" && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={!canPublish}
                          title={canPublish ? undefined : "Verify your phone to publish"}
                          onClick={() => act.mutate({ uuid: listing.uuid, action: "resume" })}
                        >
                          Resume
                        </Button>
                      )}

                      {listing.status === "draft" && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={!canPublish}
                          title={canPublish ? undefined : "Verify your phone to publish"}
                          onClick={() => act.mutate({ uuid: listing.uuid, action: "submit" })}
                        >
                          Submit
                        </Button>
                      )}

                      {/*
                        * Promote sits with the other row actions as a ghost
                        * button, not as a coloured call to action.
                        *
                        * It is an upsell, and an upsell that outshouts Pause and
                        * Archive on every row turns an inventory screen into an
                        * advertisement for SAKA's own advertising. Only offered
                        * on PUBLISHED listings, because those are the only ones
                        * the API will accept — a Promote button on a draft that
                        * 422s is worse than no button.
                        */}
                      {listing.status === "published" && (
                        <Link href="/promotions">
                          <Button size="sm" variant="ghost">
                            <Megaphone aria-hidden className="h-3.5 w-3.5" />
                            Promote
                          </Button>
                        </Link>
                      )}

                      <Button
                        size="sm"
                        variant="ghost"
                        loading={duplicate.isPending && duplicate.variables === listing.uuid}
                        onClick={() => duplicate.mutate(listing.uuid)}
                      >
                        <Copy aria-hidden className="h-3.5 w-3.5" />
                        Copy
                      </Button>

                      {listing.status !== "archived" && (
                        <Button size="sm" variant="ghost" onClick={() => setConfirmArchive(listing)}>
                          Archive
                        </Button>
                      )}
                    </div>
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

      <Modal
        open={confirmArchive !== null}
        onClose={() => setConfirmArchive(null)}
        title={`Archive "${confirmArchive?.title}"?`}
        description="It comes off the marketplace and stops receiving inquiries. Archiving cannot be undone — copy it first if you might relist."
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmArchive(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={act.isPending}
              onClick={() =>
                confirmArchive && act.mutate({ uuid: confirmArchive.uuid, action: "archive" })
              }
            >
              Archive
            </Button>
          </>
        }
      >
        <FormError error={act.error} />
      </Modal>
    </>
  );
}
