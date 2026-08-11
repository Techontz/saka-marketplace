"use client";

import { useCallback, useMemo, useState } from "react";
import { Crosshair, Scan, Trash2, Undo2 } from "lucide-react";

import { MapView } from "@/components/map/MapView";
import { MAP_LAYERS, type MapLayerId } from "@/lib/config";
import {
  formatArea,
  formatDistanceMetres,
  latToY,
  lngToX,
  ringArea,
  ringPerimeter,
  type Position,
} from "@/lib/geo";

/**
 * Draw a land parcel.
 *
 * A controlled component: `value` is the outer ring as GeoJSON positions —
 * [longitude, latitude] — and `onChange` fires with the next ring plus every
 * derived figure a caller might want, so nothing downstream has to re-implement
 * the geometry.
 *
 * WHY IT WRAPS MapView RATHER THAN REBUILDING ONE
 * -----------------------------------------------
 * MapView already carries the projection, the pointer handling, pinch zoom,
 * the tile layers and the vertex handles. A second map component would be a
 * second place for the Web Mercator maths to be subtly wrong, and the failure
 * mode there is not a crash — it is a boundary drawn in the wrong place that
 * looks perfectly plausible. Everything here is composition: state, the
 * derived measurements, and the controls that only make sense while drawing.
 *
 * ON THE NUMBERS
 * --------------
 * Area and perimeter are computed with the same spherical-excess formula and
 * the same Earth radius the API uses, so the figure that ticks up while a
 * corner is dragged is the figure the server will store. They are shown for
 * feedback only — the server recomputes from the coordinates on save and its
 * answer is authoritative, because a seller must not be able to type an
 * acreage that the shape does not support.
 */

/** Everything derived from the ring, handed to the caller on every change. */
export type BoundaryMeta = {
  /** The outer ring, open — no repeated closing point. */
  points: Position[];
  /** GeoJSON rings: closed, outer ring first. Empty until three corners exist. */
  rings: Position[][];
  /** A ready-to-post GeoJSON Feature, or null while the shape is incomplete. */
  geojson: GeoJsonPolygonFeature | null;
  areaSqm: number;
  perimeterM: number;
  /** True once the ring encloses an area rather than being a line. */
  isClosed: boolean;
};

export type GeoJsonPolygonFeature = {
  type: "Feature";
  geometry: { type: "Polygon"; coordinates: Position[][] };
  properties: { area_sqm: number; perimeter_m: number; vertex_count: number };
};

/** Fewer corners than this is a line, not a parcel. */
const MIN_VERTICES = 3;

const MIN_ZOOM = 3;
const MAX_ZOOM = 19;

export function BoundaryMap({
  value,
  onChange,
  readonly = false,
  center,
  height = 460,
  layer = "satellite",
  className,
}: {
  /** The outer ring. Accepts a closed ring and normalises it to an open one. */
  value: Position[];
  onChange?: (points: Position[], meta: BoundaryMeta) => void;
  /** View-only: corners render, but cannot be added, dragged or removed. */
  readonly?: boolean;
  /** Where to open when there is nothing drawn yet — usually the listing pin. */
  center?: { lat: number; lng: number } | null;
  height?: number;
  /**
   * Satellite by default. A boundary is traced against what is on the ground —
   * a fence, a hedge, a track — and on a road map the seller is drawing over
   * white space and guessing.
   */
  layer?: MapLayerId;
  className?: string;
}) {
  /*
   * A stored ring repeats its first point to close itself; a ring being drawn
   * does not. Normalising on the way IN means the caller can hand back exactly
   * what the API gave it without the closing point turning into a draggable
   * corner sitting on top of corner one.
   */
  const points = useMemo(() => openRing(value), [value]);

  /*
   * "Fit bounds" and the initial framing are done by REMOUNTING the map with a
   * new centre and zoom. MapView owns its own viewport once mounted — it has
   * to, or a pan would fight the parent on every frame — so a key change is
   * the honest way to reposition it from outside without reaching into it.
   */
  const [frameKey, setFrameKey] = useState(0);

  const metrics = useMemo(
    () => ({
      areaSqm: points.length >= MIN_VERTICES ? ringArea(points) : 0,
      perimeterM: points.length >= 2 ? ringPerimeter(points) : 0,
    }),
    [points],
  );

  const isClosed = points.length >= MIN_VERTICES;

  const emit = useCallback(
    (next: Position[]) => {
      if (!onChange) return;

      const open = openRing(next);
      const closed = open.length >= MIN_VERTICES ? [[...open, open[0]] as Position[]] : [];
      const areaSqm = open.length >= MIN_VERTICES ? ringArea(open) : 0;
      const perimeterM = open.length >= 2 ? ringPerimeter(open) : 0;

      onChange(open, {
        points: open,
        rings: closed,
        geojson:
          closed.length > 0
            ? {
                type: "Feature",
                geometry: { type: "Polygon", coordinates: closed },
                properties: {
                  area_sqm: Math.round(areaSqm * 100) / 100,
                  perimeter_m: Math.round(perimeterM * 100) / 100,
                  vertex_count: open.length,
                },
              }
            : null,
        areaSqm,
        perimeterM,
        isClosed: open.length >= MIN_VERTICES,
      });
    },
    [onChange],
  );

  /*
   * The frame that fits the drawn shape, or the fallback centre if there is
   * none. A plain call rather than a useMemo: it is arithmetic over a handful
   * of corners, and the React Compiler memoizes it anyway — a manual useMemo
   * here only made it refuse to optimise the component.
   */
  const view = frameFor(points, center ?? null, height, layer);

  const readout = isClosed
    ? `${formatArea(metrics.areaSqm)} · ${formatDistanceMetres(metrics.perimeterM)} perimeter · ${points.length} corners`
    : readonly
      ? "No boundary drawn"
      : `Tap each corner of the plot — ${MIN_VERTICES - points.length} more to close it`;

  return (
    <div className={className}>
      <MapView
        // The key is the fit mechanism; see the comment on `frameKey`.
        key={`${frameKey}-${points.length === 0 ? "empty" : "drawn"}`}
        pins={[]}
        polygons={
          // In readonly mode the ring is a finished shape, so it goes through
          // the polygon layer and gets MapView's own framing and labelling.
          readonly && isClosed
            ? [
                {
                  id: "boundary",
                  rings: [[...points, points[0]] as Position[]],
                  tone: "parcel",
                  label: formatArea(metrics.areaSqm),
                },
              ]
            : []
        }
        draw={!readonly}
        drawing={readonly ? undefined : points}
        onDrawingChange={readonly ? undefined : emit}
        center={view.center}
        zoom={view.zoom}
        height={height}
        layer={layer}
        allowLayers
        allowRotate
        allowMeasure={readonly}
        allowFullscreen
      />

      <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          <span className={isClosed ? "font-semibold text-navy" : undefined}>{readout}</span>
        </p>

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => setFrameKey((current) => current + 1)}
            disabled={points.length === 0}
            className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-navy transition hover:border-teal hover:text-teal disabled:opacity-40"
          >
            <Scan className="h-3.5 w-3.5" />
            Fit to plot
          </button>

          {!readonly && (
            <>
              <button
                type="button"
                onClick={() => emit(points.slice(0, -1))}
                disabled={points.length === 0}
                className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-navy transition hover:border-teal hover:text-teal disabled:opacity-40"
              >
                <Undo2 className="h-3.5 w-3.5" />
                Undo corner
              </button>

              <button
                type="button"
                onClick={() => emit([])}
                disabled={points.length === 0}
                className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-navy transition hover:border-orange hover:text-orange disabled:opacity-40"
              >
                <Trash2 className="h-3.5 w-3.5" />
                Clear
              </button>
            </>
          )}
        </div>
      </div>

      {!readonly && (
        <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
          <Crosshair className="mt-0.5 h-3.5 w-3.5 shrink-0 text-teal" />
          Tap the map to add a corner, drag a corner to move it, and tap a corner to remove it. The
          outline closes itself once there are three.
        </p>
      )}
    </div>
  );
}

/** The tightest centre and zoom at which every corner is on screen. */
function frameFor(
  points: Position[],
  fallback: { lat: number; lng: number } | null,
  height: number,
  layer: MapLayerId,
): { center: { lat: number; lng: number } | null; zoom: number } {
  if (points.length === 0) {
    return { center: fallback, zoom: fallback ? 17 : 12 };
  }

  let minLat = points[0][1];
  let maxLat = points[0][1];
  let minLng = points[0][0];
  let maxLng = points[0][0];

  for (const [lng, lat] of points) {
    minLat = Math.min(minLat, lat);
    maxLat = Math.max(maxLat, lat);
    minLng = Math.min(minLng, lng);
    maxLng = Math.max(maxLng, lng);
  }

  const middle = { lat: (minLat + maxLat) / 2, lng: (minLng + maxLng) / 2 };

  // A single corner has no extent to fit; drop to a zoom where a plot fills a
  // useful part of the screen.
  if (points.length === 1) return { center: middle, zoom: 18 };

  const cap = Math.min(MAX_ZOOM, MAP_LAYERS[layer]?.maxZoom ?? MAX_ZOOM);

  for (let zoom = cap; zoom >= MIN_ZOOM; zoom--) {
    const spanX = Math.abs(lngToX(maxLng, zoom) - lngToX(minLng, zoom));
    const spanY = Math.abs(latToY(minLat, zoom) - latToY(maxLat, zoom));

    // 800 is the assumed width before the map has measured itself; the padding
    // keeps corner handles clear of the edge either way.
    if (spanX <= 800 - 120 && spanY <= height - 120) return { center: middle, zoom };
  }

  return { center: middle, zoom: MIN_ZOOM };
}

/**
 * Drop a repeated closing point, and any consecutive duplicate.
 *
 * A double-tap on a touch screen reliably produces two corners in the same
 * place; left in, they contribute nothing to the shape and make the corner
 * count wrong. The server normalises the same way on save, so what is drawn
 * and what is stored agree.
 */
function openRing(ring: Position[]): Position[] {
  const out: Position[] = [];

  for (const point of ring) {
    const previous = out[out.length - 1];

    if (previous && samePoint(previous, point)) continue;

    out.push([point[0], point[1]]);
  }

  if (out.length > 1 && samePoint(out[0], out[out.length - 1])) out.pop();

  return out;
}

function samePoint(a: Position, b: Position): boolean {
  return Math.abs(a[0] - b[0]) < 1e-9 && Math.abs(a[1] - b[1]) < 1e-9;
}
