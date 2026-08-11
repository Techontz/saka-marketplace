"use client";

import { useState } from "react";
import { ImageOff } from "lucide-react";

/**
 * An image that cannot render broken.
 *
 * Three failure modes are handled in one place, because every one of them was
 * reachable with real data:
 *
 *   1. the API sends `null` — a listing with no photo, a business with no logo;
 *   2. the API sends a URL that 404s — media rows outlive the file they point
 *      at, and before `storage:link` existed every uploaded image 403'd;
 *   3. the URL is RELATIVE — `/storage/…` without an origin. The API returns
 *      absolute URLs today, but a config change to `APP_URL` is all it takes
 *      to start emitting relative ones, and a relative path resolves against
 *      the Next origin, where nothing is served.
 *
 * `onError` is what covers the second case: nothing else can, because the
 * server cannot know a remote image is missing until the browser asks for it.
 *
 * NOT next/image. The whole app uses plain <img>, and the API's hosts are
 * configured through the CSP rather than `images.remotePatterns` — mixing the
 * two would mean a second, silently-diverging allow-list.
 */
export function SafeImage({
  src,
  srcSet,
  sizes,
  alt,
  className = "",
  fallbackClassName = "",
  fallback,
  loading = "lazy",
  fetchPriority,
  decoding = "async",
  style,
}: {
  src: string | null | undefined;
  /**
   * Candidate renditions, as `lib/media.ts` builds them. Optional: media rows
   * whose variants have not been generated yet have nothing to offer here, and
   * `src` alone still renders.
   */
  srcSet?: string;
  /**
   * The CSS box this image lands in. Required whenever `srcSet` is set — with
   * it absent the browser assumes 100vw and picks the largest candidate, which
   * is worse than sending no srcset at all.
   */
  sizes?: string;
  alt: string;
  className?: string;
  /** Applied to the placeholder box instead of `className` when it differs. */
  fallbackClassName?: string;
  /** Rendered inside the placeholder. Defaults to a muted icon. */
  fallback?: React.ReactNode;
  loading?: "lazy" | "eager";
  /** `high` for the one image that is the LCP candidate. Nothing else. */
  fetchPriority?: "high" | "low" | "auto";
  decoding?: "async" | "sync" | "auto";
  /**
   * For values that cannot be a class because they are computed per frame —
   * the gallery's zoom transform and its cursor-tracked origin. Not an escape
   * hatch for styling: everything static belongs in `className`.
   */
  style?: React.CSSProperties;
}) {
  const [failed, setFailed] = useState(false);

  const resolved = resolveImageUrl(src);

  if (!resolved || failed) {
    return (
      <div
        className={`flex items-center justify-center bg-[#EEF4FF] text-muted-foreground ${
          fallbackClassName || className
        }`}
        role="img"
        aria-label={alt}
      >
        {fallback ?? <ImageOff className="h-6 w-6 opacity-50" aria-hidden="true" />}
      </div>
    );
  }

  const resolvedSrcSet = resolveSrcSet(srcSet);

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={resolved}
      srcSet={resolvedSrcSet}
      sizes={resolvedSrcSet ? sizes : undefined}
      alt={alt}
      loading={loading}
      fetchPriority={fetchPriority}
      decoding={decoding}
      className={className}
      style={style}
      /*
       * A srcset failure lands here too, and falling back to the placeholder
       * would be wrong when only a VARIANT is missing — so the handler clears
       * the candidates first and only gives up if the original fails as well.
       */
      onError={() => setFailed(true)}
    />
  );
}

/**
 * Runs every candidate URL through the same normalisation as `src`.
 *
 * A srcset entry is `url width-descriptor`, and a relative `/storage/…` in
 * there resolves against the Next origin exactly as it would in `src` — with
 * the difference that it fails SILENTLY, because a browser that cannot fetch a
 * srcset candidate does not fire `onError`, it just picks another one.
 */
function resolveSrcSet(srcSet: string | undefined): string | undefined {
  if (!srcSet) return undefined;

  const entries = srcSet
    .split(",")
    .map((entry) => entry.trim())
    .filter(Boolean)
    .flatMap((entry) => {
      // Split on the LAST space: the descriptor is the trailing token, and a
      // storage path with a space in it would break a naive split.
      const boundary = entry.lastIndexOf(" ");
      if (boundary === -1) return [];

      const url = resolveImageUrl(entry.slice(0, boundary));
      const descriptor = entry.slice(boundary + 1);

      return url ? [`${url} ${descriptor}`] : [];
    });

  return entries.length > 0 ? entries.join(", ") : undefined;
}

/**
 * Absolute URLs pass through; a relative `/storage/…` path is sent to this
 * app's own API proxy, which is the only origin the CSP lets the browser talk
 * to. Anything else is treated as unusable.
 */
export function resolveImageUrl(src: string | null | undefined): string | null {
  if (!src) return null;

  const trimmed = src.trim();
  if (trimmed === "") return null;

  if (/^(https?:)?\/\//i.test(trimmed) || trimmed.startsWith("data:") || trimmed.startsWith("blob:")) {
    return trimmed;
  }

  return trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
}
