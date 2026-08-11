"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import type { ReactNode } from "react";

import { CategoryBarChart, TrendChart } from "@/components/admin/charts";
import { Badge, Card, ErrorState, PageHeader, TableSkeleton, humanise } from "@/components/admin/ui";
import { apiGet } from "@/lib/admin/api/browser";
import type {
  AuditEntry,
  CategoryPopularity,
  Envelope,
  Growth,
  Overview,
  Paginated,
} from "@/lib/admin/api/types";
import { formatCount, formatNumber, toNumber } from "@/lib/admin/format";

/**
 * The dashboard.
 *
 * Four independent queries rather than one aggregate call. They have genuinely
 * different cache lifetimes server-side (counters 60s, charts 10 minutes, the
 * audit feed never), and splitting them means a slow chart does not hold up the
 * counters an operator actually opened the page for.
 */
export default function DashboardPage() {
  const overview = useQuery({
    queryKey: ["stats", "overview"],
    queryFn: () => apiGet<Envelope<Overview>>("/admin/stats/overview").then((r) => r.data),
  });

  const growth = useQuery({
    queryKey: ["stats", "growth", 30],
    queryFn: () => apiGet<Envelope<Growth>>("/admin/stats/growth", { days: 30 }).then((r) => r.data),
  });

  const categories = useQuery({
    queryKey: ["stats", "categories"],
    queryFn: () =>
      apiGet<Envelope<CategoryPopularity[]>>("/admin/stats/categories").then((r) => r.data),
  });

  const activity = useQuery({
    queryKey: ["activity", "recent"],
    queryFn: () =>
      apiGet<Paginated<AuditEntry>>("/admin/activity", { per_page: 8 }).then((r) => r.data),
  });

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Platform health at a glance."
      />

      {overview.isPending ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, index) => (
            <div key={index} className="card h-[86px] animate-pulse bg-muted-soft/40" />
          ))}
        </div>
      ) : overview.error ? (
        <Card>
          <ErrorState error={overview.error} onRetry={() => void overview.refetch()} />
        </Card>
      ) : overview.data ? (
        <Stats data={overview.data} />
      ) : null}

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <ChartCard title="Listings created" subtitle="Last 30 days">
          {growth.isPending ? (
            <ChartPlaceholder />
          ) : growth.error ? (
            <ErrorState error={growth.error} onRetry={() => void growth.refetch()} />
          ) : (
            <TrendChart data={growth.data!.listings} label="listings" />
          )}
        </ChartCard>

        <ChartCard title="New users" subtitle="Last 30 days">
          {growth.isPending ? (
            <ChartPlaceholder />
          ) : growth.error ? (
            <ErrorState error={growth.error} onRetry={() => void growth.refetch()} />
          ) : (
            <TrendChart data={growth.data!.users} label="users" color="var(--color-ok)" />
          )}
        </ChartCard>

        <ChartCard title="New vendors" subtitle="Sellers onboarded, last 30 days">
          {growth.isPending ? (
            <ChartPlaceholder />
          ) : growth.error ? (
            <ErrorState error={growth.error} onRetry={() => void growth.refetch()} />
          ) : (
            <TrendChart data={growth.data!.vendors} label="vendors" color="var(--color-info)" />
          )}
        </ChartCard>

        <ChartCard title="Daily activity" subtitle="Inquiries received, last 30 days">
          {growth.isPending ? (
            <ChartPlaceholder />
          ) : growth.error ? (
            <ErrorState error={growth.error} onRetry={() => void growth.refetch()} />
          ) : (
            <TrendChart data={growth.data!.inquiries} label="inquiries" color="var(--color-warn)" />
          )}
        </ChartCard>
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <ChartCard title="Category popularity" subtitle="Published listings per vertical">
          {categories.isPending ? (
            <ChartPlaceholder />
          ) : categories.error ? (
            <ErrorState error={categories.error} onRetry={() => void categories.refetch()} />
          ) : (
            <CategoryBarChart data={categories.data!} />
          )}
        </ChartCard>

        <Card>
          <div className="flex items-center justify-between border-b border-line px-5 py-3.5">
            <div>
              <h2 className="text-sm font-semibold text-ink">Recent activity</h2>
              <p className="text-xs text-ink-soft">Administrative actions, newest first</p>
            </div>
            <Link href="/admin/activity" className="text-xs font-medium text-brand hover:underline">
              View all
            </Link>
          </div>

          {activity.isPending ? (
            <TableSkeleton rows={5} columns={3} />
          ) : activity.error ? (
            <ErrorState error={activity.error} onRetry={() => void activity.refetch()} />
          ) : activity.data!.length === 0 ? (
            <p className="px-5 py-10 text-center text-sm text-ink-soft">
              No administrative actions recorded yet.
            </p>
          ) : (
            <ul className="divide-y divide-line">
              {activity.data!.map((entry) => (
                <li key={entry.id} className="flex items-start gap-3 px-5 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm text-ink">{humanise(entry.action)}</p>
                    <p className="truncate text-xs text-ink-faint">
                      {entry.actor_label ?? "system"}
                      {entry.subject ? ` · ${entry.subject.type} #${entry.subject.id}` : ""}
                    </p>
                  </div>
                  <time
                    dateTime={entry.created_at}
                    className="shrink-0 text-[11px] text-ink-faint"
                  >
                    {new Date(entry.created_at).toLocaleString(undefined, {
                      day: "numeric",
                      month: "short",
                      hour: "2-digit",
                      minute: "2-digit",
                    })}
                  </time>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  );
}

function Stats({ data }: { data: Overview }) {
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Total users" value={data.users.total} hint={`${formatCount(data.users.active)} active`} href="/admin/users" />
        <Stat label="Vendors" value={data.users.vendors} hint={`${formatCount(data.verifications.pending)} awaiting review`} href="/admin/vendors" />
        <Stat label="Listings" value={data.listings.total} hint={`${formatCount(data.listings.published)} published`} href="/admin/listings" />
        <Stat
          label="Pending listings"
          value={data.listings.pending}
          // The one tile that should pull attention — it is a work queue.
          tone={toNumber(data.listings.pending) > 0 ? "warn" : undefined}
          href="/admin/listings?status=pending_review"
        />
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Featured" value={data.listings.featured} href="/admin/listings?featured=1" />
        <Stat
          label="Rejected"
          value={data.listings.rejected}
          tone={toNumber(data.listings.rejected) > 0 ? "danger" : undefined}
          href="/admin/listings?status=rejected"
        />
        <Stat label="Reviews" value={data.engagement.reviews} hint={`${formatCount(data.engagement.pending_reviews)} pending`} href="/admin/reviews" />
        <Stat label="Inquiries" value={data.engagement.inquiries} hint={`${formatCount(data.engagement.unread_inquiries)} unread`} />
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Categories" value={data.catalog.categories} href="/admin/categories" />
        <Stat label="Public places" value={data.catalog.public_places} href="/admin/places" />
        <Stat label="Total views" value={data.engagement.views} />

        {/*
          Revenue is shown as UNAVAILABLE, not as zero. "TZS 0" reads as a
          platform that has taken no money rather than one that does not yet
          take money, and that is the kind of number people screenshot.
        */}
        <div className="card p-4">
          <div className="flex items-center justify-between">
            <p className="text-xs font-medium text-ink-soft">Revenue</p>
            <Badge tone="muted">v2.0</Badge>
          </div>
          <p className="mt-1.5 text-sm text-ink-faint">Not available</p>
          <p className="mt-0.5 text-[11px] leading-snug text-ink-faint">{data.revenue.reason}</p>
        </div>
      </div>
    </div>
  );
}

function Stat({
  label,
  value,
  hint,
  tone,
  href,
}: {
  label: string;
  value: number;
  hint?: string;
  tone?: "warn" | "danger";
  href?: string;
}) {
  const body = (
    <>
      <p className="text-xs font-medium text-ink-soft">{label}</p>
      <p
        className={
          "mt-1.5 text-2xl font-semibold " +
          (tone === "warn" ? "text-warn" : tone === "danger" ? "text-danger" : "text-ink")
        }
      >
        {formatNumber(value)}
      </p>
      {hint && <p className="mt-0.5 text-[11px] text-ink-faint">{hint}</p>}
    </>
  );

  // Every counter that has a screen behind it links to it, pre-filtered —
  // a dashboard number you cannot click through to is a dead end.
  if (href) {
    return (
      <Link href={href} className="card p-4 transition-colors hover:border-line-strong">
        {body}
      </Link>
    );
  }

  return <div className="card p-4">{body}</div>;
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

function ChartPlaceholder() {
  return <div className="mx-3 h-[220px] animate-pulse rounded bg-muted-soft/50" role="status" aria-label="Loading chart" />;
}
