"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  Field,
  FormError,
  Input,
  ListState,
  Modal,
  PageHeader,
  Select,
  humanise,
} from "@/components/ui";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/Table";
import { apiGet, apiSend } from "@/lib/api/browser";
import type { Banner, Envelope, HomepageSection } from "@/lib/api/types";

const PLACEMENTS = ["hero", "mid", "footer", "listings_top", "sidebar"];

type BannerDraft = {
  uuid?: string;
  title: string;
  subtitle: string;
  link_url: string;
  link_label: string;
  placement: string;
  is_active: boolean;
  starts_at: string;
  ends_at: string;
};

const EMPTY: BannerDraft = {
  title: "",
  subtitle: "",
  link_url: "",
  link_label: "",
  placement: "hero",
  is_active: true,
  starts_at: "",
  ends_at: "",
};

/**
 * Homepage banners and section configuration.
 *
 * Sections can be retitled, reordered, resized and hidden — but never created
 * or deleted, because each is bound to a frontend component by its key. That
 * boundary is what keeps a CMS from being able to break the marketplace's
 * design.
 */
export default function BannersPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<BannerDraft | null>(null);
  const [deleting, setDeleting] = useState<Banner | null>(null);

  const banners = useQuery({
    queryKey: ["banners"],
    queryFn: () => apiGet<Envelope<Banner[]>>("/admin/banners").then((r) => r.data),
  });

  const sections = useQuery({
    queryKey: ["sections"],
    queryFn: () => apiGet<Envelope<HomepageSection[]>>("/admin/sections").then((r) => r.data),
  });

  const saveBanner = useMutation({
    mutationFn: (draft: BannerDraft) => {
      const body = {
        title: draft.title,
        subtitle: draft.subtitle || null,
        link_url: draft.link_url || null,
        link_label: draft.link_label || null,
        placement: draft.placement,
        is_active: draft.is_active,
        starts_at: draft.starts_at || null,
        ends_at: draft.ends_at || null,
      };

      return draft.uuid
        ? apiSend(`/admin/banners/${draft.uuid}`, "PATCH", body)
        : apiSend("/admin/banners", "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await queryClient.invalidateQueries({ queryKey: ["banners"] });
    },
  });

  const removeBanner = useMutation({
    mutationFn: (uuid: string) => apiSend(`/admin/banners/${uuid}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await queryClient.invalidateQueries({ queryKey: ["banners"] });
    },
  });

  const saveSection = useMutation({
    mutationFn: ({ key, ...body }: { key: string } & Partial<HomepageSection>) =>
      apiSend(`/admin/sections/${key}`, "PATCH", body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["sections"] }),
  });

  const bannerRows = banners.data ?? [];
  const sectionRows = sections.data ?? [];

  return (
    <>
      <PageHeader
        title="Banners & sections"
        description="Promotional slots, and the order and headings of the homepage."
        actions={
          <Button variant="primary" onClick={() => setEditing({ ...EMPTY })}>
            <Plus aria-hidden className="h-4 w-4" />
            New banner
          </Button>
        }
      />

      <Card className="mb-6">
        <div className="border-b border-line px-5 py-3">
          <h2 className="text-sm font-semibold text-ink">Banners</h2>
        </div>

        <ListState
          isLoading={banners.isPending}
          error={banners.error}
          isEmpty={bannerRows.length === 0}
          onRetry={() => void banners.refetch()}
          emptyTitle="No banners"
          emptyDescription="Create one to promote a campaign on the marketing surface."
        >
          <Table>
            <THead>
              <TH>Banner</TH>
              <TH>Placement</TH>
              <TH>State</TH>
              <TH>Schedule</TH>
              <TH />
            </THead>
            <TBody>
              {bannerRows.map((banner) => (
                <TR key={banner.uuid}>
                  <TD>
                    <p className="font-medium text-ink">{banner.title}</p>
                    {banner.subtitle && (
                      <p className="text-xs text-ink-faint">{banner.subtitle}</p>
                    )}
                  </TD>
                  <TD>
                    <Badge tone="muted">{humanise(banner.placement)}</Badge>
                  </TD>
                  <TD>
                    {/*
                      is_live and is_active are different questions. A banner can
                      be active but outside its window — without both, an
                      operator cannot tell why it is not showing.
                    */}
                    {banner.is_live ? (
                      <Badge tone="ok">Live</Badge>
                    ) : banner.is_active ? (
                      <Badge tone="warn">Scheduled / expired</Badge>
                    ) : (
                      <Badge tone="muted">Inactive</Badge>
                    )}
                  </TD>
                  <TD className="text-xs text-ink-soft">
                    {banner.starts_at
                      ? new Date(banner.starts_at).toLocaleDateString()
                      : "Always"}
                    {" → "}
                    {banner.ends_at ? new Date(banner.ends_at).toLocaleDateString() : "No end"}
                  </TD>
                  <TD align="right">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setEditing({
                          uuid: banner.uuid,
                          title: banner.title,
                          subtitle: banner.subtitle ?? "",
                          link_url: banner.link_url ?? "",
                          link_label: banner.link_label ?? "",
                          placement: banner.placement,
                          is_active: banner.is_active,
                          starts_at: banner.starts_at?.slice(0, 16) ?? "",
                          ends_at: banner.ends_at?.slice(0, 16) ?? "",
                        })
                      }
                    >
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setDeleting(banner)}>
                      Delete
                    </Button>
                  </TD>
                </TR>
              ))}
            </TBody>
          </Table>
        </ListState>
      </Card>

      <Card>
        <div className="border-b border-line px-5 py-3">
          <h2 className="text-sm font-semibold text-ink">Homepage sections</h2>
          <p className="text-xs text-ink-soft">
            Retitle, reorder, resize or hide. Sections cannot be created or deleted — each is bound
            to a component on the marketplace.
          </p>
        </div>

        <ListState
          isLoading={sections.isPending}
          error={sections.error}
          isEmpty={sectionRows.length === 0}
          onRetry={() => void sections.refetch()}
          emptyTitle="No sections configured"
        >
          <ul className="divide-y divide-line">
            {sectionRows.map((section) => (
              <SectionRow
                key={section.key}
                section={section}
                onSave={(patch) => saveSection.mutate({ key: section.key, ...patch })}
                saving={saveSection.isPending}
              />
            ))}
          </ul>
        </ListState>
        <FormError error={saveSection.error} />
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing?.uuid ? "Edit banner" : "New banner"}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>
              Cancel
            </Button>
            <Button
              variant="primary"
              loading={saveBanner.isPending}
              disabled={!editing?.title.trim()}
              onClick={() => editing && saveBanner.mutate(editing)}
            >
              Save
            </Button>
          </>
        }
      >
        {editing && (
          <div className="space-y-4">
            <Field label="Title" required>
              <Input
                value={editing.title}
                onChange={(event) => setEditing({ ...editing, title: event.target.value })}
                autoFocus
              />
            </Field>

            <Field label="Subtitle">
              <Input
                value={editing.subtitle}
                onChange={(event) => setEditing({ ...editing, subtitle: event.target.value })}
              />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label="Link URL"
                hint="Must start with http:// or https://."
              >
                <Input
                  type="url"
                  value={editing.link_url}
                  onChange={(event) => setEditing({ ...editing, link_url: event.target.value })}
                  placeholder="https://"
                />
              </Field>

              <Field label="Link label">
                <Input
                  value={editing.link_label}
                  onChange={(event) => setEditing({ ...editing, link_label: event.target.value })}
                />
              </Field>
            </div>

            <Field label="Placement">
              <Select
                value={editing.placement}
                onChange={(event) => setEditing({ ...editing, placement: event.target.value })}
              >
                {PLACEMENTS.map((placement) => (
                  <option key={placement} value={placement}>
                    {humanise(placement)}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Starts" hint="Leave empty to start immediately.">
                <Input
                  type="datetime-local"
                  value={editing.starts_at}
                  onChange={(event) => setEditing({ ...editing, starts_at: event.target.value })}
                />
              </Field>
              <Field label="Ends" hint="Leave empty to run indefinitely.">
                <Input
                  type="datetime-local"
                  value={editing.ends_at}
                  onChange={(event) => setEditing({ ...editing, ends_at: event.target.value })}
                />
              </Field>
            </div>

            <Checkbox
              label="Active"
              checked={editing.is_active}
              onChange={(event) => setEditing({ ...editing, is_active: event.target.checked })}
            />

            <FormError error={saveBanner.error} />
          </div>
        )}
      </Modal>

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete "${deleting?.title}"?`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={removeBanner.isPending}
              onClick={() => deleting && removeBanner.mutate(deleting.uuid)}
            >
              Delete
            </Button>
          </>
        }
      >
        <FormError error={removeBanner.error} />
      </Modal>
    </>
  );
}

function SectionRow({
  section,
  onSave,
  saving,
}: {
  section: HomepageSection;
  onSave: (patch: Partial<HomepageSection>) => void;
  saving: boolean;
}) {
  const [title, setTitle] = useState(section.title);
  const [subtitle, setSubtitle] = useState(section.subtitle ?? "");
  const [limit, setLimit] = useState(section.item_limit?.toString() ?? "");

  const dirty =
    title !== section.title ||
    subtitle !== (section.subtitle ?? "") ||
    limit !== (section.item_limit?.toString() ?? "");

  return (
    <li className="px-5 py-4">
      <div className="mb-3 flex items-center gap-2">
        <code className="font-mono text-xs text-ink-faint">{section.key}</code>
        {section.is_active ? <Badge tone="ok">Visible</Badge> : <Badge tone="muted">Hidden</Badge>}
      </div>

      <div className="grid gap-3 sm:grid-cols-[2fr_2fr_100px_auto]">
        <Input
          aria-label={`${section.key} title`}
          value={title}
          onChange={(event) => setTitle(event.target.value)}
        />
        <Input
          aria-label={`${section.key} subtitle`}
          value={subtitle}
          placeholder="Subtitle"
          onChange={(event) => setSubtitle(event.target.value)}
        />
        <Input
          aria-label={`${section.key} item limit`}
          type="number"
          value={limit}
          placeholder="Items"
          onChange={(event) => setLimit(event.target.value)}
        />

        <div className="flex gap-2">
          <Button
            size="sm"
            variant={dirty ? "primary" : "secondary"}
            disabled={!dirty}
            loading={saving}
            onClick={() =>
              onSave({
                title,
                subtitle: subtitle || null,
                item_limit: limit ? Number(limit) : null,
              })
            }
          >
            Save
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => onSave({ is_active: !section.is_active })}
          >
            {section.is_active ? "Hide" : "Show"}
          </Button>
        </div>
      </div>
    </li>
  );
}
