"use client";

import { useQuery } from "@tanstack/react-query";
import { Suspense, useState } from "react";

import {
  Badge,
  Card,
  Input,
  ListState,
  Modal,
  PageHeader,
  Pagination,
  humanise,
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet } from "@/lib/admin/api/browser";
import { useUrlFilters } from "@/lib/admin/hooks";
import type { AuditEntry, Paginated } from "@/lib/admin/api/types";

/**
 * The audit trail.
 *
 * Append-only and hash-chained server-side: each entry's `prev_hash` is its
 * predecessor's `hash`, so a row edited or deleted directly in the database
 * breaks the chain from that point on. Nothing here can edit an entry, and
 * there is deliberately no delete control.
 */
export default function ActivityPage() {
  return (
    <Suspense fallback={null}>
      <ActivityView />
    </Suspense>
  );
}

function ActivityView() {
  const { filters, setFilters } = useUrlFilters({ action: "", actor: "", page: "1" });
  const [inspecting, setInspecting] = useState<AuditEntry | null>(null);

  const query = useQuery({
    queryKey: ["activity", filters],
    queryFn: () =>
      apiGet<Paginated<AuditEntry>>("/admin/activity", {
        action: filters.action || undefined,
        actor: filters.actor || undefined,
        page: filters.page,
        per_page: 30,
      }),
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <PageHeader
        title="Activity log"
        description="Every administrative action, newest first. Entries cannot be edited or removed."
      />

      <Card className="mb-4">
        <div className="flex flex-wrap items-end gap-3 p-4">
          <div className="w-[240px]">
            <label htmlFor="activity-action" className="mb-1.5 block text-[13px] font-medium text-ink">
              Action
            </label>
            <Input
              id="activity-action"
              defaultValue={filters.action}
              placeholder="e.g. listing.status_changed"
              onBlur={(event) => setFilters({ action: event.target.value || null })}
            />
          </div>
          <div className="w-[240px]">
            <label htmlFor="activity-actor" className="mb-1.5 block text-[13px] font-medium text-ink">
              Actor email
            </label>
            <Input
              id="activity-actor"
              defaultValue={filters.actor}
              placeholder="admin@saka.africa"
              onBlur={(event) => setFilters({ actor: event.target.value || null })}
            />
          </div>
        </div>
      </Card>

      <Card>
        <ListState
          isLoading={query.isPending}
          error={query.error}
          isEmpty={rows.length === 0}
          onRetry={() => void query.refetch()}
          emptyTitle="No activity recorded"
          emptyDescription="Administrative actions appear here as they happen."
        >
          <Table>
            <THead>
              <TH>When</TH>
              <TH>Action</TH>
              <TH>Actor</TH>
              <TH>Subject</TH>
              <TH>IP</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((entry) => (
                <TR key={entry.id} onClick={() => setInspecting(entry)}>
                  <TD className="whitespace-nowrap text-ink-soft">
                    {new Date(entry.created_at).toLocaleString(undefined, {
                      day: "numeric",
                      month: "short",
                      hour: "2-digit",
                      minute: "2-digit",
                      second: "2-digit",
                    })}
                  </TD>
                  <TD>
                    <Badge tone={entry.action.includes("delete") ? "danger" : "muted"}>
                      {entry.action}
                    </Badge>
                  </TD>
                  <TD className="text-ink-soft">{entry.actor_label ?? "system"}</TD>
                  <TD className="text-ink-soft">
                    {entry.subject ? `${entry.subject.type} #${entry.subject.id}` : "—"}
                  </TD>
                  <TD className="font-mono text-xs text-ink-faint">{entry.ip_address ?? "—"}</TD>
                  <TD align="right">
                    <span className="text-xs font-medium text-brand">Inspect</span>
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
        open={inspecting !== null}
        onClose={() => setInspecting(null)}
        title={inspecting ? humanise(inspecting.action) : ""}
        description={
          inspecting
            ? `${inspecting.actor_label ?? "system"} · ${new Date(inspecting.created_at).toLocaleString()}`
            : undefined
        }
      >
        {inspecting && (
          <div className="space-y-4 text-sm">
            {inspecting.request_id && (
              <p className="font-mono text-[11px] text-ink-faint">
                Request {inspecting.request_id}
              </p>
            )}

            {/* Before/after side by side is what makes an audit entry useful —
                "roles changed" without the values answers nothing. */}
            <div className="grid gap-3 sm:grid-cols-2">
              <DiffPane title="Before" data={inspecting.previous} />
              <DiffPane title="After" data={inspecting.changes} />
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}

function DiffPane({ title, data }: { title: string; data: Record<string, unknown> | null }) {
  return (
    <div>
      <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
        {title}
      </p>
      {data && Object.keys(data).length > 0 ? (
        <pre className="max-h-52 overflow-auto rounded-[var(--radius-control)] bg-muted-soft p-3 font-mono text-[11px] text-ink-soft">
          {JSON.stringify(data, null, 2)}
        </pre>
      ) : (
        <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-faint">
          Nothing recorded
        </p>
      )}
    </div>
  );
}
