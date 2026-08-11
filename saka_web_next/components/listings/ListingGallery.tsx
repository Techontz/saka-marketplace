"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ArrowLeft, ArrowRight, Expand, Minus, Plus, RotateCcw, X, ZoomIn } from "lucide-react";

import { SafeImage } from "@/components/ui/SafeImage";
import { IMAGE_SIZES, imageSrc, imageSrcSet } from "@/lib/media";
import type { ApiMedia } from "@/lib/types";

/**
 * The listing gallery.
 *
 * Keeps the original layout — a 460px main image with a four-up thumbnail
 * strip under it — and everything it already did: arrows, a counter, keyboard
 * navigation, swipe, and a fullscreen lightbox. With ONE image every control
 * still disappears; a single photo with a "1 / 1" badge and dead arrows looks
 * broken in a way an unadorned image does not.
 *
 * What is new is the zoom, in two forms, because they answer different
 * questions:
 *
 *   - HOVER (desktop only): the pointer becomes a lens. The image scales inside
 *     its existing frame with `transform-origin` tracking the cursor, so the
 *     region under the pointer is the region magnified. It is a compositor-only
 *     transform on a box that already clips, which is why it cannot shift the
 *     layout and does not cost a repaint. No magnifying-glass cursor, no second
 *     panel floating beside the image.
 *
 *   - LIGHTBOX: explicit controls, because "look closer at the crack in the
 *     wall" is a deliberate act, not a hover. Zoomed, the image can be dragged.
 *
 * Hover zoom is suppressed on coarse pointers and under `prefers-reduced-motion`
 * — on touch there is no hover to speak of, and a viewport that lurches under
 * the finger is the exact thing that setting asks us not to do.
 */

/** Below this a horizontal drag is a scroll, not a swipe. */
const SWIPE_THRESHOLD_PX = 50;

/** How far the hover lens magnifies. Enough to read a number plate, not so far
 *  that the image turns to mush at typical upload resolutions. */
const HOVER_ZOOM = 2.2;

const LIGHTBOX_ZOOM_STEPS = [1, 1.5, 2, 3, 4] as const;

export function ListingGallery({ images, title }: { images: ApiMedia[]; title: string }) {
  const [index, setIndex] = useState(0);
  const [lightbox, setLightbox] = useState(false);

  const touchStart = useRef<{ x: number; y: number } | null>(null);

  const count = images.length;
  const hasMultiple = count > 1;

  /*
   * Lightbox zoom state, declared before the navigation callbacks because they
   * reset it. Moving to another photo must not carry the previous one's zoom
   * and pan with it — landing on a wide shot already magnified 4× and parked in
   * a corner reads as a broken viewer.
   *
   * `dragging` is STATE, not a flag on the ref, because the cursor and the
   * transition both depend on it during render, and a ref read while rendering
   * is a value React has not agreed to re-render for.
   */
  const [lightboxZoom, setLightboxZoom] = useState(1);
  const [pan, setPan] = useState({ x: 0, y: 0 });
  const [dragging, setDragging] = useState(false);
  const dragOrigin = useRef<{ x: number; y: number; panX: number; panY: number } | null>(null);

  const resetZoom = useCallback(() => {
    setLightboxZoom(1);
    setPan({ x: 0, y: 0 });
    setDragging(false);
  }, []);

  /** Jump to a specific photo. The one place index changes, so the one place
   *  zoom is reset — an effect watching `index` would be a cascading render. */
  const select = useCallback(
    (next: number) => {
      setIndex(next);
      resetZoom();
    },
    [resetZoom],
  );

  const go = useCallback(
    (delta: number) => {
      if (count === 0) return;
      setIndex((current) => (current + delta + count) % count);
      resetZoom();
    },
    [count, resetZoom],
  );

  /*
   * Whether this pointer can hover.
   *
   * Read in an effect rather than during render: `matchMedia` does not exist on
   * the server, and branching on it while rendering would produce markup that
   * disagrees with the client's first paint. The gallery starts in the
   * no-hover state, which is the safe one — it renders a plain image.
   */
  const [canHover, setCanHover] = useState(false);

  useEffect(() => {
    const query = window.matchMedia("(hover: hover) and (pointer: fine)");
    const motion = window.matchMedia("(prefers-reduced-motion: reduce)");

    const sync = () => setCanHover(query.matches && !motion.matches);

    sync();
    query.addEventListener("change", sync);
    motion.addEventListener("change", sync);

    return () => {
      query.removeEventListener("change", sync);
      motion.removeEventListener("change", sync);
    };
  }, []);

  /*
   * Preload the neighbours.
   *
   * Without this, every arrow press is a fresh network request and the image
   * pops in blank — the single thing that makes a gallery feel cheap. Only the
   * immediate neighbours: preloading all twenty photos of a property would
   * spend a phone's data allowance on images nobody scrolls to.
   */
  useEffect(() => {
    if (count < 2) return;

    const neighbours = [(index + 1) % count, (index - 1 + count) % count];

    for (const neighbour of neighbours) {
      const media = images[neighbour];
      if (!media) continue;

      const preload = new Image();
      const srcSet = imageSrcSet(media);
      if (srcSet) preload.srcset = srcSet;
      preload.sizes = IMAGE_SIZES.gallery;
      preload.src = imageSrc(media, "detail") ?? media.url;
    }
  }, [index, images, count]);

  const onTouchStart = (event: React.TouchEvent) => {
    const touch = event.touches[0];
    touchStart.current = { x: touch.clientX, y: touch.clientY };
  };

  const onTouchEnd = (event: React.TouchEvent) => {
    const start = touchStart.current;
    touchStart.current = null;

    if (!start || !hasMultiple) return;

    const touch = event.changedTouches[0];
    const dx = touch.clientX - start.x;
    const dy = touch.clientY - start.y;

    // Ignore mostly-vertical gestures: that is the page scrolling.
    if (Math.abs(dx) < SWIPE_THRESHOLD_PX || Math.abs(dx) < Math.abs(dy)) return;

    go(dx < 0 ? 1 : -1);
  };

  // ------------------------------------------------------------ hover lens

  const [lens, setLens] = useState<{ x: number; y: number } | null>(null);

  /*
   * Whether the lens layer has ever been needed on this page.
   *
   * Once true it stays mounted and is hidden with opacity rather than being
   * unmounted, because the lens renders the `full` (1600px) rendition and
   * remounting it would re-enter the fetch-and-decode path on every hover —
   * a visible stutter the second time round. Gating it on a real hover is what
   * keeps that download off the page for anyone who never zooms, and off
   * touch devices entirely.
   */
  const [lensArmed, setLensArmed] = useState(false);

  const onLensMove = (event: React.MouseEvent<HTMLDivElement>) => {
    if (!canHover) return;

    if (!lensArmed) setLensArmed(true);

    const box = event.currentTarget.getBoundingClientRect();

    // Clamped: a pointer leaving during a drag can report a value outside the
    // box, and an origin past 100% snaps the image to a corner.
    const x = Math.min(100, Math.max(0, ((event.clientX - box.left) / box.width) * 100));
    const y = Math.min(100, Math.max(0, ((event.clientY - box.top) / box.height) * 100));

    setLens({ x, y });
  };

  // --------------------------------------------------------- lightbox zoom

  const zoomBy = useCallback((direction: 1 | -1) => {
    setLightboxZoom((current) => {
      const position = LIGHTBOX_ZOOM_STEPS.indexOf(current as (typeof LIGHTBOX_ZOOM_STEPS)[number]);
      // A zoom set by double-click may not be one of the steps; fall back to
      // the nearest one so the buttons still move in sensible increments.
      const from =
        position === -1
          ? LIGHTBOX_ZOOM_STEPS.reduce((best, step, i) =>
              Math.abs(step - current) < Math.abs(LIGHTBOX_ZOOM_STEPS[best] - current) ? i : best, 0)
          : position;

      const next = Math.min(LIGHTBOX_ZOOM_STEPS.length - 1, Math.max(0, from + direction));
      // Returning to 1× must also recentre, or the image stays parked off-screen.
      if (LIGHTBOX_ZOOM_STEPS[next] === 1) setPan({ x: 0, y: 0 });

      return LIGHTBOX_ZOOM_STEPS[next];
    });
  }, []);

  const onDragStart = (event: React.MouseEvent) => {
    if (lightboxZoom === 1) return;
    event.preventDefault();
    dragOrigin.current = { x: event.clientX, y: event.clientY, panX: pan.x, panY: pan.y };
    setDragging(true);
  };

  useEffect(() => {
    if (!lightbox) return;

    const onMove = (event: MouseEvent) => {
      const origin = dragOrigin.current;
      if (!origin) return;

      setPan({
        x: origin.panX + (event.clientX - origin.x),
        y: origin.panY + (event.clientY - origin.y),
      });
    };

    const onUp = () => {
      dragOrigin.current = null;
      setDragging(false);
    };

    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", onUp);

    return () => {
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", onUp);
    };
  }, [lightbox]);

  // ------------------------------------------------------- lightbox chrome

  const dialogRef = useRef<HTMLDivElement | null>(null);
  const closeRef = useRef<HTMLButtonElement | null>(null);
  const openerRef = useRef<HTMLButtonElement | null>(null);

  const openLightbox = () => {
    resetZoom();
    setLightbox(true);
  };

  const closeLightbox = useCallback(() => {
    setLightbox(false);
    resetZoom();
    // Focus goes back where it came from. Without this it lands on <body> and
    // the next Tab restarts from the top of the page.
    openerRef.current?.focus();
  }, [resetZoom]);

  /*
   * Keyboard, scroll lock and the focus trap — all only while open.
   *
   * Binding the arrows on the page would hijack ← and → from the rest of the
   * document, including horizontal scrolling and every text field on it.
   */
  useEffect(() => {
    if (!lightbox) return;

    const onKey = (event: KeyboardEvent) => {
      switch (event.key) {
        case "Escape":
          closeLightbox();
          return;
        case "ArrowLeft":
          go(-1);
          return;
        case "ArrowRight":
          go(1);
          return;
        case "+":
        case "=":
          zoomBy(1);
          return;
        case "-":
          zoomBy(-1);
          return;
        case "0":
          resetZoom();
          return;
        case "Tab": {
          /*
           * The trap. A modal that lets Tab wander onto the page behind it is
           * unusable with a screen reader: focus disappears into content that
           * is visually covered and announced as if it were reachable.
           */
          const focusable = dialogRef.current?.querySelectorAll<HTMLElement>(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
          );

          if (!focusable || focusable.length === 0) return;

          const first = focusable[0];
          const last = focusable[focusable.length - 1];

          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
      }
    };

    document.addEventListener("keydown", onKey);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    closeRef.current?.focus();

    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [lightbox, go, closeLightbox, zoomBy, resetZoom]);

  const current = images[index];

  // Built once per photo rather than per render: `imageSrcSet` walks and sorts
  // the variant map, and this sits under a mousemove handler.
  const currentSrcSet = useMemo(() => imageSrcSet(current), [current]);

  if (count === 0) {
    return (
      <div className="flex h-[460px] w-full items-center justify-center bg-white text-muted-foreground">
        This listing has no photos yet
      </div>
    );
  }

  const zoomed = lightboxZoom > 1;

  return (
    <>
      <div className="relative overflow-hidden">
        <div
          onTouchStart={onTouchStart}
          onTouchEnd={onTouchEnd}
          onMouseMove={onLensMove}
          onMouseLeave={() => setLens(null)}
          className={canHover ? "cursor-zoom-in" : undefined}
          /*
           * The click target for fullscreen is the whole image on desktop,
           * which is what people expect from a marketplace photo. It is a plain
           * div rather than a button because it CONTAINS buttons (the arrows),
           * and nesting interactive elements is invalid and breaks keyboard
           * order. The "Fullscreen" button below is the accessible route in.
           */
          onClick={canHover ? openLightbox : undefined}
        >
          <SafeImage
            src={imageSrc(current, "detail") ?? current?.url}
            srcSet={currentSrcSet}
            sizes={IMAGE_SIZES.gallery}
            alt={current?.alt_text ?? title}
            loading="eager"
            /* The gallery hero is the LCP element on every listing page. */
            fetchPriority="high"
            className="h-[460px] w-full object-cover"
            fallbackClassName="h-[460px] w-full"
          />
        </div>

        {/*
         * The lens overlay.
         *
         * A SECOND absolutely-positioned image rather than a transform on the
         * one above, so the base image is never scaled — if it were, the arrows
         * and badges layered over it would have to be excluded from the
         * transform, and the browser would rasterise a 2.2× layer on every
         * listing page whether or not anyone hovered.
         */}
        {canHover && lensArmed && (
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 overflow-hidden transition-opacity duration-150"
            style={{ opacity: lens ? 1 : 0 }}
          >
            <SafeImage
              src={imageSrc(current, "full") ?? current?.url}
              alt=""
              loading="eager"
              className="h-[460px] w-full object-cover will-change-transform"
              fallbackClassName="hidden"
              style={{
                transform: `scale(${HOVER_ZOOM})`,
                // No transition on the origin: it is driven by mousemove, and
                // easing it makes the magnified region trail the cursor.
                transformOrigin: lens ? `${lens.x}% ${lens.y}%` : "50% 50%",
              }}
            />
          </div>
        )}

        {hasMultiple && (
          <>
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                go(-1);
              }}
              aria-label="Previous photo"
              className="absolute left-4 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow transition hover:scale-110"
            >
              <ArrowLeft className="h-5 w-5 text-navy" />
            </button>
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                go(1);
              }}
              aria-label="Next photo"
              className="absolute right-4 top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow transition hover:scale-110"
            >
              <ArrowRight className="h-5 w-5 text-navy" />
            </button>

            <span className="pointer-events-none absolute bottom-4 left-4 z-10 rounded-full bg-navy/80 px-3 py-1 text-xs font-semibold text-white">
              {index + 1} / {count}
            </span>
          </>
        )}

        {/* Only shown where hovering is possible, and only while hovering —
            a permanent "hover to zoom" hint on a touch screen is a lie. */}
        {canHover && lens && (
          <span className="pointer-events-none absolute left-1/2 top-4 z-10 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-navy/75 px-3 py-1 text-[11px] font-semibold text-white">
            <ZoomIn className="h-3.5 w-3.5" />
            Click for fullscreen
          </span>
        )}

        <button
          ref={openerRef}
          type="button"
          onClick={(event) => {
            event.stopPropagation();
            openLightbox();
          }}
          aria-label="View photos fullscreen"
          className="absolute bottom-4 right-4 z-10 flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-xs font-semibold text-navy shadow transition hover:bg-white"
        >
          <Expand className="h-3.5 w-3.5" />
          Fullscreen
        </button>
      </div>

      {hasMultiple && (
        <div className="mt-4 grid grid-cols-4 gap-3">
          {images.slice(0, 8).map((image, thumbIndex) => (
            <button
              key={image.uuid ?? thumbIndex}
              type="button"
              onClick={() => select(thumbIndex)}
              aria-label={`Photo ${thumbIndex + 1}`}
              aria-current={index === thumbIndex}
              className={`overflow-hidden border-2 transition ${
                index === thumbIndex ? "border-teal" : "border-transparent hover:border-border"
              }`}
            >
              <SafeImage
                src={imageSrc(image, "thumb") ?? image.url}
                srcSet={imageSrcSet(image)}
                sizes={IMAGE_SIZES.thumbnail}
                alt=""
                className="h-20 w-full object-cover"
                fallbackClassName="h-20 w-full"
              />
            </button>
          ))}
        </div>
      )}

      {lightbox && (
        <div
          ref={dialogRef}
          className="fixed inset-0 z-[110] flex flex-col bg-navy/95 animate-fade-in-soft"
          role="dialog"
          aria-modal="true"
          aria-label={`${title} — photo ${index + 1} of ${count}`}
        >
          <div className="flex items-center justify-between gap-3 px-5 py-4 text-white">
            <span className="text-sm font-semibold">
              {index + 1} / {count}
            </span>

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => zoomBy(-1)}
                disabled={lightboxZoom === LIGHTBOX_ZOOM_STEPS[0]}
                aria-label="Zoom out"
                className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-30"
              >
                <Minus className="h-5 w-5" />
              </button>

              {/* aria-live: a screen-reader user pressing +/- gets no feedback
                  from a silently changing transform. */}
              <span
                className="min-w-[3.5rem] text-center text-sm font-semibold tabular-nums"
                aria-live="polite"
              >
                {Math.round(lightboxZoom * 100)}%
              </span>

              <button
                type="button"
                onClick={() => zoomBy(1)}
                disabled={lightboxZoom === LIGHTBOX_ZOOM_STEPS[LIGHTBOX_ZOOM_STEPS.length - 1]}
                aria-label="Zoom in"
                className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-30"
              >
                <Plus className="h-5 w-5" />
              </button>

              <button
                type="button"
                onClick={resetZoom}
                disabled={!zoomed}
                aria-label="Reset zoom"
                className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-30"
              >
                <RotateCcw className="h-4 w-4" />
              </button>

              <button
                ref={closeRef}
                type="button"
                onClick={closeLightbox}
                aria-label="Close"
                className="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
          </div>

          <div
            className="relative flex flex-1 items-center justify-center overflow-hidden px-4 pb-6"
            onTouchStart={onTouchStart}
            onTouchEnd={onTouchEnd}
            onMouseDown={onDragStart}
            onDoubleClick={() => (zoomed ? resetZoom() : zoomBy(1))}
          >
            <SafeImage
              src={imageSrc(current, "full") ?? current?.url}
              srcSet={currentSrcSet}
              sizes={IMAGE_SIZES.lightbox}
              alt={current?.alt_text ?? title}
              loading="eager"
              className={`max-h-full max-w-full object-contain will-change-transform ${
                zoomed ? (dragging ? "cursor-grabbing" : "cursor-grab") : "cursor-zoom-in"
              }`}
              fallbackClassName="h-64 w-64 rounded-xl"
              style={{
                transform: `translate(${pan.x}px, ${pan.y}px) scale(${lightboxZoom})`,
                // Animated only when settling to a new zoom level. Transitioning
                // during a drag makes the image lag the cursor by 200ms, which
                // reads as jank rather than smoothness.
                transition: dragging ? "none" : "transform 200ms ease-out",
              }}
            />

            {hasMultiple && (
              <>
                <button
                  type="button"
                  onClick={() => go(-1)}
                  aria-label="Previous photo"
                  className="absolute left-4 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/25"
                >
                  <ArrowLeft className="h-6 w-6" />
                </button>
                <button
                  type="button"
                  onClick={() => go(1)}
                  aria-label="Next photo"
                  className="absolute right-4 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/25"
                >
                  <ArrowRight className="h-6 w-6" />
                </button>
              </>
            )}
          </div>

          {hasMultiple && (
            <div className="flex gap-2 overflow-x-auto px-5 pb-5">
              {images.map((image, thumbIndex) => (
                <button
                  key={image.uuid ?? thumbIndex}
                  type="button"
                  onClick={() => select(thumbIndex)}
                  aria-label={`Photo ${thumbIndex + 1}`}
                  className={`h-16 w-24 shrink-0 overflow-hidden rounded border-2 transition ${
                    index === thumbIndex ? "border-teal" : "border-transparent opacity-60 hover:opacity-100"
                  }`}
                >
                  <SafeImage
                    src={imageSrc(image, "thumb") ?? image.url}
                    srcSet={imageSrcSet(image)}
                    sizes={IMAGE_SIZES.thumbnail}
                    alt=""
                    className="h-full w-full object-cover"
                    fallbackClassName="h-full w-full"
                  />
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </>
  );
}
