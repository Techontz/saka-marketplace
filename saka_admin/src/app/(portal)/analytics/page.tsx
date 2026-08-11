"use client";

import { useQuery } from "@tanstack/react-query";
import { Suspense } from "react";

import { CategoryBarChart, TrendChart } from "@/components/charts";
import {
  Badge,
  Card,
  ErrorState,
  ListState,
  PageHeader,
  Select,
} from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet } from "@/lib/api/browser";
import { useUrlFilters } from "@/lib/hooks";
import type { CategoryPopularity, Envelope, Growth, Overview, TopVendor } from "@/lib/api/types";
import { formatCount, formatNumber } from "@/lib/format";

/**
 * Analytics.
 *
 * Every series is the same 30/90/365-day window, chosen once at the top — a
 * page where each chart has its own range control invites comparing two charts
 * that are not comparable.
 */
export default function AnalyticsPage() {
  return (
    <Suspense fallback={null}>
      <AnalyticsView />
    </Suspense>
  );
}

function AnalyticsView() {
  const { filters, setFilters } = useUrlFilters({ days: "30" });
  const days = Number(filters.days) || 30;

  const growth = useQuery({
    queryKey: ["stats", "growth", days],
    queryFn: () => apiGet<Envelope<Growth>>("/admin/stats/growth", { days }).then((r) => r.data),
  });

  const overview = useQuery({
    queryKey: ["stats", "overview"],
    queryFn: () => apiGet<Envelope<Overview>>("/admin/stats/overview").then((r) => r.data),
  });

  const categories = useQuery({
    queryKey: ["stats", "categories"],
    queryFn: () =>
      apiGet<Envelope<CategoryPopularity[]>>("/admin/stats/categories").then((r) => r.data),
  });

  const vendors = useQuery({
    queryKey: ["stats", "vendors"],
    queryFn: () =>
      apiGet<Envelope<TopVendor[]>>("/admin/stats/vendors", { limit: 10 }).then((r) => r.data),
  });

  const series: { key: keyof Growth; label: string; color: string }[] = [
    { key: "listings", label: "Listings created", color: "var(--color-brand)" },
    { key: "published_listings", label: "Listings published", color: "var(--color-ok)" },
    { key: "users", label: "New users", color: "var(--color-info)" },
    { key: "vendors", label: "New vendors", color: "var(--color-warn)" },
    { key: "views", label: "Listing views", color: "var(--color-brand)" },
    { key: "inquiries", label: "Inquiries", color: "var(--color-warn)" },
    { key: "favorites", label: "Favourites", color: "var(--color-danger)" },
    { key: "reviews", label: "Reviews", color: "var(--color-ok)" },
  ];

  return (
    <>
      <PageHeader
        title="Analytics"
        description="Growth and engagement across the platform."
        actions={
          <div className="w-[160px]">
            <Select
              aria-label="Date range"
              value={filters.days}
              onChange={(event) => setFilters({ days: event.target.value }, { resetPage: false })}
            >
              <option value="7">Last 7 days</option>
              <option value="30">Last 30 days</option>
              <option value="90">Last 90 days</option>
              <option value="365">Last year</option>
            </Select>
          </div>
        }
      />

      {overview.data && (
        <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Summary label="Total views" value={overview.data.engagement.views} />
          <Summary label="Inquiries" value={overview.data.engagement.inquiries} />
          <Summary label="Favourites" value={overview.data.engagement.favorites} />
          <Summary
            label="Average rating"
            value={overview.data.engagement.average_rating ?? 0}
            suffix={overview.data.engagement.average_rating !== null ? " / 5" : ""}
            empty={overview.data.engagement.average_rating === null}
          />
        </div>
      )}

      {/*
        "Searches" is a requested metric with no data behind it. The API does
        not log search terms — there is no table, and inventing a chart from
        listing views would be a fabricated number presented as a measurement.
      */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-3 px-5 py-3.5">
          <Badge tone="muted">Not available</Badge>
          <p className="text-sm text-ink-soft">
            <strong className="text-ink">Search analytics</strong> — the API does not record search
            queries, so there is nothing to chart. It needs a search-logging table and a retention
            policy before this can be answered honestly.
          </p>
        </div>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        {series.map((entry) => (
          <Card key={entry.key}>
            <div className="border-b border-line px-5 py-3.5">
              <h2 className="text-sm font-semibold text-ink">{entry.label}</h2>
              <p className="text-xs text-ink-soft">Last {days} days</p>
            </div>
            <div className="px-2 py-4">
              {growth.isPending ? (
                <div className="mx-3 h-[220px] animate-pulse rounded bg-muted-soft/50" />
              ) : growth.error ? (
                <ErrorState error={growth.error} onRetry={() => void growth.refetch()} />
              ) : (
                <TrendChart
                  data={growth.data![entry.key] as never}
                  label={entry.label}
                  color={entry.color}
                />
              )}
            </div>
          </Card>
        ))}
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <div className="border-b border-line px-5 py-3.5">
            <h2 className="text-sm font-semibold text-ink">Category popularity</h2>
          </div>
          <div className="px-2 py-4">
            {categories.isPending ? (
              <div className="mx-3 h-[260px] animate-pulse rounded bg-muted-soft/50" />
            ) : categories.error ? (
              <ErrorState error={categories.error} onRetry={() => void categories.refetch()} />
            ) : (
              <CategoryBarChart data={categories.data!} />
            )}
          </div>
        </Card>

        <Card>
          <div className="border-b border-line px-5 py-3.5">
            <h2 className="text-sm font-semibold text-ink">Most active vendors</h2>
            <p className="text-xs text-ink-soft">By published listings</p>
          </div>

          <ListState
            isLoading={vendors.isPending}
            error={vendors.error}
            isEmpty={(vendors.data?.length ?? 0) === 0}
            onRetry={() => void vendors.refetch()}
            emptyTitle="No vendors yet"
            skeletonColumns={4}
          >
            <Table>
              <THead>
                <TH>Vendor</TH>
                <TH align="right">Listings</TH>
                <TH align="right">Views</TH>
                <TH align="right">Inquiries</TH>
              </THead>
              <TBody>
                {(vendors.data ?? []).map((vendor) => (
                  <TR key={vendor.uuid}>
                    <TD>
                      <span className="font-medium text-ink">{vendor.name}</span>
                      {vendor.is_verified && <Badge tone="ok">Verified</Badge>}
                    </TD>
                    <TD align="right">{formatCount(vendor.listings)}</TD>
                    <TD align="right" className="text-ink-soft">
                      {formatCount(vendor.views)}
                    </TD>
                    <TD align="right" className="text-ink-soft">
                      {formatCount(vendor.inquiries)}
                    </TD>
                  </TR>
                ))}
              </TBody>
            </Table>
          </ListState>
        </Card>
      </div>
    </>
  );
}

function Summary({
  label,
  value,
  suffix = "",
  empty = false,
}: {
  label: string;
  value: number;
  suffix?: string;
  empty?: boolean;
}) {
  return (
    <div className="card p-4">
      <p className="text-xs font-medium text-ink-soft">{label}</p>
      <p className="mt-1.5 text-2xl font-semibold text-ink">
        {empty ? <span className="text-base text-ink-faint">No data yet</span> : `${formatNumber(value)}${suffix}`}
      </p>
    </div>
  );
}
