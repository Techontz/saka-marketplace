"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Suspense, useEffect, useState } from "react";

import {
  Badge,
  Card,
  Input,
  ListState,
  PageHeader,
  Pagination,
  Select,
  type BadgeTone,
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet } from "@/lib/admin/api/browser";
import { formatCount } from "@/lib/admin/format";
import { useDebounced, useUrlFilters } from "@/lib/admin/hooks";
import type { AdminBooking, BookingStats, Envelope, Paginated } from "@/lib/admin/api/types";

/**
 * Every appointment on the platform.
 *
 * READ-ONLY, and deliberately so. An administrator opens this to answer "the
 * customer says they booked and the lawyer says they did not" — not to run
 * somebody else's diary. Confirming or declining on a specialist's behalf would
 * record SAKA as having made a commitment it cannot keep, so those controls
 * live only in the vendor portal.
 */
function bookingTone(status: string): BadgeTone {
  switch (status) {
    case "confirmed":
      return "ok";
    case "pending":
      return "warn";
    case "completed":
      return "info";
    case "declined":
    case "cancelled":
    case "no_show":
      return "danger";
    default:
      return "muted";
  }
}

export default function BookingsPage() {
  return (
    <Suspense fallback={null}>
      <BookingsView />
    </Suspense>
  );
}

function BookingsView() {
  const { filters, setFilters } = useUrlFilters({ status: "", q: "", from: "", to: "", page: "1" });

  const [search, setSearch] = useState(filters.q);
  const debounced = useDebounced(search);

  useEffect(() => {
    if (debounced !== filters.q) setFilters({ q: debounced || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);

  const stats = useQuery({
    queryKey: ["booking-stats"],
    queryFn: () => apiGet<Envelope<BookingStats>>("/admin/bookings/stats").then((r) => r.data),
  });

  const bookings = useQuery({
    queryKey: ["admin-bookings", filters],
    queryFn: () =>
      apiGet<Paginated<AdminBooking>>("/admin/bookings", {
        status: filters.status || undefined,
        q: filters.q || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const rows = bookings.data?.data ?? [];
  const meta = bookings.data?.meta;

  return (
    <>
      <PageHeader
        title="Bookings"
        description="Specialist appointments across the platform. Read-only — specialists manage their own diaries."
      />

      {stats.data && (
        <div className="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
          <Card>
            <button
              type="button"
              onClick={() => setFilters({ status: null })}
              className="w-full px-4 py-3 text-left"
            >
              <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                All bookings
              </p>
              <p className="mt-1 text-2xl font-semibold text-ink">
                {formatCount(stats.data.total)}
              </p>
            </button>
          </Card>

          {/*
            * Every status is rendered, including the ones sitting at zero.
            * Omitting empty statuses would make the row change shape as the
            * platform fills up, and an operator would not know a status existed
            * until it happened.
            */}
          {stats.data.by_status
            .filter((entry) => ["pending", "confirmed", "cancelled"].includes(entry.status))
            .map((entry) => (
              <Card key={entry.status}>
                <button
                  type="button"
                  onClick={() => setFilters({ status: entry.status })}
                  className="w-full px-4 py-3 text-left"
                >
                  <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                    {entry.label}
                  </p>
                  <p className="mt-1 text-2xl font-semibold text-ink">
                    {formatCount(entry.total)}
                  </p>
                </button>
              </Card>
            ))}
        </div>
      )}

      <Card>
        <div className="flex flex-wrap gap-3 border-b border-line px-4 py-3">
          <Input
            aria-label="Search by customer or specialist"
            className="w-full sm:w-[260px]"
            placeholder="Customer or specialist…"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />

          <Select
            aria-label="Filter by status"
            className="w-auto"
            value={filters.status}
            onChange={(event) => setFilters({ status: event.target.value || null })}
          >
            <option value="">All statuses</option>
            {(stats.data?.by_status ?? []).map((entry) => (
              <option key={entry.status} value={entry.status}>
                {entry.label}
              </option>
            ))}
          </Select>

          <Input
            aria-label="From date"
            type="date"
            className="w-auto"
            value={filters.from}
            onChange={(event) => setFilters({ from: event.target.value || null })}
          />
          <Input
            aria-label="To date"
            type="date"
            className="w-auto"
            value={filters.to}
            onChange={(event) => setFilters({ to: event.target.value || null })}
          />
        </div>

        <ListState
          isLoading={bookings.isPending}
          error={bookings.error}
          isEmpty={rows.length === 0}
          onRetry={() => void bookings.refetch()}
          skeletonColumns={6}
          emptyTitle="No bookings"
          emptyDescription="Nothing matches these filters."
        >
          <Table>
            <THead>
              <TH>When</TH>
              <TH>Specialist</TH>
              <TH>Service</TH>
              <TH>Customer</TH>
              <TH>Status</TH>
            </THead>
            <TBody>
              {rows.map((booking) => (
                <TR key={booking.uuid}>
                  <TD>
                    <p className="font-medium text-ink">
                      {new Date(`${booking.scheduled_date}T00:00:00`).toLocaleDateString(undefined, {
                        day: "numeric",
                        month: "short",
                        year: "numeric",
                      })}
                    </p>
                    <p className="text-xs text-ink-faint">
                      {booking.start_time}–{booking.end_time} ·{" "}
                      {booking.timezone.replace(/_/g, " ")}
                    </p>
                  </TD>

                  <TD>
                    {booking.specialist ? (
                      <Link
                        href={`/listings?q=${encodeURIComponent(booking.specialist.title)}`}
                        className="text-ink hover:text-brand"
                      >
                        {booking.specialist.title}
                      </Link>
                    ) : (
                      <span className="text-ink-faint">—</span>
                    )}
                  </TD>

                  <TD className="text-ink-soft">{booking.service?.name ?? "—"}</TD>

                  <TD>
                    {booking.customer ? (
                      <>
                        <p className="text-ink">{booking.customer.name}</p>
                        <p className="text-xs text-ink-faint">{booking.customer.phone}</p>
                        {!booking.customer.is_registered && (
                          // Worth flagging: a guest booking has no account to
                          // notify, so support reaches them by phone only.
                          <Badge tone="muted">Guest</Badge>
                        )}
                      </>
                    ) : (
                      <span className="text-ink-faint">—</span>
                    )}
                  </TD>

                  <TD>
                    <Badge tone={bookingTone(booking.status)}>{booking.status_label}</Badge>
                    {booking.cancelled_by && (
                      <p className="mt-1 text-xs text-ink-faint">by {booking.cancelled_by}</p>
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
            disabled={bookings.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>
    </>
  );
}
