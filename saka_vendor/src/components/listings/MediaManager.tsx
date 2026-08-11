"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Star, Trash2, Upload } from "lucide-react";
import { useRef, useState } from "react";

import { Badge, Button, FormError } from "@/components/ui";
import { request } from "@/lib/api/http";
import type { ListingMedia } from "@/lib/api/types";

/**
 * Listing gallery management.
 *
 * Reordering uses explicit move buttons rather than drag-and-drop. Drag is
 * nicer with a mouse and close to unusable on a phone, and a large share of
 * vendors in this market manage listings from one — so the tappable, accessible
 * control is the primary one, not a fallback.
 *
 * The whole order is submitted at once (`PATCH media/reorder`), so a failed
 * request leaves the gallery in its previous order rather than half-rearranged.
 */
export function MediaManager({
  listingUuid,
  media,
  onChanged,
}: {
  listingUuid: string;
  media: ListingMedia[];
  onChanged: () => void;
}) {
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [pendingCount, setPendingCount] = useState(0);

  const base = `/api/saka/seller/listings/${listingUuid}/media`;

  const invalidate = async () => {
    onChanged();
    await queryClient.invalidateQueries({ queryKey: ["vendor-listing", listingUuid] });
  };

  const upload = useMutation({
    mutationFn: async (files: File[]) => {
      // Sequential, not parallel: the API rate-limits media uploads, and firing
      // ten at once trips it and fails most of them.
      for (const file of files) {
        const body = new FormData();
        // The listing media endpoint reads "image"; branding and verification
        // uploads use different field names. Each matches its own endpoint.
        body.append("image", file);
        await request(base, { method: "POST", body });
        setPendingCount((count) => Math.max(0, count - 1));
      }
    },
    onSettled: async () => {
      setPendingCount(0);
      await invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (uuid: string) => request(`${base}/${uuid}`, { method: "DELETE" }),
    onSuccess: invalidate,
  });

  const makePrimary = useMutation({
    mutationFn: (uuid: string) => request(`${base}/${uuid}/primary`, { method: "POST" }),
    onSuccess: invalidate,
  });

  const reorder = useMutation({
    mutationFn: (order: string[]) =>
      request(`${base}/reorder`, { method: "PATCH", body: { order } }),
    onSuccess: invalidate,
  });

  const move = (index: number, direction: -1 | 1) => {
    const next = [...media];
    const target = index + direction;

    if (target < 0 || target >= next.length) return;

    [next[index], next[target]] = [next[target], next[index]];
    reorder.mutate(next.map((item) => item.uuid));
  };

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-[13px] font-medium text-ink">Photos</p>
          <p className="text-xs text-ink-soft">
            The first photo is the one buyers see in search results.
          </p>
        </div>

        <input
          ref={inputRef}
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(event) => {
            const files = Array.from(event.target.files ?? []);
            if (files.length === 0) return;

            setPendingCount(files.length);
            upload.mutate(files);
            event.target.value = "";
          }}
        />

        <Button
          type="button"
          variant="secondary"
          size="sm"
          loading={upload.isPending}
          onClick={() => inputRef.current?.click()}
        >
          <Upload aria-hidden className="h-4 w-4" />
          {upload.isPending && pendingCount > 0 ? `Uploading ${pendingCount}…` : "Add photos"}
        </Button>
      </div>

      <FormError error={upload.error ?? remove.error ?? makePrimary.error ?? reorder.error} />

      {media.length === 0 ? (
        <div className="rounded-[var(--radius-control)] border border-dashed border-line-strong px-6 py-10 text-center">
          <p className="text-sm text-ink-soft">No photos yet.</p>
          <p className="mt-1 text-xs text-ink-faint">
            Listings with photos get far more views. JPG, PNG or WebP, up to 5&nbsp;MB each.
          </p>
        </div>
      ) : (
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {media.map((item, index) => (
            <li
              key={item.uuid}
              className="overflow-hidden rounded-[var(--radius-control)] border border-line"
            >
              <div className="relative aspect-[4/3] bg-muted-soft">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={item.variants?.card?.url ?? item.url}
                  alt={item.alt_text ?? ""}
                  loading="lazy"
                  className="h-full w-full object-cover"
                />

                {item.is_primary && (
                  <span className="absolute top-2 left-2">
                    <Badge tone="brand">Main</Badge>
                  </span>
                )}

                {/* Variants are generated by a queued job, so a freshly
                    uploaded photo is briefly "processing" — saying so beats a
                    silently lower-quality image. */}
                {item.processing_status === "pending" && (
                  <span className="absolute top-2 right-2">
                    <Badge tone="muted">Processing</Badge>
                  </span>
                )}
                {item.processing_status === "failed" && (
                  <span className="absolute top-2 right-2">
                    <Badge tone="danger">Failed</Badge>
                  </span>
                )}
              </div>

              <div className="flex items-center justify-between gap-1 border-t border-line px-1.5 py-1.5">
                <div className="flex gap-0.5">
                  <IconButton
                    label="Move earlier"
                    disabled={index === 0 || reorder.isPending}
                    onClick={() => move(index, -1)}
                  >
                    <ArrowLeft aria-hidden className="h-3.5 w-3.5" />
                  </IconButton>
                  <IconButton
                    label="Move later"
                    disabled={index === media.length - 1 || reorder.isPending}
                    onClick={() => move(index, 1)}
                  >
                    <ArrowRight aria-hidden className="h-3.5 w-3.5" />
                  </IconButton>
                </div>

                <div className="flex gap-0.5">
                  {!item.is_primary && (
                    <IconButton
                      label="Make main photo"
                      disabled={makePrimary.isPending}
                      onClick={() => makePrimary.mutate(item.uuid)}
                    >
                      <Star aria-hidden className="h-3.5 w-3.5" />
                    </IconButton>
                  )}
                  <IconButton
                    label="Delete photo"
                    disabled={remove.isPending}
                    onClick={() => remove.mutate(item.uuid)}
                  >
                    <Trash2 aria-hidden className="h-3.5 w-3.5 text-danger" />
                  </IconButton>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {/*
        Video is requested but not supported by the API: the media pipeline
        accepts image/jpeg, image/png and image/webp only, with no transcoding,
        no poster-frame extraction and no storage sizing for it. Saying so beats
        an upload button that rejects every file a vendor picks.
      */}
      <p className="mt-3 text-xs text-ink-faint">
        Video isn&apos;t supported yet — the platform accepts images only.
      </p>
    </div>
  );
}

function IconButton({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string;
  disabled?: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className="flex h-7 w-7 items-center justify-center rounded text-ink-soft transition-colors hover:bg-muted-soft hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
    >
      {children}
    </button>
  );
}
