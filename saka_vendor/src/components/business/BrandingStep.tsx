"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { ImageIcon, Upload } from "lucide-react";
import { useRef, useState } from "react";

import { Button, FormError } from "@/components/ui";
import { request } from "@/lib/api/http";
import { PROFILE_QUERY_KEY } from "@/providers/VendorProvider";

/**
 * Logo and cover upload.
 *
 * Goes through the proxy as multipart. `request` is used directly rather than
 * the JSON helpers because a FormData body must NOT get a Content-Type header —
 * the browser has to set it with the multipart boundary, and overriding it
 * produces a request the server cannot parse.
 */
export function BrandingStep({
  logoUrl,
  coverUrl,
}: {
  logoUrl: string | null | undefined;
  coverUrl: string | null | undefined;
}) {
  return (
    <div className="space-y-6">
      <ImageSlot
        kind="logo"
        label="Logo"
        hint="Square works best. Shown next to your name everywhere."
        url={logoUrl}
        aspect="aspect-square w-32"
      />
      <ImageSlot
        kind="cover"
        label="Cover image"
        hint="Wide banner across the top of your public profile."
        url={coverUrl}
        aspect="aspect-[3/1] w-full"
      />
    </div>
  );
}

function ImageSlot({
  kind,
  label,
  hint,
  url,
  aspect,
}: {
  kind: "logo" | "cover";
  label: string;
  hint: string;
  url: string | null | undefined;
  aspect: string;
}) {
  const queryClient = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData();
      body.append("file", file);

      return request(`/api/saka/seller/vendor-profile/branding/${kind}`, {
        method: "POST",
        body,
      });
    },
    onSuccess: async () => {
      setPreview(null);
      await queryClient.invalidateQueries({ queryKey: PROFILE_QUERY_KEY });
    },
    onError: () => setPreview(null),
  });

  const remove = useMutation({
    mutationFn: () =>
      request(`/api/saka/seller/vendor-profile/branding/${kind}`, { method: "DELETE" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PROFILE_QUERY_KEY }),
  });

  const shown = preview ?? url;

  return (
    <div>
      <p className="text-[13px] font-medium text-ink">{label}</p>
      <p className="mt-0.5 mb-2 text-xs text-ink-soft">{hint}</p>

      <div className="flex flex-wrap items-start gap-4">
        <div
          className={`${aspect} max-w-md overflow-hidden rounded-[var(--radius-control)] border border-line bg-muted-soft`}
        >
          {shown ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={shown} alt="" className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center">
              <ImageIcon aria-hidden className="h-6 w-6 text-ink-faint" />
            </div>
          )}
        </div>

        <div className="flex flex-col gap-2">
          <input
            ref={inputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (!file) return;

              // Optimistic preview so the slot fills immediately rather than
              // sitting empty for the length of the upload.
              setPreview(URL.createObjectURL(file));
              upload.mutate(file);
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
            {url ? "Replace" : "Upload"}
          </Button>

          {url && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              loading={remove.isPending}
              onClick={() => remove.mutate()}
            >
              Remove
            </Button>
          )}

          <p className="max-w-[180px] text-[11px] text-ink-faint">
            JPG, PNG or WebP, up to 5 MB.
          </p>
        </div>
      </div>

      <FormError error={upload.error ?? remove.error} />
    </div>
  );
}
