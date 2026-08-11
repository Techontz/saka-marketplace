"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import { Check, Upload } from "lucide-react";
import { useRef, useState } from "react";

import {
  Button,
  Field,
  FormError,
  Input,
  Modal,
  Select,
  Textarea,
} from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import type {
  Envelope,
  PromotableItem,
  PromotionOptions,
  PromotionRequest,
} from "@/lib/vendor/api/types";

/**
 * The promotion request wizard.
 *
 * Five steps, and the ORDER is forced by how artwork works rather than by
 * taste: media is polymorphic and needs a row to attach to, so the request has
 * to exist in the database before a banner can be uploaded to it. That is why
 * step 3 creates a DRAFT and step 5 submits it — the draft is what the upload
 * hangs off, and it keeps half-finished wizards out of the review queue.
 *
 * Nothing here mentions price or payment, because SAKA cannot take any. The
 * final button says "Submit request", not "Buy" or "Pay", and the confirmation
 * says a human will review it.
 */

type Step = 1 | 2 | 3 | 4 | 5;

const STEP_LABELS: Record<Step, string> = {
  1: "What to promote",
  2: "Where it appears",
  3: "Dates and wording",
  4: "Artwork",
  5: "Review",
};

export function PromotionWizard({
  resume,
  onClose,
  onSubmitted,
}: {
  /** An existing draft to pick up at the artwork step. */
  resume?: PromotionRequest | null;
  onClose: () => void;
  onSubmitted: () => Promise<void> | void;
}) {
  // Resuming a draft skips straight to what is missing from it.
  const [step, setStep] = useState<Step>(resume ? 4 : 1);

  const [item, setItem] = useState<PromotableItem | null>(null);
  const [placement, setPlacement] = useState("");
  const [start, setStart] = useState("");
  const [end, setEnd] = useState("");
  const [headline, setHeadline] = useState("");
  const [body, setBody] = useState("");
  const [cta, setCta] = useState("View listing");

  /** The draft, once step 3 has created it. */
  const [draft, setDraft] = useState<PromotionRequest | null>(resume ?? null);

  const options = useQuery({
    queryKey: ["promotion-options"],
    queryFn: () => apiGet<Envelope<PromotionOptions>>("/seller/promotions/options").then((r) => r.data),
    staleTime: 30 * 60 * 1000,
  });

  const promotable = useQuery({
    queryKey: ["promotable"],
    queryFn: () => apiGet<Envelope<PromotableItem[]>>("/seller/promotions/promotable").then((r) => r.data),
    enabled: !resume,
  });

  const create = useMutation({
    mutationFn: () =>
      apiSend<Envelope<PromotionRequest>>("/seller/promotions", "POST", {
        promotable_type: item?.type,
        promotable_uuid: item?.uuid,
        placement,
        requested_start: start,
        requested_end: end,
        headline,
        body: body || null,
        cta_label: cta || null,
      }).then((r) => r.data),
    onSuccess: (created) => {
      setDraft(created);
      setStep(4);
    },
  });

  const submit = useMutation({
    mutationFn: () => apiSend(`/seller/promotions/${draft?.uuid}/submit`, "POST"),
    onSuccess: async () => {
      await onSubmitted();
    },
  });

  const placements = (options.data?.placements ?? []).filter((p) => p.vendor_requestable);
  const chosenPlacement = placements.find((p) => p.value === placement);
  const items = promotable.data ?? [];

  /** Today, as the date input wants it. A vendor cannot book the past. */
  const today = new Date().toISOString().slice(0, 10);

  const canContinue =
    step === 1
      ? item !== null
      : step === 2
        ? placement !== ""
        : step === 3
          ? start !== "" && end !== "" && end > start && headline.trim().length >= 2
          : step === 4
            ? // Desktop artwork is required; the API refuses a submission
              // without it, so the button must not pretend otherwise.
              Boolean(draft?.creative.image?.url)
            : true;

  return (
    <Modal
      open
      onClose={onClose}
      title="Promote a listing"
      description={`Step ${step} of 5 — ${STEP_LABELS[step]}`}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>

          {step > 1 && step < 5 && !resume && (
            <Button variant="secondary" onClick={() => setStep((step - 1) as Step)}>
              Back
            </Button>
          )}

          {step < 3 && (
            <Button variant="primary" disabled={!canContinue} onClick={() => setStep((step + 1) as Step)}>
              Continue
            </Button>
          )}

          {step === 3 && (
            <Button
              variant="primary"
              disabled={!canContinue}
              loading={create.isPending}
              onClick={() => create.mutate()}
            >
              Continue
            </Button>
          )}

          {step === 4 && (
            <Button variant="primary" disabled={!canContinue} onClick={() => setStep(5)}>
              Continue
            </Button>
          )}

          {step === 5 && (
            <Button variant="primary" loading={submit.isPending} onClick={() => submit.mutate()}>
              Submit request
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        <StepBar current={step} />

        <FormError error={create.error ?? submit.error} />

        {step === 1 && (
          <div>
            {promotable.isPending ? (
              <p className="text-sm text-ink-soft">Loading your listings…</p>
            ) : items.length === 0 ? (
              /*
               * A real empty state, not a disabled dropdown. Only PUBLISHED
               * listings can be promoted, so a vendor with none needs telling
               * why rather than being shown an empty picker.
               */
              <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-3 text-sm text-ink-soft">
                You have nothing to promote yet. Only published listings and a completed business
                profile can be promoted.
              </p>
            ) : (
              <ul className="max-h-72 space-y-2 overflow-y-auto">
                {items.map((candidate) => {
                  const key = `${candidate.type}:${candidate.uuid ?? "self"}`;
                  const selected =
                    item?.type === candidate.type && item?.uuid === candidate.uuid;

                  return (
                    <li key={key}>
                      <button
                        type="button"
                        onClick={() => setItem(candidate)}
                        className={`flex w-full items-center gap-3 rounded-[var(--radius-control)] border p-2 text-left transition ${
                          selected ? "border-brand bg-brand-soft/40" : "border-line hover:border-line-strong"
                        }`}
                      >
                        {candidate.image_url ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img
                            src={candidate.image_url}
                            alt=""
                            className="h-10 w-14 shrink-0 rounded object-cover"
                          />
                        ) : (
                          <span className="flex h-10 w-14 shrink-0 items-center justify-center rounded bg-muted-soft text-[10px] text-ink-faint">
                            No photo
                          </span>
                        )}

                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-medium text-ink">
                            {candidate.label}
                          </span>
                          <span className="block text-xs text-ink-faint capitalize">
                            {candidate.type}
                          </span>
                        </span>

                        {selected && <Check aria-hidden className="h-4 w-4 shrink-0 text-brand" />}
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        )}

        {step === 2 && (
          <Field label="Placement" required hint={chosenPlacement?.description}>
            <Select value={placement} onChange={(event) => setPlacement(event.target.value)}>
              <option value="">Choose where it appears…</option>
              {placements.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </Field>
        )}

        {step === 3 && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Start date" required>
                <Input
                  type="date"
                  value={start}
                  // The API refuses a past start, so the control does too —
                  // a date picker that offers a day the server will reject is
                  // a form that fails after the vendor has finished filling it.
                  min={today}
                  onChange={(event) => setStart(event.target.value)}
                />
              </Field>
              <Field
                label="End date"
                required
                error={start && end && end <= start ? "The end date must be after the start." : undefined}
              >
                <Input
                  type="date"
                  value={end}
                  min={start || today}
                  onChange={(event) => setEnd(event.target.value)}
                />
              </Field>
            </div>

            <Field label="Headline" required hint="The main line people read. Keep it short.">
              <Input
                value={headline}
                maxLength={120}
                placeholder="Modern two-bedroom apartment in Masaki"
                onChange={(event) => setHeadline(event.target.value)}
              />
            </Field>

            <Field label="Description">
              <Textarea
                rows={2}
                maxLength={240}
                value={body}
                placeholder="Sea view, secure parking, ready to move in."
                onChange={(event) => setBody(event.target.value)}
              />
            </Field>

            <Field label="Button label">
              <Input value={cta} maxLength={40} onChange={(event) => setCta(event.target.value)} />
            </Field>

            {/*
              * Stated up front, not discovered later.
              *
              * A vendor promotion always links to the SAKA page for the thing
              * being promoted — there is no destination field anywhere in this
              * flow. Saying so here prevents the reasonable question "where do
              * I put my own website?" from becoming a support ticket.
              */}
            <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
              Your promotion always links to its page on SAKA, so people land on the listing itself.
            </p>
          </div>
        )}

        {step === 4 && draft && (
          <div className="space-y-4">
            <ArtworkUpload
              requestUuid={draft.uuid}
              kind="desktop"
              label="Desktop artwork"
              required
              ratio={chosenPlacement?.aspect_ratio.desktop ?? 8}
              url={draft.creative.image?.url ?? null}
              onUploaded={setDraft}
            />

            <ArtworkUpload
              requestUuid={draft.uuid}
              kind="mobile"
              label="Mobile artwork"
              ratio={chosenPlacement?.aspect_ratio.mobile ?? 3}
              url={draft.creative.mobile_image?.url ?? null}
              onUploaded={setDraft}
            />

            <p className="text-xs text-ink-faint">
              Mobile artwork is optional. Without it your desktop image is used on phones, scaled to
              fit.
            </p>
          </div>
        )}

        {step === 5 && draft && (
          <dl className="space-y-3 text-sm">
            <Summary label="Promoting" value={draft.promoted.label ?? "—"} />
            <Summary label="Placement" value={draft.placement_label} />
            <Summary label="Dates" value={`${draft.requested_start} → ${draft.requested_end}`} />
            <Summary label="Headline" value={draft.creative.headline} />
            {draft.creative.body && <Summary label="Description" value={draft.creative.body} />}
            {draft.creative.cta_label && <Summary label="Button" value={draft.creative.cta_label} />}
            <Summary label="Links to" value={draft.creative.destination_url ?? "—"} />

            {draft.creative.image?.url && (
              <div>
                <dt className="text-xs text-ink-soft">Artwork</dt>
                <dd className="mt-1">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={draft.creative.image.url}
                    alt=""
                    className="w-full rounded border border-line object-cover"
                  />
                </dd>
              </div>
            )}

            <p className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2 text-xs text-ink-soft">
              SAKA will review your request. Nothing is charged — we will be in touch about pricing
              before anything goes live.
            </p>
          </dl>
        )}
      </div>
    </Modal>
  );
}

/**
 * Where the vendor is in the flow.
 *
 * A row of segments rather than numbered circles with labels: at 360px the
 * labelled version wraps onto three lines and pushes the actual form off the
 * screen. The step name is already in the modal's description.
 */
function StepBar({ current }: { current: Step }) {
  return (
    <ol className="flex gap-1" aria-label={`Step ${current} of 5`}>
      {([1, 2, 3, 4, 5] as Step[]).map((step) => (
        <li
          key={step}
          aria-current={step === current ? "step" : undefined}
          className={`h-1 flex-1 rounded-full transition-colors ${
            step <= current ? "bg-brand" : "bg-muted-soft"
          }`}
        />
      ))}
    </ol>
  );
}

function Summary({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-3">
      <dt className="shrink-0 text-xs text-ink-soft sm:w-28 sm:pt-0.5">{label}</dt>
      <dd className="min-w-0 break-words text-sm text-ink">{value}</dd>
    </div>
  );
}

/**
 * Upload one piece of artwork to the draft.
 *
 * Multipart straight to the API, which routes it through the SAME media
 * pipeline as listing photos — MIME sniffed from magic bytes rather than the
 * filename, EXIF (including the GPS tags that would leak a seller's address)
 * stripped, and the WebP variants the banner renders from generated. A separate
 * upload path here would silently skip all three.
 */
function ArtworkUpload({
  requestUuid,
  kind,
  label,
  required,
  ratio,
  url,
  onUploaded,
}: {
  requestUuid: string;
  kind: "desktop" | "mobile";
  label: string;
  required?: boolean;
  ratio: number;
  url: string | null;
  onUploaded: (request: PromotionRequest) => void;
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

      // Content-Type is deliberately unset: the browser has to add it itself so
      // it can append the multipart boundary.
      const response = await fetch(`/api/saka/seller/promotions/${requestUuid}/artwork/${kind}`, {
        method: "POST",
        body: form,
      });

      const payload = await response.json().catch(() => null);

      if (!response.ok) {
        throw new Error(payload?.error?.message ?? "The upload failed.");
      }

      onUploaded(payload.data as PromotionRequest);
    } catch (uploadError) {
      setError(uploadError);
    } finally {
      setUploading(false);
      // Cleared so re-picking the same file fires `change` again — otherwise a
      // retry after a failure silently does nothing.
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  return (
    <div>
      <p className="mb-1.5 flex items-center gap-1 text-[13px] font-medium text-ink">
        {label}
        {required && (
          <span aria-hidden className="text-danger">
            *
          </span>
        )}
      </p>

      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={uploading}
        aria-label={`Upload ${label}`}
        className="flex w-full items-center justify-center overflow-hidden rounded-[var(--radius-control)] border border-dashed border-line bg-muted-soft/40 transition hover:border-brand disabled:opacity-50"
        // The placement's own ratio, so the vendor sees the shape their artwork
        // will actually be shown in rather than guessing from a square box.
        style={{ aspectRatio: ratio }}
      >
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={url} alt="" className="h-full w-full object-cover" />
        ) : (
          <span className="flex items-center gap-1.5 px-3 text-xs text-ink-faint">
            <Upload aria-hidden className="h-3.5 w-3.5" />
            {uploading ? "Uploading…" : "Choose an image"}
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

      {error != null && (
        <div className="mt-2">
          <FormError error={error} />
        </div>
      )}
    </div>
  );
}
