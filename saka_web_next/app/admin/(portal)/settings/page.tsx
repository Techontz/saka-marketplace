"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import {
  Badge,
  Button,
  Card,
  FormError,
  Input,
  ListState,
  Modal,
  PageHeader,
  Select,
  humanise,
} from "@/components/admin/ui";
import { APP_VERSION, DEVELOPER, SHOW_DEVELOPER_CREDIT } from "@/lib/admin/build-info";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import type { Envelope, Setting, SystemInfo } from "@/lib/admin/api/types";
import { useAuth } from "@/providers/admin/AuthProvider";

/** Group -> the label the tab shows. Anything unlisted falls back to humanise(). */
const GROUP_LABELS: Record<string, string> = {
  general: "General",
  contact: "Contact",
  maps: "Google Maps",
  email: "Email",
  auth: "Google login",
  seo: "SEO",
  listings: "Listings",
  features: "Features",
};

const CACHE_TARGETS = [
  { value: "application", label: "All application caches" },
  { value: "taxonomy", label: "Taxonomy (categories, attributes)" },
  { value: "content", label: "Content (FAQs, pages, settings)" },
  { value: "discovery", label: "Discovery (trending, featured)" },
  { value: "config", label: "Config, routes and permissions" },
];

export default function SettingsPage() {
  const queryClient = useQueryClient();
  const { isSuperAdmin } = useAuth();

  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [cacheTarget, setCacheTarget] = useState("taxonomy");
  const [maintenanceOpen, setMaintenanceOpen] = useState(false);

  const settings = useQuery({
    queryKey: ["settings"],
    queryFn: () => apiGet<Envelope<Setting[]>>("/admin/settings").then((r) => r.data),
  });

  const system = useQuery({
    queryKey: ["system"],
    queryFn: () => apiGet<Envelope<SystemInfo>>("/admin/system").then((r) => r.data),
  });

  const save = useMutation({
    mutationFn: (changed: Record<string, string>) =>
      apiSend("/admin/settings", "PATCH", {
        settings: Object.entries(changed).map(([key, value]) => ({ key, value })),
      }),
    onSuccess: async () => {
      setDrafts({});
      await queryClient.invalidateQueries({ queryKey: ["settings"] });
    },
  });

  const clearCache = useMutation({
    mutationFn: (target: string) => apiSend("/admin/system/cache", "POST", { target }),
  });

  const maintenance = useMutation({
    mutationFn: (enabled: boolean) => apiSend("/admin/system/maintenance", "POST", { enabled }),
    onSuccess: async () => {
      setMaintenanceOpen(false);
      await queryClient.invalidateQueries({ queryKey: ["system"] });
    },
  });

  const rows = settings.data ?? [];
  const groups = [...new Set(rows.map((setting) => setting.group))];
  const dirty = Object.keys(drafts).length > 0;

  return (
    <>
      <PageHeader
        title="Settings"
        description="Platform configuration and operational controls."
        actions={
          dirty && (
            <>
              <Button variant="ghost" onClick={() => setDrafts({})}>
                Discard
              </Button>
              <Button variant="primary" loading={save.isPending} onClick={() => save.mutate(drafts)}>
                Save {Object.keys(drafts).length} change
                {Object.keys(drafts).length === 1 ? "" : "s"}
              </Button>
            </>
          )
        }
      />

      <FormError error={save.error} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Card>
            <ListState
              isLoading={settings.isPending}
              error={settings.error}
              isEmpty={rows.length === 0}
              onRetry={() => void settings.refetch()}
              emptyTitle="No settings defined"
              emptyDescription="Settings are declared by a seeder — the API only accepts keys that already exist."
            >
              <div className="divide-y divide-line">
                {groups.map((group) => (
                  <section key={group}>
                    <div className="bg-muted-soft/40 px-5 py-2">
                      <h2 className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
                        {GROUP_LABELS[group] ?? humanise(group)}
                      </h2>
                    </div>

                    <div className="divide-y divide-line">
                      {rows
                        .filter((setting) => setting.group === group)
                        .map((setting) => {
                          const current =
                            drafts[setting.key] ??
                            (setting.value === null || setting.value === undefined
                              ? ""
                              : String(setting.value));

                          return (
                            <div key={setting.key} className="px-5 py-3.5">
                              <div className="mb-1.5 flex flex-wrap items-center gap-2">
                                <code className="font-mono text-xs text-ink">{setting.key}</code>
                                {/*
                                  is_public is read-only through the API: it
                                  decides whether a value is world-readable, so
                                  exposing it here would let an administrator
                                  publish an SMTP password by accident.
                                */}
                                {setting.is_public ? (
                                  <Badge tone="info">Public</Badge>
                                ) : (
                                  <Badge tone="muted">Private</Badge>
                                )}
                              </div>

                              {setting.description && (
                                <p className="mb-1.5 text-xs text-ink-soft">
                                  {setting.description}
                                </p>
                              )}

                              <Input
                                aria-label={setting.key}
                                value={current}
                                // Private values are credentials often enough
                                // that masking them by default is the right
                                // trade against shoulder-surfing.
                                type={
                                  !setting.is_public && /key|secret|password/.test(setting.key)
                                    ? "password"
                                    : "text"
                                }
                                onChange={(event) =>
                                  setDrafts((current) => ({
                                    ...current,
                                    [setting.key]: event.target.value,
                                  }))
                                }
                              />
                            </div>
                          );
                        })}
                    </div>
                  </section>
                ))}
              </div>
            </ListState>
          </Card>
        </div>

        <div className="space-y-4">
          <Card>
            <div className="border-b border-line px-5 py-3">
              <h2 className="text-sm font-semibold text-ink">System</h2>
            </div>

            {system.isPending ? (
              <div className="space-y-2 p-5">
                {Array.from({ length: 6 }).map((_, index) => (
                  <div key={index} className="h-3.5 animate-pulse rounded bg-muted-soft" />
                ))}
              </div>
            ) : system.error ? (
              <p className="px-5 py-6 text-sm text-ink-soft">
                Could not load the system report.
              </p>
            ) : (
              <dl className="divide-y divide-line text-sm">
                <Row label="Environment">
                  <Badge tone={system.data!.application.environment === "production" ? "ok" : "warn"}>
                    {system.data!.application.environment}
                  </Badge>
                  {system.data!.application.debug && <Badge tone="danger">Debug on</Badge>}
                </Row>
                <Row label="Maintenance">
                  {system.data!.application.maintenance ? (
                    <Badge tone="danger">Site is down</Badge>
                  ) : (
                    <Badge tone="ok">Live</Badge>
                  )}
                </Row>
                <Row label="PHP">{system.data!.versions.php}</Row>
                <Row label="Laravel">{system.data!.versions.laravel}</Row>
                <Row label="Database">{system.data!.versions.database ?? "—"}</Row>
                <Row label="Cache">{system.data!.drivers.cache}</Row>
                <Row label="Queue">{system.data!.drivers.queue}</Row>
                <Row label="Media disk">{system.data!.drivers.media_disk}</Row>
                <Row label="Failed jobs">
                  <span
                    className={
                      (system.data!.queue.failed_jobs ?? 0) > 0 ? "text-danger" : "text-ink"
                    }
                  >
                    {system.data!.queue.failed_jobs ?? "—"}
                  </span>
                </Row>
                <Row label="Storage writable">
                  {system.data!.storage.writable ? (
                    <Badge tone="ok">Yes</Badge>
                  ) : (
                    <Badge tone="danger">No</Badge>
                  )}
                </Row>
              </dl>
            )}
          </Card>

          {/*
            Who to call when the platform itself is wrong.

            An administrator looking at a failed-jobs count or a read-only
            storage disk needs somewhere to escalate to, and "ask whoever built
            it" is not a route anyone can follow at 2am. The version is here for
            the same reason: a bug report without a build number is a bug report
            that cannot be reproduced.
          */}
          <Card>
            <div className="border-b border-line px-5 py-3">
              <h2 className="text-sm font-semibold text-ink">System information</h2>
            </div>

            <dl className="divide-y divide-line text-sm">
              <Row label="Application">SAKA Marketplace</Row>
              <Row label="Admin version">{APP_VERSION}</Row>
              {SHOW_DEVELOPER_CREDIT && (
                <>
                  <Row label="Developed by">
                    <a
                      href={DEVELOPER.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-brand-ink underline underline-offset-2 hover:no-underline"
                    >
                      {DEVELOPER.name}
                    </a>
                  </Row>
                  <Row label="Technical support">
                    <a
                      href={`mailto:${DEVELOPER.supportEmail}`}
                      className="text-brand-ink underline underline-offset-2 hover:no-underline"
                    >
                      {DEVELOPER.supportEmail}
                    </a>
                  </Row>
                </>
              )}
            </dl>
          </Card>

          {isSuperAdmin && (
            <>
              <Card>
                <div className="border-b border-line px-5 py-3">
                  <h2 className="text-sm font-semibold text-ink">Cache</h2>
                </div>
                <div className="space-y-3 px-5 py-4">
                  <p className="text-xs text-ink-soft">
                    Targeted rather than global — dropping everything at once sends every
                    subsequent request to the database.
                  </p>
                  <Select
                    aria-label="Cache to clear"
                    value={cacheTarget}
                    onChange={(event) => setCacheTarget(event.target.value)}
                  >
                    {CACHE_TARGETS.map((target) => (
                      <option key={target.value} value={target.value}>
                        {target.label}
                      </option>
                    ))}
                  </Select>
                  <Button
                    variant="secondary"
                    className="w-full"
                    loading={clearCache.isPending}
                    onClick={() => clearCache.mutate(cacheTarget)}
                  >
                    Clear cache
                  </Button>
                  {clearCache.isSuccess && (
                    <p className="rounded-[var(--radius-control)] bg-ok-soft px-3 py-2 text-xs text-ok">
                      Cleared.
                    </p>
                  )}
                  <FormError error={clearCache.error} />
                </div>
              </Card>

              <Card className="border-danger/30">
                <div className="border-b border-line px-5 py-3">
                  <h2 className="text-sm font-semibold text-ink">Maintenance mode</h2>
                </div>
                <div className="px-5 py-4">
                  <p className="text-xs text-ink-soft">
                    Takes the entire marketplace and API offline for everyone. Use during a
                    migration, not to hide a bug.
                  </p>
                  <Button
                    variant={system.data?.application.maintenance ? "primary" : "danger"}
                    className="mt-3 w-full"
                    onClick={() => setMaintenanceOpen(true)}
                  >
                    {system.data?.application.maintenance ? "Bring site back up" : "Take site down"}
                  </Button>
                </div>
              </Card>
            </>
          )}
        </div>
      </div>

      <Modal
        open={maintenanceOpen}
        onClose={() => setMaintenanceOpen(false)}
        title={
          system.data?.application.maintenance
            ? "Bring the site back up?"
            : "Take the whole platform offline?"
        }
        description={
          system.data?.application.maintenance
            ? "The marketplace and API start serving traffic again immediately."
            : "Every visitor and every API client receives a 503 until you turn this off."
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setMaintenanceOpen(false)}>
              Cancel
            </Button>
            <Button
              variant={system.data?.application.maintenance ? "primary" : "danger"}
              loading={maintenance.isPending}
              onClick={() => maintenance.mutate(!system.data?.application.maintenance)}
            >
              Confirm
            </Button>
          </>
        }
      >
        <FormError error={maintenance.error} />
      </Modal>
    </>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 px-5 py-2.5">
      <dt className="text-xs text-ink-soft">{label}</dt>
      <dd className="flex items-center gap-1.5 text-right text-ink">{children}</dd>
    </div>
  );
}
