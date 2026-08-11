import Image from "next/image";

/**
 * The SAKA brand mark.
 *
 * A copy of the marketplace's `components/ui/Logo.tsx`. The three apps are
 * separate Next projects with no shared package; this file has no dependency
 * beyond `next/image`, and one duplicated component is cheaper than the three
 * brand marks drifting out of alignment.
 *
 * The only place the logo image appears. Every header, footer, auth screen and
 * loading state renders this component, so a change of asset, of aspect ratio,
 * or of how the mark sits on a dark background happens once.
 *
 * ── ASPECT RATIO ──────────────────────────────────────────────────────────
 * The source is 1254 × 392 (3.199 : 1). The wrapper sets a HEIGHT and the image
 * is `h-full w-auto`, so width is always derived and the mark can never be
 * stretched. `next/image` still receives intrinsic width and height — at the
 * largest step the size can reach — because that is what reserves the space
 * and stops the header reflowing as the image decodes.
 *
 * ── RESPONSIVE SIZING ─────────────────────────────────────────────────────
 * Sizes are Tailwind class strings rather than inline pixels, so one `size`
 * can mean 44px on a phone and 56px on a laptop. Inline styles cannot express
 * a breakpoint, and shipping two logos with `hidden`/`block` would download
 * both.
 *
 * ── LIGHT AND DARK ────────────────────────────────────────────────────────
 * The asset is a DARK navy-and-teal wordmark on transparency. It is legible on
 * white and invisible on the navy footer, so `variant` does not switch files —
 * there is only one file — it decides whether the mark needs a surface:
 *
 *   variant="dark"  → the mark as-is, for light backgrounds. (default)
 *   variant="light" → the mark on a white plate, for dark backgrounds.
 *
 * A CSS filter was the alternative and is wrong here: `invert()` on a
 * two-colour gradient logo turns the teal a muddy pink and the navy a pale
 * yellow. The plate keeps the brand colours exactly as drawn.
 *
 * If a knocked-out white version of the mark is ever supplied, `variant="light"`
 * becomes a file swap and every call site already passes the right value.
 */

/** Intrinsic pixels of `public/saka.PNG`. */
const SOURCE_WIDTH = 1254;
const SOURCE_HEIGHT = 392;
const ASPECT = SOURCE_WIDTH / SOURCE_HEIGHT;

/**
 * Named steps. `height` is the tallest the step can render, and is what
 * `next/image` is told; `className` is what actually sizes it per breakpoint.
 */
const SIZES = {
  /** Inline with a text label — vendor and admin sidebars. */
  xs: { className: "h-7 sm:h-8", height: 32 },
  /** Small chrome. */
  sm: { className: "h-8 sm:h-9", height: 36 },
  /** Auth cards and footers. */
  md: { className: "h-10 sm:h-12", height: 48 },
  /** Dialogs and full-page loaders. */
  lg: { className: "h-12 sm:h-14", height: 56 },
  /**
   * The site header. 44px on a phone, 56px on a laptop — the top of the
   * requested mobile band and the middle of the desktop one, which is as large
   * as the 80px header allows while keeping the mark optically centred.
   */
  brand: { className: "h-11 md:h-14", height: 56 },
  /** Marketing moments — the location welcome dialog. */
  xl: { className: "h-14 sm:h-16", height: 64 },
} as const;

export type LogoSize = keyof typeof SIZES;

export function Logo({
  size = "sm",
  variant = "dark",
  priority = false,
  className,
  alt = "SAKA",
}: {
  size?: LogoSize;
  /** `dark` = the mark on a light surface. `light` = plated, for dark surfaces. */
  variant?: "light" | "dark";
  /**
   * Set on the mark in the page header, which is usually the LCP element on a
   * cold load. Everywhere else it should stay false so the logo does not
   * compete with the content image for bandwidth.
   */
  priority?: boolean;
  className?: string;
  /**
   * Defaults to the brand name. Pass `alt=""` where the logo sits beside a
   * text label that already says "SAKA" — a screen reader announcing it twice
   * is worse than not announcing it at all.
   */
  alt?: string;
}) {
  const { className: sizeClass, height } = SIZES[size];
  const width = Math.round(height * ASPECT);

  const image = (
    <Image
      src="/saka.PNG"
      alt={alt}
      width={width}
      height={height}
      priority={priority}
      /*
       * NO `sizes` PROP, deliberately.
       *
       * This is a fixed-size UI element. Given width and height alone, Next
       * builds a srcSet from `imageSizes` (16…384) and serves a file matched to
       * the slot. Adding `sizes` switches it into responsive mode, where it
       * picks from `deviceSizes` instead and ships the 3840px variant for a
       * 44px logo — which is exactly what happened the first time.
       */
      className="h-full w-auto object-contain"
    />
  );

  if (variant === "light") {
    return (
      <span
        // A plate, not a filter — see the note above. `py-1.5 px-2.5` keeps the
        // ink clear of the edge at every step without a second size table.
        className={`inline-flex shrink-0 items-center rounded-lg bg-white px-2.5 py-1.5 ${className ?? ""}`}
      >
        <span className={`inline-flex ${sizeClass}`}>{image}</span>
      </span>
    );
  }

  return (
    <span className={`inline-flex shrink-0 items-center ${sizeClass} ${className ?? ""}`}>
      {image}
    </span>
  );
}
