"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { Suspense, useEffect, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  Input,
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
import { ApiError } from "@/lib/api/errors";
import { useDebounced, useUrlFilters } from "@/lib/hooks";
import type { BulkResult, Envelope, ListingSummary, Paginated } from "@/lib/api/types";
import { useAuth } from "@/providers/AuthProvider";
import { formatCount } from "@/lib/format";

const STATUSES = [
  "draft",
  "pending_review",
  "published",
  "rejected",
  "paused",
  "expired",
  "sold",
  "archived",
];

/** Bulk actions, and how each should be presented. */
type BulkAction = {
  value: string;
  label: string;
  /** Renders the Apply button red, so a destructive batch is not one blue click. */
  destructive: boolean;
  needsReason?: boolean;
  permission?: string;
};

const BULK_ACTIONS: BulkAction[] = [
  { value: "approve", label: "Approve", destructive: false },
  { value: "reject", label: "Reject", destructive: true, needsReason: true },
  { value: "archive", label: "Archive", destructive: true },
  { value: "feature", label: "Feature", destructive: false, permission: "listing.feature" },
  { value: "unfeature", label: "Remove feature", destructive: false, permission: "listing.feature" },
  { value: "verify", label: "Mark verified", destructive: false, permission: "listing.verify" },
  { value: "delete", label: "Delete", destructive: true, permission: "listing.delete_any" },
];

export default function ListingsPage() {
  return (
    <Suspense fallback={null}>
      <ListingsView />
    </Suspense>
  );
}

function ListingsView() {
  const queryClient = useQueryClient();
  const { can } = useAuth();

  const { filters, setFilters } = useUrlFilters({
    q: "",
    status: "",
    featured: "",
    trashed: "",
    sort: "updated",
    page: "1",
  });

  const [search, setSearch] = useState(filters.q);
  const debouncedSearch = useDebounced(search);

  // Typing writes to the URL only once the value settles, so history is not
  // polluted with one entry per keystroke.
  useEffect(() => {
    if (debouncedSearch !== filters.q) setFilters({ q: debouncedSearch || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const [selected, setSelected] = useState<string[]>([]);
  const [bulkAction, setBulkAction] = useState<string>("");
  const [bulkReason, setBulkReason] = useState("");
  const [bulkResult, setBulkResult] = useState<BulkResult | null>(null);

  const query = useQuery({
    queryKey: ["admin-listings", filters],
    queryFn: () =>
      apiGet<Paginated<ListingSummary>>("/admin/listings", {
        q: filters.q || undefined,
        status: filters.status || undefined,
        featured: filters.featured || undefined,
        trashed: filters.trashed || undefined,
        sort: filters.sort,
        page: filters.page,
        per_page: 25,
      }),
  });

  const bulk = useMutation({
    mutationFn: (input: { action: string; uuids: string[]; reason?: string }) =>
      apiSend<Envelope<BulkResult>>("/admin/listings/bulk", "POST", input).then((r) => r.data),
    onSuccess: async (result) => {
      setBulkResult(result);
      setSelected([]);
      setBulkAction("");
      setBulkReason("");
      await queryClient.invalidateQueries({ queryKey: ["admin-listings"] });
      await queryClient.invalidateQueries({ queryKey: ["stats"] });
    },
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  const allSelected = rows.length > 0 && selected.length === rows.length;
  const action = BULK_ACTIONS.find((entry) => entry.value === bulkAction);

  return (
    <>
      <PageHeader
        title="Listings"
        description="Every listing on the platform, in any status."
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="min-w-[220px] flex-1">
            <label htmlFor="listing-search" className="mb-1.5 block text-[13px] font-medium text-ink">
              Search
            </label>
            <Input
              id="listing-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Title, slug or UUID"
            />
          </div>

          <div className="w-[170px]">
            <label htmlFor="listing-status" className="mb-1.5 block text-[13px] font-medium text-ink">
              Status
            </label>
            <Select
              id="listing-status"
              value={filters.status}
              onChange={(event) => setFilters({ status: event.target.value || null })}
            >
              <option value="">All statuses</option>
              {STATUSES.map((status) => (
                <option key={status} value={status}>
                  {humanise(status)}
                </option>
              ))}
            </Select>
          </div>

          <div className="w-[160px]">
            <label htmlFor="listing-sort" className="mb-1.5 block text-[13px] font-medium text-ink">
              Sort
            </label>
            <Select
              id="listing-sort"
              value={filters.sort}
              onChange={(event) => setFilters({ sort: event.target.value })}
            >
              <option value="updated">Recently updated</option>
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="price_desc">Price, high to low</option>
              <option value="price_asc">Price, low to high</option>
              <option value="views">Most viewed</option>
            </Select>
          </div>

          <div className="flex items-center gap-4 pb-2.5">
            <Checkbox
              label="Featured only"
              checked={filters.featured === "1"}
              onChange={(event) => setFilters({ featured: event.target.checked ? "1" : null })}
            />
            {/* Deleted listings are hidden by default, so the normal view is
                "what exists" rather than "what ever existed". */}
            <Checkbox
              label="Deleted"
              checked={filters.trashed === "1"}
              onChange={(event) => setFilters({ trashed: event.target.checked ? "1" : null })}
            />
          </div>
        </div>
      </Card>

      {selected.length > 0 && (
        <Card className="mb-4 border-brand/40">
          <div className="flex flex-wrap items-end gap-3 p-4">
            <p className="pb-2.5 text-sm font-medium text-ink">
              {selected.length} selected
            </p>

            <div className="w-[190px]">
              <Select
                aria-label="Bulk action"
                value={bulkAction}
                onChange={(event) => setBulkAction(event.target.value)}
              >
                <option value="">Choose an action…</option>
                {BULK_ACTIONS.filter(
                  (entry) => !entry.permission || can(entry.permission),
                ).map((entry) => (
                  <option key={entry.value} value={entry.value}>
                    {entry.label}
                  </option>
                ))}
              </Select>
            </div>

            {action?.needsReason && (
              <div className="min-w-[220px] flex-1">
                <Input
                  aria-label="Reason"
                  value={bulkReason}
                  onChange={(event) => setBulkReason(event.target.value)}
                  placeholder="Reason (shown to the seller)"
                />
              </div>
            )}

            <div className="flex gap-2 pb-0.5">
              <Button
                variant={action?.destructive ? "danger" : "primary"}
                disabled={!bulkAction}
                loading={bulk.isPending}
                onClick={() =>
                  bulk.mutate({
                    action: bulkAction,
                    uuids: selected,
                    reason: bulkReason || undefined,
                  })
                }
              >
                Apply
              </Button>
              <Button variant="ghost" onClick={() => setSelected([])}>
                Clear
              </Button>
            </div>
          </div>

          {/*
            Permanent deletion is deliberately absent from this menu. It is
            irreversible, super-admin only, and available one listing at a time
            from the detail page — a bulk control for it is how fifty rows
            disappear at once.
          */}
          <p className="border-t border-line px-4 py-2 text-[11px] text-ink-faint">
            Delete is reversible — deleted listings can be restored from the Deleted filter.
          </p>
        </Card>
      )}

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No listings match these filters"
          emptyDescription="Try clearing the search or choosing a different status."
          skeletonColumns={6}
        >
          <Table>
            <THead>
              <TH className="w-10">
                <input
                  type="checkbox"
                  aria-label="Select all on this page"
                  checked={allSelected}
                  onChange={(event) =>
                    setSelected(event.target.checked ? rows.map((row) => row.uuid) : [])
                  }
                  className="h-4 w-4 accent-[var(--color-brand)]"
                />
              </TH>
              <TH>Listing</TH>
              <TH>Status</TH>
              <TH>Category</TH>
              <TH align="right">Price</TH>
              <TH align="right">Views</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((listing) => (
                <TR key={listing.uuid} selected={selected.includes(listing.uuid)}>
                  <TD>
                    <input
                      type="checkbox"
                      aria-label={`Select ${listing.title}`}
                      checked={selected.includes(listing.uuid)}
                      onChange={(event) =>
                        setSelected((current) =>
                          event.target.checked
                            ? [...current, listing.uuid]
                            : current.filter((uuid) => uuid !== listing.uuid),
                        )
                      }
                      className="h-4 w-4 accent-[var(--color-brand)]"
                    />
                  </TD>

                  <TD>
                    <Link
                      href={`/listings/${listing.uuid}`}
                      className="font-medium text-ink hover:text-brand"
                    >
                      {listing.title}
                    </Link>
                    <p className="mt-0.5 text-xs text-ink-faint">
                      {listing.location.address_line ?? listing.location.region ?? "—"}
                    </p>
                  </TD>

                  <TD>
                    <div className="flex flex-wrap items-center gap-1">
                      <Badge tone={statusTone(listing.status)}>{humanise(listing.status)}</Badge>
                      {listing.is_featured && <Badge tone="brand">Featured</Badge>}
                      {listing.is_verified && <Badge tone="info">Verified</Badge>}
                    </div>
                  </TD>

                  <TD className="text-ink-soft">
                    {listing.category?.name ?? "—"}
                    {listing.category?.parent && (
                      <span className="block text-xs text-ink-faint">
                        {listing.category.parent.name}
                      </span>
                    )}
                  </TD>

                  <TD align="right" className="whitespace-nowrap">
                    {listing.price
                      ? `${listing.price.currency} ${listing.price.amount.toLocaleString()}`
                      : "—"}
                  </TD>

                  <TD align="right" className="text-ink-soft">
                    {formatCount(listing.stats?.views)}
                  </TD>

                  <TD align="right">
                    <Link
                      href={`/listings/${listing.uuid}`}
                      className="text-xs font-medium text-brand hover:underline"
                    >
                      Review
                    </Link>
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

      {/*
        Bulk results are reported in full, including partial failure. The API
        processes each listing independently and does NOT roll back, so
        "3 succeeded, 2 failed" is a real outcome that has to be shown — the
        alternative is an operator assuming all five worked.
      */}
      <Modal
        open={bulkResult !== null}
        onClose={() => setBulkResult(null)}
        title={`${bulkResult?.summary.succeeded ?? 0} of ${bulkResult?.summary.requested ?? 0} updated`}
        description={
          bulkResult && bulkResult.summary.failed > 0
            ? "Some listings could not be changed. The rest were applied."
            : undefined
        }
        footer={<Button onClick={() => setBulkResult(null)}>Close</Button>}
      >
        {bulkResult && bulkResult.failed.length > 0 && (
          <ul className="space-y-2">
            {bulkResult.failed.map((failure) => (
              <li key={failure.uuid} className="rounded-[var(--radius-control)] bg-danger-soft px-3 py-2">
                <p className="font-mono text-[11px] text-ink-soft">{failure.uuid}</p>
                <p className="text-sm text-danger">{failure.reason}</p>
              </li>
            ))}
          </ul>
        )}
      </Modal>

      {bulk.error instanceof ApiError && (
        <Modal
          open
          onClose={() => bulk.reset()}
          title="That action could not be applied"
          description={bulk.error.message}
          footer={<Button onClick={() => bulk.reset()}>Close</Button>}
        />
      )}
    </>
  );
}
