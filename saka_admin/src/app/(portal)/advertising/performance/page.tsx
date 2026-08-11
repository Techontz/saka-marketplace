"use client";

import { useQuery } from "@tanstack/react-query";
import { BarChart3 } from "lucide-react";
import Link from "next/link";
import { Suspense } from "react";

import { Card, ErrorState, Input, PageHeader, TableSkeleton } from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet } from "@/lib/api/browser";
import { formatCount } from "@/lib/format";
import { useUrlFilters } from "@/lib/hooks";
import type { AdvertisingPerformance, Envelope } from "@/lib/api/types";

/**
 * Advertising performance.
 *
 * Every number here is read from `ad_impressions_daily` and `ad_clicks` — the
 * same rows the public beacons write. Nothing is estimated, extrapolated or
 * modelled, and there is no revenue figure anywhere on this screen because
 * SAKA has no billing system to derive one from. A plausible-looking "TZS
 * 2,400,000 earned" would be the most damaging thing on the page.
 *
 * The empty state is the important part of the design. `has_data` comes from
 * the API rather than being inferred from `totals.impressions > 0`, so a
 * marketplace that has sold no advertising sees "no performance data yet"
 * instead of a chart authoritatively asserting that delivery was flat at zero.
 */
export default function PerformancePage() {
  return (
    <Suspense fallback={null}>
      <PerformanceView />
    </Suspense>
  );
}

function PerformanceView() {
  const { filters, setFilters } = useUrlFilters({ from: "", to: "" });

  const performance = useQuery({
    queryKey: ["advertising-performance", filters],
    queryFn: () =>
      apiGet<Envelope<AdvertisingPerformance>>("/admin/advertising/performance", {
        from: filters.from || undefined,
        to: filters.to || undefined,
      }).then((r) => r.data),
  });

  return (
    <>
      <PageHeader
        title="Performance"
        description="Delivery for SAKA's own advertising inventory. Google AdSense reports separately, in the AdSense dashboard."
        actions={
          <div className="flex items-end gap-2">
            <label className="text-xs text-ink-soft">
              <span className="mb-1 block">From</span>
              <Input
                type="date"
                className="h-9 w-auto"
                value={filters.from}
                max={filters.to || undefined}
                onChange={(event) => setFilters({ from: event.target.value || null })}
              />
            </label>
            <label className="text-xs text-ink-soft">
              <span className="mb-1 block">To</span>
              <Input
                type="date"
                className="h-9 w-auto"
                value={filters.to}
                min={filters.from || undefined}
                onChange={(event) => setFilters({ to: event.target.value || null })}
              />
            </label>
          </div>
        }
      />

      {performance.isPending ? (
        <TableSkeleton rows={5} />
      ) : performance.error ? (
        <ErrorState error={performance.error} onRetry={() => void performance.refetch()} />
      ) : !performance.data?.has_data ? (
        <Card>
          <div className="px-6 py-16 text-center">
            <BarChart3 aria-hidden className="mx-auto mb-3 h-8 w-8 text-ink-faint" />
            <p className="text-sm font-medium text-ink">No performance data yet</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-ink-soft">
              Nothing was delivered between {performance.data?.range.from} and{" "}
              {performance.data?.range.to}. Impressions are counted when a unit is actually on
              screen, so a campaign only appears here once visitors have seen it.
            </p>
            <Link
              href="/advertising"
              className="mt-4 inline-block text-sm font-medium text-brand hover:underline"
            >
              Go to campaigns
            </Link>
          </div>
        </Card>
      ) : (
        <PerformanceReport data={performance.data} />
      )}
    </>
  );
}

function PerformanceReport({ data }: { data: AdvertisingPerformance }) {
  // The tallest bar sets the scale. Guarded against zero so a range containing
  // only clicks cannot divide by it.
  const peak = Math.max(1, ...data.series.map((point) => point.impressions));

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-3">
        <Stat label="Impressions" value={formatCount(data.totals.impressions)} />
        <Stat label="Clicks" value={formatCount(data.totals.clicks)} />
        <Stat
          label="Click-through rate"
          value={data.totals.ctr === null ? "—" : `${data.totals.ctr.toFixed(2)}%`}
        />
      </div>

      <Card>
        <div className="border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold text-ink">Daily delivery</h2>
          <p className="text-xs text-ink-soft">
            {data.range.from} to {data.range.to}
          </p>
        </div>

        {/*
          * A plain CSS bar chart rather than the Recharts dependency the other
          * screens use. This is one series of one number per day; pulling in a
          * charting library for it would add a client bundle to a page that
          * needs a list of divs. The table below carries the exact figures,
          * which is what an operator reading a report actually needs.
          */}
        <div className="overflow-x-auto px-4 py-4">
          <div className="flex min-w-[600px] items-end gap-1" style={{ height: 160 }}>
            {data.series.map((point) => (
              <div key={point.date} className="group relative flex flex-1 flex-col justify-end">
                <div
                  className="rounded-t bg-brand/70 transition-colors group-hover:bg-brand"
                  style={{ height: `${Math.max(2, (point.impressions / peak) * 100)}%` }}
                />
                {/*
                  * A title attribute, not a custom tooltip. It is keyboard- and
                  * screen-reader-reachable for free, and the exact numbers are
                  * in the table below for anyone who needs them reliably.
                  */}
                <span className="sr-only">
                  {point.date}: {point.impressions} impressions, {point.clicks} clicks
                </span>
                <div
                  aria-hidden
                  title={`${point.date} — ${formatCount(point.impressions)} impressions, ${formatCount(point.clicks)} clicks`}
                  className="absolute inset-0"
                />
              </div>
            ))}
          </div>
        </div>
      </Card>

      <Card>
        <div className="border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold text-ink">By placement</h2>
        </div>
        <Table>
          <THead>
            <TH>Placement</TH>
            <TH align="right">Impressions</TH>
            <TH align="right">Clicks</TH>
            <TH align="right">CTR</TH>
          </THead>
          <TBody>
            {data.by_placement.map((row) => (
              <TR key={row.placement}>
                <TD>{row.placement_label}</TD>
                <TD align="right">{formatCount(row.impressions)}</TD>
                <TD align="right">{formatCount(row.clicks)}</TD>
                <TD align="right">{row.ctr === null ? "—" : `${row.ctr.toFixed(2)}%`}</TD>
              </TR>
            ))}
          </TBody>
        </Table>
      </Card>

      <Card>
        <div className="border-b border-line px-4 py-3">
          <h2 className="text-sm font-semibold text-ink">Top campaigns</h2>
          <p className="text-xs text-ink-soft">
            Lifetime delivery, not limited to the selected range.
          </p>
        </div>
        <Table>
          <THead>
            <TH>Campaign</TH>
            <TH>Advertiser</TH>
            <TH>Placement</TH>
            <TH align="right">Impressions</TH>
            <TH align="right">Clicks</TH>
            <TH align="right">CTR</TH>
          </THead>
          <TBody>
            {data.top_campaigns.map((row) => (
              <TR key={row.uuid}>
                <TD>
                  <Link href={`/advertising/${row.uuid}`} className="font-medium text-ink hover:text-brand">
                    {row.name}
                  </Link>
                </TD>
                <TD>{row.advertiser ?? "—"}</TD>
                <TD>{row.placement_label}</TD>
                <TD align="right">{formatCount(row.impressions)}</TD>
                <TD align="right">{formatCount(row.clicks)}</TD>
                <TD align="right">{row.ctr === null ? "—" : `${row.ctr.toFixed(2)}%`}</TD>
              </TR>
            ))}
          </TBody>
        </Table>
      </Card>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <div className="px-4 py-3">
        <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">{label}</p>
        <p className="mt-1 text-2xl font-semibold text-ink">{value}</p>
      </div>
    </Card>
  );
}
