"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ImageOff, Plus, Upload } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useRef, useState } from "react";

import {
  Badge,
  Button,
  Card,
  Checkbox,
  ErrorState,
  Field,
  FormError,
  Input,
  Modal,
  PageHeader,
  TableSkeleton,
  Textarea,
} from "@/components/admin/ui";
import { apiGet, apiSend } from "@/lib/admin/api/browser";
import { formatCount } from "@/lib/admin/format";
import type { AdCampaign, AdCreative, Envelope } from "@/lib/admin/api/types";

/**
 * One campaign and its creatives.
 *
 * Creatives live HERE rather than on a flat list of their own. A creative
 * without its campaign has no placement, no dates and no targeting, so a
 * standalone list would be rows of headlines nobody could act on — and the
 * rotation between them only means anything within a campaign.
 */

type CreativeDraft = {
  uuid?: string;
  headline: string;
  body: string;
  cta_label: string;
  click_url: string;
  alt_text: string;
  is_active: boolean;
};

const EMPTY_CREATIVE: CreativeDraft = {
  headline: "",
  body: "",
  cta_label: "",
  click_url: "",
  alt_text: "",
  is_active: true,
};

export default function CampaignDetailPage() {
  const params = useParams<{ uuid: string }>();
  const uuid = params.uuid;

  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<CreativeDraft | null>(null);
  const [deleting, setDeleting] = useState<AdCreative | null>(null);

  const campaign = useQuery({
    queryKey: ["ad-campaign", uuid],
    queryFn: () => apiGet<Envelope<AdCampaign>>(`/admin/ad-campaigns/${uuid}`).then((r) => r.data),
  });

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ["ad-campaign", uuid] });
    // The list carries creatives_count and the delivery totals.
    await queryClient.invalidateQueries({ queryKey: ["ad-campaigns"] });
  };

  const save = useMutation({
    mutationFn: (draft: CreativeDraft) => {
      const body = {
        headline: draft.headline,
        body: draft.body || null,
        cta_label: draft.cta_label || null,
        click_url: draft.click_url,
        alt_text: draft.alt_text || null,
        is_active: draft.is_active,
      };

      return draft.uuid
        ? apiSend(`/admin/ad-creatives/${draft.uuid}`, "PATCH", body)
        : apiSend(`/admin/ad-campaigns/${uuid}/creatives`, "POST", body);
    },
    onSuccess: async () => {
      setEditing(null);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (creativeUuid: string) => apiSend(`/admin/ad-creatives/${creativeUuid}`, "DELETE"),
    onSuccess: async () => {
      setDeleting(null);
      await invalidate();
    },
  });

  if (campaign.isPending) return <TableSkeleton rows={4} />;
  if (campaign.error) return <ErrorState error={campaign.error} onRetry={() => void campaign.refetch()} />;

  const data = campaign.data;
  if (!data) return <ErrorState error={new Error("Campaign not found.")} />;

  const creatives = data.creatives ?? [];

  return (
    <>
      <Link
        href="/admin/advertising"
        className="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-soft hover:text-ink"
      >
        <ArrowLeft aria-hidden className="h-4 w-4" />
        Campaigns
      </Link>

      <PageHeader
        title={data.name}
        description={`${data.advertiser?.name ?? "Unknown advertiser"} · ${data.placement_label}`}
        actions={
          <Button variant="primary" onClick={() => setEditing({ ...EMPTY_CREATIVE })}>
            <Plus aria-hidden className="h-4 w-4" />
            New creative
          </Button>
        }
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-4">
        <Stat label="Status" value={data.status_label} />
        <Stat label="Impressions" value={formatCount(data.performance.impressions)} />
        <Stat label="Clicks" value={formatCount(data.performance.clicks)} />
        <Stat
          label="CTR"
          /* A dash when nothing has been shown. See the note in the campaign
             resource — "no data" is not "0%". */
          value={data.performance.ctr === null ? "—" : `${data.performance.ctr.toFixed(2)}%`}
        />
      </div>

      {creatives.length === 0 ? (
        <Card>
          <div className="px-6 py-16 text-center">
            <ImageOff aria-hidden className="mx-auto mb-3 h-8 w-8 text-ink-faint" />
            <p className="text-sm font-medium text-ink">No creatives yet</p>
            <p className="mt-1 text-sm text-ink-soft">
              A campaign cannot go live until it has something to render.
            </p>
            <Button className="mt-4" variant="primary" onClick={() => setEditing({ ...EMPTY_CREATIVE })}>
              Add the first creative
            </Button>
          </div>
        </Card>
      ) : (
        <div className="space-y-4">
          {creatives.map((creative) => (
            <CreativeCard
              key={creative.uuid}
              creative={creative}
              onEdit={() =>
                setEditing({
                  uuid: creative.uuid,
                  headline: creative.headline,
                  body: creative.body ?? "",
                  cta_label: creative.cta_label ?? "",
                  click_url: creative.click_url,
                  alt_text: creative.alt_text ?? "",
                  is_active: creative.is_active,
                })
              }
              onDelete={() => setDeleting(creative)}
              onUploaded={invalidate}
            />
          ))}
        </div>
      )}

      <CreativeModal
        draft={editing}
        onChange={setEditing}
        onClose={() => setEditing(null)}
        onSave={(draft) => save.mutate(draft)}
        saving={save.isPending}
        error={save.error}
      />

      <Modal
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete this creative?"
        description={
          deleting
            ? `"${deleting.headline}" has been shown ${formatCount(deleting.performance.impressions)} times. Its delivery history is kept for reporting.`
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.uuid)}
            >
              Delete
            </Button>
          </>
        }
      >
        <FormError error={remove.error} />
      </Modal>
    </>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <div className="px-4 py-3">
        <p className="text-[11px] font-semibold tracking-wide text-ink-soft uppercase">{label}</p>
        <p className="mt-1 text-lg font-semibold text-ink">{value}</p>
      </div>
    </Card>
  );
}

function CreativeCard({
  creative,
  onEdit,
  onDelete,
  onUploaded,
}: {
  creative: AdCreative;
  onEdit: () => void;
  onDelete: () => void;
  onUploaded: () => Promise<void>;
}) {
  return (
    <Card>
      <div className="flex flex-col gap-4 p-4 sm:flex-row">
        <div className="w-full shrink-0 sm:w-56">
          <ArtworkSlot
            creativeUuid={creative.uuid}
            kind="desktop"
            url={creative.image?.url ?? null}
            onUploaded={onUploaded}
          />
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-sm font-semibold text-ink">{creative.headline}</h3>
            <Badge tone={creative.is_active ? "ok" : "muted"}>
              {creative.is_active ? "Active" : "Inactive"}
            </Badge>
          </div>

          {creative.body && <p className="mt-1 text-sm text-ink-soft">{creative.body}</p>}

          <p className="mt-2 truncate text-xs text-ink-faint">
            {creative.cta_label ? `${creative.cta_label} → ` : "→ "}
            {creative.click_url}
          </p>

          <p className="mt-3 text-xs text-ink-soft">
            {formatCount(creative.performance.impressions)} impressions ·{" "}
            {formatCount(creative.performance.clicks)} clicks ·{" "}
            {creative.performance.ctr === null ? "—" : `${creative.performance.ctr.toFixed(2)}%`} CTR
          </p>

          <div className="mt-3 flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" onClick={onEdit}>
              Edit
            </Button>
            <Button size="sm" variant="ghost" onClick={onDelete}>
              Delete
            </Button>
          </div>
        </div>

        <div className="w-full shrink-0 sm:w-40">
          <ArtworkSlot
            creativeUuid={creative.uuid}
            kind="mobile"
            url={creative.mobile_image?.url ?? null}
            onUploaded={onUploaded}
          />
        </div>
      </div>
    </Card>
  );
}

/**
 * Upload or replace one piece of artwork.
 *
 * Multipart, straight to the API, because the media pipeline does the work that
 * matters — MIME sniffing from magic bytes rather than the filename, EXIF
 * stripping, and WebP variant generation. Sending a base64 blob through the
 * JSON endpoint would skip all three.
 */
function ArtworkSlot({
  creativeUuid,
  kind,
  url,
  onUploaded,
}: {
  creativeUuid: string;
  kind: "desktop" | "mobile";
  url: string | null;
  onUploaded: () => Promise<void>;
}) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const upload = async (file: File) => {
    setUploading(true);
    setError(null);

    try {
      const form = new FormData();
      form.append("file", file);

      /*
       * `fetch` directly rather than `apiSend`, which JSON-encodes its body.
       * The Content-Type header is deliberately NOT set: the browser has to add
       * it itself so it can append the multipart boundary, and setting it by
       * hand produces a request the server cannot parse.
       */
      const response = await fetch(`/api/saka/admin/ad-creatives/${creativeUuid}/image/${kind}`, {
        method: "POST",
        body: form,
      });

      if (!response.ok) {
        const payload = await response.json().catch(() => null);
        throw new Error(payload?.error?.message ?? "The upload failed.");
      }

      await onUploaded();
    } catch (uploadError) {
      setError(uploadError);
    } finally {
      setUploading(false);
      // Cleared so re-picking the SAME file fires `change` again — otherwise a
      // retry after a failure silently does nothing.
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  return (
    <div>
      <p className="mb-1.5 text-[11px] font-semibold tracking-wide text-ink-soft uppercase">
        {kind}
      </p>

      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={uploading}
        aria-label={`Upload ${kind} artwork`}
        className="flex aspect-[4/1] w-full items-center justify-center overflow-hidden rounded-[var(--radius-control)] border border-dashed border-line bg-muted-soft/40 transition hover:border-brand disabled:opacity-50"
      >
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={url} alt="" className="h-full w-full object-cover" />
        ) : (
          <span className="flex items-center gap-1.5 text-xs text-ink-faint">
            <Upload aria-hidden className="h-3.5 w-3.5" />
            {uploading ? "Uploading…" : "Upload"}
          </span>
        )}
      </button>

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0];
          if (file) void upload(file);
        }}
      />

      {error != null && <div className="mt-2"><FormError error={error} /></div>}
    </div>
  );
}

function CreativeModal({
  draft,
  onChange,
  onClose,
  onSave,
  saving,
  error,
}: {
  draft: CreativeDraft | null;
  onChange: (draft: CreativeDraft) => void;
  onClose: () => void;
  onSave: (draft: CreativeDraft) => void;
  saving: boolean;
  error: unknown;
}) {
  if (!draft) return null;

  const urlLooksValid = /^https?:\/\/.+/i.test(draft.click_url.trim());
  const canSave = draft.headline.trim().length >= 2 && urlLooksValid;

  return (
    <Modal
      open
      onClose={onClose}
      title={draft.uuid ? "Edit creative" : "New creative"}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} disabled={!canSave} onClick={() => onSave(draft)}>
            Save
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <FormError error={error} />

        <Field label="Headline" required hint="Shown as the main line. Plain text — never rendered as HTML.">
          <Input
            value={draft.headline}
            maxLength={120}
            onChange={(event) => onChange({ ...draft, headline: event.target.value })}
          />
        </Field>

        <Field label="Body">
          <Textarea
            rows={2}
            maxLength={240}
            value={draft.body}
            onChange={(event) => onChange({ ...draft, body: event.target.value })}
          />
        </Field>

        <Field label="Call to action">
          <Input
            value={draft.cta_label}
            maxLength={40}
            placeholder="Explore"
            onChange={(event) => onChange({ ...draft, cta_label: event.target.value })}
          />
        </Field>

        <Field
          label="Destination URL"
          required
          hint="Must start with http:// or https://. This becomes the link visitors follow."
          error={draft.click_url !== "" && !urlLooksValid ? "Enter a full http(s) URL." : undefined}
        >
          <Input
            value={draft.click_url}
            maxLength={2048}
            placeholder="https://example.co.tz/offer"
            onChange={(event) => onChange({ ...draft, click_url: event.target.value })}
          />
        </Field>

        <Field
          label="Alt text"
          hint="What a screen reader announces. Falls back to the headline if left blank."
        >
          <Input
            value={draft.alt_text}
            maxLength={255}
            onChange={(event) => onChange({ ...draft, alt_text: event.target.value })}
          />
        </Field>

        <Checkbox
          label="Active — include in this campaign's rotation"
          checked={draft.is_active}
          onChange={(event) => onChange({ ...draft, is_active: event.target.checked })}
        />
      </div>
    </Modal>
  );
}
