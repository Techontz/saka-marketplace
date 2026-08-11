"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { Plus } from "lucide-react";
import { Suspense, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Field,
  FormError,
  Input,
  ListState,
  Modal,
  PageHeader,
  Pagination,
  Select,

  type BadgeTone,
} from "@/components/admin/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/admin/ui/Table";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import { useUrlFilters } from "@/lib/admin/hooks";
import { formatCount } from "@/lib/admin/format";
import type {
  AdCampaign,
  Advertiser,
  AdvertisingOptions,
  Category,
  Envelope,
  Paginated,
} from "@/lib/admin/api/types";

/**
 * Advertising campaigns.
 *
 * The screen that made the advertising backend usable by somebody without a
 * shell. Everything here is the existing admin kit — the same table, badges,
 * modal and four-state wrapper as Listings and Places — because an operator
 * should not have to learn a second set of conventions to sell a banner.
 */

/**
 * Campaign status -> badge tone.
 *
 * Deliberately NOT the shared `statusTone()`. That helper maps listing
 * moderation states, where "paused" is a warning worth an operator's attention.
 * A paused CAMPAIGN is a normal, deliberate commercial state — an amber badge
 * on every paused campaign would train people to ignore amber on this screen,
 * which is the colour that matters on the queues next door.
 */
function campaignTone(status: string): BadgeTone {
  switch (status) {
    case "active":
      return "ok";
    case "scheduled":
      return "info";
    case "paused":
      return "warn";
    case "expired":
    case "archived":
      return "muted";
    default:
      return "muted";
  }
}

type Draft = {
  uuid?: string;
  advertiser_uuid: string;
  name: string;
  placement: string;
  starts_at: string;
  ends_at: string;
  priority: string;
  impression_cap: string;
  category_slugs: string[];
};

const EMPTY: Draft = {
  advertiser_uuid: "",
  name: "",
  placement: "",
  starts_at: "",
  ends_at: "",
  priority: "0",
  impression_cap: "",
  category_slugs: [],
};

export default function AdvertisingPage() {
  return (
    <Suspense fallback={null}>
      <CampaignsView />
    </Suspense>
  );
}

function CampaignsView() {
  const queryClient = useQueryClient();
  const { filters, setFilters } = useUrlFilters({ status: "", placement: "", page: "1" });

  const [editing, setEditing] = useState<Draft | null>(null);
  const [advertiserModal, setAdvertiserModal] = useState(false);

  const options = useQuery({
    queryKey: ["advertising-options"],
    queryFn: () => apiGet<Envelope<AdvertisingOptions>>("/admin/advertising/options").then((r) => r.data),
    // Enum-derived reference data; it changes on deploy, not during a session.
    staleTime: 30 * 60 * 1000,
  });

  const advertisers = useQuery({
    queryKey: ["advertisers"],
    queryFn: () => apiGet<Paginated<Advertiser>>("/admin/advertisers", { per_page: 100 }).then((r) => r.data),
  });

  const categories = useQuery({
    queryKey: ["categories"],
    queryFn: () => apiGet<Envelope<Category[]>>("/categories").then((r) => r.data),
  });

  const campaigns = useQuery({
    queryKey: ["ad-campaigns", filters],
    queryFn: () =>
      apiGet<Paginated<AdCampaign>>("/admin/ad-campaigns", {
        status: filters.status || undefined,
        placement: filters.placement || undefined,
        page: filters.page,
        per_page: 25,
      }),
  });

  const save = useMutation({
    mutationFn: (draft: Draft) => {
      const body: Record<string, unknown> = {
        name: draft.name,
        placement: draft.placement,
        // Empty string is "not set", which is a null date — not the epoch.
        starts_at: draft.starts_at || null,
        ends_at: draft.ends_at || null,
        priority: Number(draft.priority || 0),
        impression_cap: draft.impression_cap ? Number(draft.impression_cap) : null,
        category_slugs: draft.category_slugs,
      };

      // The advertiser is fixed at creation: re-pointing a campaign's billing
      // after it has delivered would detach the invoice from the delivery, and
      // the API rejects it.
      if (!draft.uuid) body.advertiser_uuid = draft.advertiser_uuid;

      return draft.uuid
        ? apiSend(`/admin/ad-campaigns/${draft.uuid}`, "PATCH", body)
        : apiSend("/admin/ad-campaigns", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await queryClient.invalidateQueries({ queryKey: ["ad-campaigns"] });
    },
  });

  const transition = useMutation({
    mutationFn: ({ uuid, status }: { uuid: string; status: string }) =>
      apiSend(`/admin/ad-campaigns/${uuid}/transition`, "POST", { status }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["ad-campaigns"] });
    },
  });

  const createAdvertiser = useMutation({
    mutationFn: (body: { name: string; contact_name: string; contact_email: string }) =>
      apiSend("/admin/advertisers", "POST", {
        name: body.name,
        contact_name: body.contact_name || null,
        contact_email: body.contact_email || null,
      }),
    onSuccess: async () => {
      setAdvertiserModal(false);
      await queryClient.invalidateQueries({ queryKey: ["advertisers"] });
    },
  });

  const rows = campaigns.data?.data ?? [];
  const meta = campaigns.data?.meta;
  const placements = options.data?.placements ?? [];
  const statuses = options.data?.statuses ?? [];
  const advertiserList = advertisers.data ?? [];

  return (
    <>
      <PageHeader
        title="Campaigns"
        description="SAKA's own advertising inventory. Google AdSense is configured separately, in the marketplace environment."
        actions={
          <>
            <Button variant="secondary" onClick={() => setAdvertiserModal(true)}>
              New advertiser
            </Button>
            <Button
              variant="primary"
              /*
               * A campaign needs an advertiser to bill. Disabling until one
               * exists is better than a form whose first dropdown is empty and
               * whose submit fails with a validation error.
               */
              disabled={advertiserList.length === 0 || placements.length === 0}
              onClick={() => setEditing({ ...EMPTY })}
            >
              <Plus aria-hidden className="h-4 w-4" />
              New campaign
            </Button>
          </>
        }
      />

      <Card>
        <div className="flex flex-wrap gap-3 border-b border-line px-4 py-3">
          <Select
            aria-label="Filter by status"
            className="w-auto"
            value={filters.status}
            onChange={(event) => setFilters({ status: event.target.value || null })}
          >
            <option value="">All statuses</option>
            {statuses.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </Select>

          <Select
            aria-label="Filter by placement"
            className="w-auto"
            value={filters.placement}
            onChange={(event) => setFilters({ placement: event.target.value || null })}
          >
            <option value="">All placements</option>
            {placements.map((placement) => (
              <option key={placement.value} value={placement.value}>
                {placement.label}
              </option>
            ))}
          </Select>
        </div>

        <ListState
          isLoading={campaigns.isPending}
          error={campaigns.error}
          isEmpty={rows.length === 0}
          onRetry={() => void campaigns.refetch()}
          skeletonColumns={8}
          emptyTitle="No campaigns"
          emptyDescription={
            advertiserList.length === 0
              ? "Create an advertiser first, then book a campaign against a placement."
              : "Nothing matches these filters."
          }
        >
          <Table>
            <THead>
              <TH>Campaign</TH>
              <TH>Advertiser</TH>
              <TH>Placement</TH>
              <TH>Status</TH>
              <TH>Runs</TH>
              <TH align="right">Impressions</TH>
              <TH align="right">Clicks</TH>
              <TH align="right">CTR</TH>
              <TH />
            </THead>
            <TBody>
              {rows.map((campaign) => (
                <TR key={campaign.uuid}>
                  <TD>
                    <Link
                      href={`/advertising/${campaign.uuid}`}
                      className="font-medium text-ink hover:text-brand"
                    >
                      {campaign.name}
                    </Link>
                    <p className="text-xs text-ink-faint">
                      {formatCount(campaign.creatives_count)} creative
                      {campaign.creatives_count === 1 ? "" : "s"}
                      {campaign.priority > 0 && ` · priority ${campaign.priority}`}
                    </p>
                  </TD>

                  <TD>{campaign.advertiser?.name ?? "—"}</TD>
                  <TD>{campaign.placement_label}</TD>

                  <TD>
                    <Badge tone={campaignTone(campaign.status)}>{campaign.status_label}</Badge>

                    {/*
                      * The stored status is refreshed by cron every five
                      * minutes. When the dates disagree with it, say so rather
                      * than letting an operator believe a campaign that started
                      * ten seconds ago is still waiting.
                      */}
                    {campaign.effective_status !== campaign.status && (
                      <p className="mt-1 text-[11px] text-ink-faint">
                        Schedule says {campaign.effective_status}
                      </p>
                    )}
                  </TD>

                  <TD>
                    <span className="text-xs text-ink-soft">
                      {formatDate(campaign.starts_at)} → {formatDate(campaign.ends_at)}
                    </span>
                  </TD>

                  <TD align="right">{formatCount(campaign.performance.impressions)}</TD>
                  <TD align="right">{formatCount(campaign.performance.clicks)}</TD>
                  <TD align="right">
                    {/*
                      * A dash, not 0.00%. Null means nothing has been shown
                      * yet, which is a different fact from "shown and never
                      * clicked" — and only one of them is a problem.
                      */}
                    {campaign.performance.ctr === null ? "—" : `${campaign.performance.ctr.toFixed(2)}%`}
                  </TD>

                  <TD align="right">
                    <div className="flex justify-end gap-2">
                      {campaign.status === "active" ? (
                        <Button
                          size="sm"
                          variant="secondary"
                          loading={transition.isPending && transition.variables?.uuid === campaign.uuid}
                          onClick={() => transition.mutate({ uuid: campaign.uuid, status: "paused" })}
                        >
                          Pause
                        </Button>
                      ) : campaign.status === "draft" || campaign.status === "paused" ? (
                        <Button
                          size="sm"
                          variant="primary"
                          loading={transition.isPending && transition.variables?.uuid === campaign.uuid}
                          onClick={() => transition.mutate({ uuid: campaign.uuid, status: "active" })}
                        >
                          {campaign.status === "paused" ? "Resume" : "Activate"}
                        </Button>
                      ) : null}

                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                          setEditing({
                            uuid: campaign.uuid,
                            advertiser_uuid: campaign.advertiser?.uuid ?? "",
                            name: campaign.name,
                            placement: campaign.placement,
                            starts_at: toDateInput(campaign.starts_at),
                            ends_at: toDateInput(campaign.ends_at),
                            priority: String(campaign.priority),
                            impression_cap: campaign.impression_cap ? String(campaign.impression_cap) : "",
                            category_slugs: (campaign.targeting.categories ?? []).map((c) => c.slug),
                          })
                        }
                      >
                        Edit
                      </Button>
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
            disabled={campaigns.isFetching}
            onPage={(page) => setFilters({ page }, { resetPage: false })}
          />
        )}
      </Card>

      {/*
        * Activation failures surface here rather than beside the row button.
        * The most common one — "add an active creative before putting this
        * campaign live" — is actionable, and losing it would leave the operator
        * pressing Activate repeatedly with nothing happening.
        */}
      {transition.error != null && (
        <div className="mt-4">
          <FormError error={transition.error} />
        </div>
      )}

      <CampaignModal
        draft={editing}
        onChange={setEditing}
        onClose={() => setEditing(null)}
        onSave={(draft) => save.mutate(draft)}
        saving={save.isPending}
        error={save.error}
        advertisers={advertiserList}
        placements={placements}
        categories={categories.data ?? []}
      />

      <AdvertiserModal
        open={advertiserModal}
        onClose={() => setAdvertiserModal(false)}
        onSave={(body) => createAdvertiser.mutate(body)}
        saving={createAdvertiser.isPending}
        error={createAdvertiser.error}
      />
    </>
  );
}

// ------------------------------------------------------------------- modals

function CampaignModal({
  draft,
  onChange,
  onClose,
  onSave,
  saving,
  error,
  advertisers,
  placements,
  categories,
}: {
  draft: Draft | null;
  onChange: (draft: Draft) => void;
  onClose: () => void;
  onSave: (draft: Draft) => void;
  saving: boolean;
  error: unknown;
  advertisers: Advertiser[];
  placements: AdvertisingOptions["placements"];
  categories: Category[];
}) {
  if (!draft) return null;

  const isNew = !draft.uuid;
  const placement = placements.find((option) => option.value === draft.placement);

  const canSave =
    draft.name.trim().length >= 2 &&
    draft.placement !== "" &&
    (!isNew || draft.advertiser_uuid !== "");

  return (
    <Modal
      open
      onClose={onClose}
      title={isNew ? "New campaign" : "Edit campaign"}
      description={
        isNew
          ? "Created as a draft. Add a creative, then activate it to start serving."
          : undefined
      }
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} disabled={!canSave} onClick={() => onSave(draft)}>
            {isNew ? "Create campaign" : "Save changes"}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError error={error} />

        {isNew && (
          <Field label="Advertiser" required>
            <Select
              value={draft.advertiser_uuid}
              onChange={(event) => onChange({ ...draft, advertiser_uuid: event.target.value })}
            >
              <option value="">Select an advertiser…</option>
              {advertisers.map((advertiser) => (
                <option key={advertiser.uuid} value={advertiser.uuid}>
                  {advertiser.name}
                </option>
              ))}
            </Select>
          </Field>
        )}

        <Field label="Campaign name" required>
          <Input
            value={draft.name}
            maxLength={191}
            onChange={(event) => onChange({ ...draft, name: event.target.value })}
            placeholder="Home loans — Q3"
          />
        </Field>

        <Field label="Placement" required hint={placement?.description}>
          <Select
            value={draft.placement}
            onChange={(event) => onChange({ ...draft, placement: event.target.value })}
          >
            <option value="">Select a placement…</option>
            {placements.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>

        {placement && (
          /*
           * Artwork guidance derived from the placement itself, so it cannot
           * drift from the box the marketplace reserves.
           */
          <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
            Artwork ratio — desktop {placement.aspect_ratio.desktop.toFixed(2)}:1, mobile{" "}
            {placement.aspect_ratio.mobile.toFixed(2)}:1. Shows up to{" "}
            {placement.max_concurrent} campaign{placement.max_concurrent === 1 ? "" : "s"} at a time.
          </p>
        )}

        <div className="grid grid-cols-2 gap-3">
          <Field label="Starts" hint="Leave blank to start on activation.">
            <Input
              type="date"
              value={draft.starts_at}
              onChange={(event) => onChange({ ...draft, starts_at: event.target.value })}
            />
          </Field>
          <Field label="Ends" hint="Blank runs until stopped.">
            <Input
              type="date"
              value={draft.ends_at}
              onChange={(event) => onChange({ ...draft, ends_at: event.target.value })}
            />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Priority" hint="Higher wins when several campaigns compete.">
            <Input
              type="number"
              min={0}
              max={65535}
              value={draft.priority}
              onChange={(event) => onChange({ ...draft, priority: event.target.value })}
            />
          </Field>
          <Field label="Impression cap" hint="Blank is uncapped.">
            <Input
              type="number"
              min={1}
              value={draft.impression_cap}
              placeholder="e.g. 500000"
              onChange={(event) => onChange({ ...draft, impression_cap: event.target.value })}
            />
          </Field>
        </div>

        <Field
          label="Target categories"
          hint="None selected means the campaign runs everywhere. Selecting a vertical also covers its subcategories."
        >
          <div className="max-h-40 space-y-1 overflow-y-auto rounded-[var(--radius-control)] border border-line p-2">
            {categories.map((category) => (
              <label key={category.slug} className="flex cursor-pointer items-center gap-2 text-sm text-ink">
                <input
                  type="checkbox"
                  className="h-4 w-4 accent-[var(--color-brand)]"
                  checked={draft.category_slugs.includes(category.slug)}
                  onChange={(event) =>
                    onChange({
                      ...draft,
                      category_slugs: event.target.checked
                        ? [...draft.category_slugs, category.slug]
                        : draft.category_slugs.filter((slug) => slug !== category.slug),
                    })
                  }
                />
                {category.name}
              </label>
            ))}
          </div>
        </Field>
      </div>
    </Modal>
  );
}

function AdvertiserModal({
  open,
  onClose,
  onSave,
  saving,
  error,
}: {
  open: boolean;
  onClose: () => void;
  onSave: (body: { name: string; contact_name: string; contact_email: string }) => void;
  saving: boolean;
  error: unknown;
}) {
  const [name, setName] = useState("");
  const [contactName, setContactName] = useState("");
  const [contactEmail, setContactEmail] = useState("");

  if (!open) return null;

  return (
    <Modal
      open
      onClose={onClose}
      title="New advertiser"
      description="Who is billed. Separate from a vendor account — most advertisers do not list on SAKA."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="primary"
            loading={saving}
            disabled={name.trim().length < 2}
            onClick={() => onSave({ name, contact_name: contactName, contact_email: contactEmail })}
          >
            Create
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError error={error} />

        <Field label="Name" required>
          <Input value={name} maxLength={191} onChange={(event) => setName(event.target.value)} />
        </Field>

        <Field label="Billing contact">
          <Input value={contactName} onChange={(event) => setContactName(event.target.value)} />
        </Field>

        <Field label="Billing email">
          <Input
            type="email"
            value={contactEmail}
            onChange={(event) => setContactEmail(event.target.value)}
          />
        </Field>
      </div>
    </Modal>
  );
}

// ------------------------------------------------------------------ helpers

/** "Open-ended" is a real state and reads better than an empty cell. */
function formatDate(value: string | null): string {
  if (!value) return "—";

  return new Date(value).toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

/**
 * ISO timestamp -> the `yyyy-mm-dd` an `<input type="date">` requires.
 *
 * Anything else — including a full ISO string — leaves the control blank with
 * no error, so an operator editing a dated campaign would see empty date fields
 * and unknowingly clear the schedule on save.
 */
function toDateInput(value: string | null): string {
  if (!value) return "";

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "" : date.toISOString().slice(0, 10);
}
