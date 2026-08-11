"use client";

import { useQuery } from "@tanstack/react-query";
import { Suspense } from "react";

import { TrendChart } from "@/components/vendor/charts";
import { Card, ErrorState, PageHeader, TableSkeleton } from "@/components/vendor/ui";
import { apiGet } from "@/lib/vendor/api/browser";
import { useUrlFilters } from "@/lib/vendor/hooks";
import type { Envelope, VendorAnalytics } from "@/lib/vendor/api/types";
import { useVendor } from "@/providers/vendor/VendorProvider";
import { formatCount, toNumber } from "@/lib/vendor/format";

const RANGES = [
  { days: "7", label: "7 days" },
  { days: "30", label: "30 days" },
  { days: "90", label: "90 days" },
  { days: "365", label: "12 months" },
];

/**
 * Performance over time.
 *
 * Four series rather than one combined chart: views, favourites, inquiries and
 * reviews differ by orders of magnitude, and plotting them on shared axes would
 * flatten everything but views into a line along the bottom.
 */
export default function AnalyticsPage() {
  return (
    <Suspense fallback={null}>
      <AnalyticsView />
    </Suspense>
  );
}

function AnalyticsView() {
  const { noun } = useVendor();
  const { filters, setFilters } = useUrlFilters({ days: "30" });

  const query = useQuery({
    queryKey: ["vendor-analytics", filters.days],
    queryFn: () =>
      apiGet<Envelope<VendorAnalytics>>("/seller/analytics", { days: filters.days }).then(
        (r) => r.data,
      ),
  });

  const data = query.data;

  return (
    <>
      <PageHeader
        title="Analytics"
        description={`How your ${noun.plural} performed over time.`}
        actions={
          <div
            role="group"
            aria-label="Date range"
            className="flex gap-1 rounded-[var(--radius-control)] border border-line bg-surface p-1"
          >
            {RANGES.map((range) => (
              <button
                key={range.days}
                type="button"
                aria-pressed={filters.days === range.days}
                onClick={() => setFilters({ days: range.days }, { resetPage: false })}
                className={
                  "rounded-[calc(var(--radius-control)-2px)] px-3 py-1 text-[13px] font-medium transition-colors " +
                  (filters.days === range.days
                    ? "bg-brand-soft text-brand-ink"
                    : "text-ink-soft hover:text-ink")
                }
              >
                {range.label}
              </button>
            ))}
          </div>
        }
      />

      {query.isPending ? (
        <Card>
          <TableSkeleton rows={6} />
        </Card>
      ) : query.error || !data ? (
        <Card>
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        </Card>
      ) : (
        <>
          <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Total label="Views" points={data.views} />
            <Total label="Saves" points={data.favorites} />
            <Total label="Inquiries" points={data.inquiries} />
            <Total label="Reviews" points={data.reviews} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <ChartCard
              title="Views"
              subtitle={`How often your ${noun.plural} were opened.`}
              points={data.views}
              color="var(--color-brand)"
            />
            <ChartCard
              title="Inquiries"
              subtitle="Messages from interested buyers."
              points={data.inquiries}
              color="var(--color-ok)"
            />
            <ChartCard
              title="Saves"
              subtitle="Added to someone's favourites."
              points={data.favorites}
              color="var(--color-info)"
            />
            <ChartCard
              title="Reviews"
              subtitle="Left on your listings."
              points={data.reviews}
              color="var(--color-warn)"
            />
          </div>

          <p className="mt-4 text-xs text-ink-faint">
            {data.range.from} to {data.range.to}. View counts are aggregated daily, so today&apos;s
            figure keeps rising until midnight.
          </p>
        </>
      )}
    </>
  );
}

function Total({ label, points }: { label: string; points: { value: number }[] }) {
  const total = (points ?? []).reduce((sum, point) => sum + toNumber(point?.value), 0);

  return (
    <Card>
      <div className="px-5 py-4">
        <p className="text-[13px] text-ink-soft">{label}</p>
        <p className="mt-1 text-2xl font-semibold text-ink">{formatCount(total)}</p>
      </div>
    </Card>
  );
}

function ChartCard({
  title,
  subtitle,
  points,
  color,
}: {
  title: string;
  subtitle: string;
  points: { date: string; value: number }[];
  color: string;
}) {
  const isEmpty = points.every((point) => point.value === 0);

  return (
    <Card>
      <div className="border-b border-line px-5 py-3">
        <h2 className="text-sm font-semibold text-ink">{title}</h2>
        <p className="text-xs text-ink-soft">{subtitle}</p>
      </div>

      <div className="px-2 py-4">
        {isEmpty ? (
          // A flat line at zero looks like a broken chart. Say what it means.
          <p className="px-3 py-12 text-center text-sm text-ink-faint">
            Nothing recorded in this period.
          </p>
        ) : (
          <TrendChart data={points} label={title.toLowerCase()} color={color} />
        )}
      </div>
    </Card>
  );
}
