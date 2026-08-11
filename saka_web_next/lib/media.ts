import type { ApiMedia } from "@/lib/types";

/**
 * Choosing which rendition of an uploaded image to download.
 *
 * The API has generated four WebP renditions of every upload since Milestone 1
 * — thumb/card/detail/full, see `saka.media.variants` — and `MediaResource`
 * returns all of them. Nothing on the frontend ever asked for one, so every
 * surface rendered `media.url`: the ORIGINAL. A 435px listing card in a grid of
 * twenty was pulling twenty 1600px JPEGs, which is the exact waste the variant
 * pipeline was built to remove.
 *
 * This module turns `variants` into a `srcset`, so the browser picks the
 * rendition that fits the box it is painting into. It is deliberately a pure
 * data transform with no React in it: the gallery, the cards, the map popup and
 * the avatars all need the same decision made the same way.
 *
 * FALLBACK IS THE POINT. Variants are produced by a queued job, so a freshly
 * uploaded image genuinely has none for a second or two, and a failed resize
 * leaves `processing_status: "failed"` with the original still perfectly
 * usable. Every function here degrades to the original rather than to nothing.
 */

/**
 * Widths from `config/saka.php`, used ONLY when the API omitted the width for a
 * variant. `MediaResource` sends the real post-resize width per variant and
 * that is always preferred — `scaleDown` never enlarges, so a 300px original
 * produces a "card" variant 300px wide, not 400, and a srcset that claimed 400
 * would make the browser pick it for boxes it cannot actually fill.
 */
const FALLBACK_VARIANT_WIDTHS: Record<string, number> = {
  thumb: 200,
  card: 400,
  detail: 800,
  full: 1600,
};

/** Ordered smallest to largest — the order the pipeline defines them in. */
export type VariantName = "thumb" | "card" | "detail" | "full";

const VARIANT_ORDER: VariantName[] = ["thumb", "card", "detail", "full"];

type Rendition = { url: string; width: number };

/**
 * The renditions of one media row, widest last.
 *
 * `variants` arrives as an OBJECT when populated and as an ARRAY when empty —
 * PHP's `[]` encodes to `[]`, not `{}`, so an unprocessed image serialises as a
 * JSON array. Treating that as a map would produce entries keyed "0", "1", so
 * the array case is discarded up front.
 */
function renditions(media: ApiMedia | null | undefined): Rendition[] {
  const variants = media?.variants;

  if (!variants || Array.isArray(variants) || typeof variants !== "object") {
    return [];
  }

  const found: Rendition[] = [];

  for (const [name, value] of Object.entries(variants as Record<string, unknown>)) {
    if (!value || typeof value !== "object") continue;

    const entry = value as { url?: unknown; width?: unknown };
    const url = typeof entry.url === "string" ? entry.url.trim() : "";
    if (url === "") continue;

    const width =
      typeof entry.width === "number" && entry.width > 0
        ? entry.width
        : FALLBACK_VARIANT_WIDTHS[name];

    if (!width) continue;

    found.push({ url, width });
  }

  return found.sort((a, b) => a.width - b.width);
}

/**
 * A `srcset` string, or undefined when there is nothing better than the
 * original to offer.
 *
 * The original is appended at its own intrinsic width when the API reported
 * one, so a browser on a 4K display can still reach full quality. It is left
 * out when `media.width` is unknown, because a descriptor guessed wrong is
 * worse than no descriptor: the browser would size its choice against a
 * fiction.
 */
export function imageSrcSet(media: ApiMedia | null | undefined): string | undefined {
  const sized = renditions(media);

  if (sized.length === 0) return undefined;

  const candidates = [...sized];
  const originalWidth = typeof media?.width === "number" ? media.width : null;
  const originalUrl = typeof media?.url === "string" ? media.url.trim() : "";

  // Only worth adding when it is genuinely larger than the biggest variant;
  // otherwise it is a duplicate entry at a descriptor the browser already has.
  if (originalUrl !== "" && originalWidth !== null) {
    const largest = candidates[candidates.length - 1];

    if (largest && originalWidth > largest.width) {
      candidates.push({ url: originalUrl, width: originalWidth });
    }
  }

  // A single candidate is not a choice — emit nothing and let `src` stand.
  if (candidates.length < 2) return undefined;

  return candidates.map((r) => `${r.url} ${r.width}w`).join(", ");
}

/**
 * The URL to put in `src`.
 *
 * `preferred` names the rendition that best fits the box. It is a HINT, not a
 * guarantee: the exact variant is used when present, otherwise the next size
 * UP is chosen and the original is the last resort. Rounding up rather than
 * down matters because `src` is what a browser without srcset support — and
 * every server-rendered crawler — actually downloads, and a card served the
 * 200px thumb looks soft on a retina screen in a way the 400px one does not.
 */
export function imageSrc(
  media: ApiMedia | null | undefined,
  preferred: VariantName = "card",
): string | null {
  if (!media) return null;

  const sized = renditions(media);
  const original = typeof media.url === "string" && media.url.trim() !== "" ? media.url.trim() : null;

  if (sized.length === 0) return original;

  const target = FALLBACK_VARIANT_WIDTHS[preferred] ?? FALLBACK_VARIANT_WIDTHS.card;
  const atLeastTarget = sized.find((r) => r.width >= target);

  return (atLeastTarget ?? sized[sized.length - 1])?.url ?? original;
}

/**
 * Everything an `<img>` needs for one media row, ready to spread.
 *
 *   <SafeImage {...imageProps(photo, "card", CARD_SIZES)} alt={…} />
 *
 * `sizes` is required alongside a srcset and has no safe default: it describes
 * the CSS box the image lands in, which only the call site knows. Omitting it
 * makes the browser assume 100vw and defeats the whole exercise on mobile,
 * where it would then pick the LARGEST candidate for a two-up grid.
 */
export function imageProps(
  media: ApiMedia | null | undefined,
  preferred: VariantName,
  sizes: string,
): { src: string | null; srcSet?: string; sizes?: string } {
  const srcSet = imageSrcSet(media);

  return {
    src: imageSrc(media, preferred),
    srcSet,
    // Pointless without candidates to choose between, and a stray `sizes` on a
    // plain img is noise in the DOM.
    sizes: srcSet ? sizes : undefined,
  };
}

/**
 * Shared `sizes` descriptors.
 *
 * Kept here rather than inline so the grid's breakpoints are stated ONCE. They
 * mirror the real layouts: `container-wide` caps at 1600px with 32px of
 * padding, the listing grid is 1-up below 640px, 2-up to 1024px and 3-up above.
 */
export const IMAGE_SIZES = {
  /** Listing / business cards in the main marketplace grid. */
  card: "(max-width: 640px) 50vw, (max-width: 1024px) 45vw, 500px",
  /** The horizontal card used in favourites and the map sidebar. */
  cardHorizontal: "(max-width: 640px) 40vw, 220px",
  /** The listing detail hero. Full width of the content column. */
  gallery: "(max-width: 1024px) 100vw, 800px",
  /** Gallery thumbnail strip. */
  thumbnail: "(max-width: 640px) 25vw, 180px",
  /** Fullscreen lightbox. */
  lightbox: "100vw",
  /** Avatars, logos and other small round images. */
  avatar: "96px",
} as const;

export { VARIANT_ORDER };
