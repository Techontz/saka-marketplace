"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowUpRight, Plus } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";

import { TrendChart } from "@/components/vendor/charts";
import { Button, Card, ErrorState, PageHeader } from "@/components/vendor/ui";
import { apiGet } from "@/lib/vendor/api/browser";
import type { Envelope, VendorAnalytics, VendorDashboard } from "@/lib/vendor/api/types";
import { formatCount, formatNumber, toNumber } from "@/lib/vendor/format";
import { useAuth } from "@/providers/vendor/AuthProvider";
import { useVendor } from "@/providers/vendor/VendorProvider";

/**
 * The vendor dashboard.
 *
 * Counters and charts are separate queries: the counters are what a vendor
 * opens the page for, and a slow analytics query should not hold them up.
 */
export default function DashboardPage() {
  const { noun, progress } = useVendor();
  const { canPublish } = useAuth();

  const dashboard = useQuery({
    queryKey: ["vendor", "dashboard"],
    queryFn: () => apiGet<Envelope<VendorDashboard>>("/seller/dashboard").then((r) => r.data),
  });

  const analytics = useQuery({
    queryKey: ["vendor", "analytics", 30],
    queryFn: () =>
      apiGet<Envelope<VendorAnalytics>>("/seller/analytics", { days: 30 }).then((r) => r.data),
  });

  const unreadInquiries = toNumber(dashboard.data?.engagement.unread_inquiries);

  const reported = dashboard.data?.profile_completion;
  const completion =
    typeof reported === "number"
      ? reported
      : (reported?.percent ?? reported?.percentage ?? progress?.percentage ?? 0);

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={`How your ${noun.plural} are doing.`}
        actions={
          <Link href="/vendor/listings/new">
            <Button variant="primary">
              <Plus aria-hidden className="h-4 w-4" />
              New {noun.singular}
            </Button>
          </Link>
        }
      />

      {/*
        Profile completion is a prompt, not a decoration — an incomplete profile
        is the most common reason a vendor's listings underperform. Hidden once
        finished rather than sitting at 100% forever.
      */}
      {completion < 100 && (
        <Card className="mb-4">
          <div className="flex flex-wrap items-center gap-4 p-4">
            <div className="min-w-[180px] flex-1">
              <div className="mb-1.5 flex items-center justify-between">
                <p className="text-sm font-medium text-ink">Finish your business profile</p>
                <span className="text-sm text-ink-soft">{completion}%</span>
              </div>
              <div
                className="h-1.5 overflow-hidden rounded-full bg-muted-soft"
                role="progressbar"
                aria-valuenow={completion}
                aria-valuemin={0}
                aria-valuemax={100}
              >
                <div className="h-full bg-brand transition-all" style={{ width: `${completion}%` }} />
              </div>
            </div>
            <Link href="/vendor/business">
              <Button variant="secondary" size="sm">
                Complete it
                <ArrowUpRight aria-hidden className="h-4 w-4" />
              </Button>
            </Link>
          </div>
        </Card>
      )}

      {dashboard.isPending ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, index) => (
            <div key={index} className="card h-[86px] animate-pulse bg-muted-soft/40" />
          ))}
        </div>
      ) : dashboard.error ? (
        <Card>
          <ErrorState error={dashboard.error} onRetry={() => void dashboard.refetch()} />
        </Card>
      ) : dashboard.data ? (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat
              label={`Total ${noun.plural}`}
              value={dashboard.data.listings.total}
              href="/vendor/listings"
            />
            <Stat
              label="Live"
              value={dashboard.data.listings.active}
              href="/vendor/listings?status=published"
            />
            <Stat
              label="Drafts"
              value={dashboard.data.listings.draft}
              href="/vendor/listings?status=draft"
            />
            <Stat
              label="Awaiting review"
              value={dashboard.data.listings.pending}
              tone={toNumber(dashboard.data.listings.pending) > 0 ? "warn" : undefined}
              href="/vendor/listings?status=pending_review"
            />
          </div>

          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat label="Views" value={dashboard.data.engagement.total_views} />
            <Stat label="Favourites" value={dashboard.data.engagement.total_favorites} />
            <Stat
              label="Inquiries"
              value={dashboard.data.engagement.total_inquiries}
              hint={
                unreadInquiries > 0 ? `${formatCount(unreadInquiries)} unread` : undefined
              }
              tone={unreadInquiries > 0 ? "warn" : undefined}
              href="/vendor/inquiries"
            />
            <Stat
              label="Archived"
              value={dashboard.data.listings.archived}
              href="/vendor/listings?status=archived"
            />
          </div>

          {!canPublish && (
            <p className="text-xs text-ink-faint">
              Drafts stay private until your phone is verified.
            </p>
          )}
        </div>
      ) : null}

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <ChartCard title="Views" subtitle="Last 30 days">
          <Chart data={analytics.data?.views} query={analytics} colour="var(--color-brand)" label="views" />
        </ChartCard>
        <ChartCard title="Favourites" subtitle="Last 30 days">
          <Chart data={analytics.data?.favorites} query={analytics} colour="var(--color-danger)" label="favourites" />
        </ChartCard>
        <ChartCard title="Inquiries" subtitle="Last 30 days">
          <Chart data={analytics.data?.inquiries} query={analytics} colour="var(--color-warn)" label="inquiries" />
        </ChartCard>
        <ChartCard title="Reviews" subtitle="Last 30 days">
          <Chart data={analytics.data?.reviews} query={analytics} colour="var(--color-ok)" label="reviews" />
        </ChartCard>
      </div>
    </>
  );
}

function Chart({
  data,
  query,
  colour,
  label,
}: {
  data: { date: string; value: number }[] | undefined;
  query: { isPending: boolean; error: unknown; refetch: () => unknown };
  colour: string;
  label: string;
}) {
  if (query.isPending) {
    return <div className="mx-3 h-[220px] animate-pulse rounded bg-muted-soft/50" />;
  }

  if (query.error) {
    return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
  }

  return <TrendChart data={data ?? []} label={label} color={colour} />;
}

function Stat({
  label,
  value,
  hint,
  tone,
  href,
}: {
  label: string;
  /** Deliberately permissive: a counter the API omits renders as a dash. */
  value: number | null | undefined;
  hint?: string;
  tone?: "warn";
  href?: string;
}) {
  const body = (
    <>
      <p className="text-xs font-medium text-ink-soft">{label}</p>
      <p className={"mt-1.5 text-2xl font-semibold " + (tone === "warn" ? "text-warn" : "text-ink")}>
        {formatNumber(value)}
      </p>
      {hint && <p className="mt-0.5 text-[11px] text-ink-faint">{hint}</p>}
    </>
  );

  // Every number links to the screen behind it — a dashboard figure you cannot
  // click through to is a dead end.
  return href ? (
    <Link href={href} className="card p-4 transition-colors hover:border-line-strong">
      {body}
    </Link>
  ) : (
    <div className="card p-4">{body}</div>
  );
}

function ChartCard({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
}) {
  return (
    <Card>
      <div className="border-b border-line px-5 py-3.5">
        <h2 className="text-sm font-semibold text-ink">{title}</h2>
        {subtitle && <p className="text-xs text-ink-soft">{subtitle}</p>}
      </div>
      <div className="px-2 py-4">{children}</div>
    </Card>
  );
}
