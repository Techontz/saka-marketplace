"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Compass,
  Crosshair,
  Loader2,
  Maximize2,
  Minimize2,
  Minus,
  Plus,
  RefreshCw,
  Ruler,
  Trash2,
  Undo2,
  X,
} from "lucide-react";

import { DEFAULT_CENTER, MAP_LAYERS, type MapLayerId } from "@/lib/map";
import {
  TILE_SIZE,
  formatArea,
  formatDistanceMetres,
  latToY,
  lngToX,
  pathLength,
  ringArea,
  ringPerimeter,
  xToLng,
  yToLat,
  type LatLng,
  type Position,
} from "@/lib/geo";
import { useGeolocation, type Coords } from "@/lib/useGeolocation";

/**
 * The interactive map — the vendor portal's copy.
 *
 * A near-verbatim copy of the marketplace's `components/map/MapView.tsx`. The
 * two apps are separate Next projects with no shared package, and the map is
 * the one piece of geometry that MUST behave identically in both: the seller
 * draws a parcel here and the buyer reads its area there, so a projection that
 * differed between them would advertise a plot at the wrong size.
 *
 * Three things are deliberately removed rather than ported: the marker popup's
 * link into the marketplace (no such route here), its image (no SafeImage in
 * this app), and the Directions link (a vendor editing their own listing is
 * standing at the plot, not navigating to it). Everything that touches the
 * projection is unchanged.
 *
 * ---
 * The interactive map.
 *
 * Built directly on tiles rather than on Leaflet or the Google Maps SDK, for
 * two reasons that matter for this product:
 *
 *   1. NO API KEY. Google Maps needs a billable key; without one the map is a
 *      grey box with a "for development purposes only" watermark. OpenStreetMap
 *      and Esri imagery tiles need nothing, so maps work for every deployment.
 *   2. NO 150KB of map library for what is, at heart, a tile grid, a set of
 *      absolutely-positioned pins and a pointer handler.
 *
 * Zoom is a FLOAT. Tiles render at the nearest integer zoom inside a wrapper
 * scaled by the fractional remainder, which is what makes a wheel or a pinch
 * feel continuous instead of jumping a whole power of two. Markers and polygons
 * are projected at the fractional zoom OUTSIDE that wrapper, so they stay the
 * right size and the right way up however far between tile levels the map sits.
 *
 * Bearing works the same way: the tile wrapper is rotated by CSS, and the
 * projection rotates every marker and vertex to match, so labels stay upright
 * over a rotated map instead of turning upside down with it.
 *
 * Pointer events rather than separate mouse/touch handlers: one code path pans
 * with a mouse, a finger or a pen, and two simultaneous pointers give pinch
 * zoom for free.
 */

const MIN_ZOOM = 3;
const MAX_ZOOM = 19;

const OSM_TEMPLATE = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";

/** Past this, a pointer sequence is a drag and must not fire a click. */
const DRAG_THRESHOLD_PX = 5;

const clampZoom = (zoom: number, max = MAX_ZOOM) =>
  Math.max(MIN_ZOOM, Math.min(max, zoom));

/**
 * A tile template that cannot produce a src-less <img>.
 *
 * A misconfigured layer URL — blank, or missing a placeholder — blanks the map
 * while leaving its border, controls and markers in place, which reads as "the
 * map is broken" rather than "the map is misconfigured". Falling back is always
 * better than rendering nothing.
 */
function safeTemplate(url: string): string {
  return ["{z}", "{x}", "{y}"].every((token) => url.includes(token)) ? url : OSM_TEMPLATE;
}

export type MapPin = {
  id: string;
  lat: number;
  lng: number;
  label: string;
  /** The short text shown inside the marker itself, e.g. a price. */
  sublabel?: string;
  /** Secondary line in the popup, e.g. an address. */
  meta?: string;
  image?: string | null;
  href?: string;
  tone?: "listing" | "business" | "place";
};

/**
 * A land parcel, or any other shaded shape.
 *
 * Rings are GeoJSON order — [longitude, latitude] — and ring 0 is the outer
 * edge; any further rings are holes. That matches what the API stores and
 * returns, so nothing has to be transformed on the way in or out.
 */
export type MapPolygon = {
  id: string;
  rings: Position[][];
  label?: string;
  tone?: "parcel" | "highlight";
};

export type MapViewport = {
  center: Coords;
  /** Half the viewport diagonal — the radius that covers everything visible. */
  radiusKm: number;
  zoom: number;
};

type Cluster = { key: string; x: number; y: number; pins: MapPin[] };

const TONE_CLASSES: Record<NonNullable<MapPin["tone"]>, string> = {
  listing: "bg-brand text-white",
  business: "bg-ink text-white",
  place: "bg-warn text-white",
};

const POLYGON_TONES: Record<NonNullable<MapPolygon["tone"]>, { fill: string; stroke: string }> = {
  parcel: { fill: "rgba(20,184,166,0.22)", stroke: "#0d9488" },
  highlight: { fill: "rgba(249,115,22,0.20)", stroke: "#ea580c" },
};

/** The tightest integer zoom at which every point fits, with padding. */
function zoomForBounds(
  points: LatLng[],
  width: number,
  height: number,
  padding = 64,
): { center: Coords; zoom: number } | null {
  if (points.length === 0) return null;

  let minLat = points[0].lat;
  let maxLat = points[0].lat;
  let minLng = points[0].lng;
  let maxLng = points[0].lng;

  for (const point of points) {
    minLat = Math.min(minLat, point.lat);
    maxLat = Math.max(maxLat, point.lat);
    minLng = Math.min(minLng, point.lng);
    maxLng = Math.max(maxLng, point.lng);
  }

  const center = { lat: (minLat + maxLat) / 2, lng: (minLng + maxLng) / 2 };

  // A single point has no extent to fit; drop to a sensible street-level zoom.
  if (points.length === 1 || (minLat === maxLat && minLng === maxLng)) {
    return { center, zoom: 15 };
  }

  const usableWidth = Math.max(64, width - padding * 2);
  const usableHeight = Math.max(64, height - padding * 2);

  for (let zoom = MAX_ZOOM; zoom >= MIN_ZOOM; zoom--) {
    const spanX = Math.abs(lngToX(maxLng, zoom) - lngToX(minLng, zoom));
    const spanY = Math.abs(latToY(minLat, zoom) - latToY(maxLat, zoom));

    if (spanX <= usableWidth && spanY <= usableHeight) return { center, zoom };
  }

  return { center, zoom: MIN_ZOOM };
}

export function MapView({
  pins,
  polygons = [],
  center,
  zoom: initialZoom = 12,
  height = 520,
  loading = false,
  fitToPins = false,
  autoSearch = false,
  onAreaSearch,
  onPinClick,
  activePinId,
  interactive = true,
  showPopups = true,
  layer: initialLayer = "street",
  allowLayers = true,
  allowMeasure = true,
  allowFullscreen = true,
  allowRotate = false,
  draw = false,
  drawing,
  onDrawingChange,
}: {
  pins: MapPin[];
  /** Shaded shapes drawn under the markers — land parcels, mostly. */
  polygons?: MapPolygon[];
  center?: Coords | null;
  zoom?: number;
  height?: number;
  loading?: boolean;
  /** Re-frame the map whenever the pin set changes, so results are never off-screen. */
  fitToPins?: boolean;
  /**
   * When true the viewport itself is the query: panning or zooming re-searches
   * once the gesture settles. When false the customer gets a "Search this area"
   * button and nothing happens until they press it.
   */
  autoSearch?: boolean;
  onAreaSearch?: (viewport: MapViewport) => void;
  onPinClick?: (pin: MapPin) => void;
  activePinId?: string | null;
  /** Small embedded maps on detail pages are display-only. */
  interactive?: boolean;
  showPopups?: boolean;
  layer?: MapLayerId;
  allowLayers?: boolean;
  allowMeasure?: boolean;
  allowFullscreen?: boolean;
  /** Off by default: rotation only earns its keep on parcel and detail maps. */
  allowRotate?: boolean;
  /** Turns the map into a polygon editor. Controlled by `drawing`. */
  draw?: boolean;
  drawing?: Position[];
  onDrawingChange?: (points: Position[]) => void;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const [size, setSize] = useState({ width: 800, height });
  const [zoom, setZoom] = useState(initialZoom);
  const [bearing, setBearing] = useState(0);
  const [centre, setCentre] = useState<Coords>(center ?? DEFAULT_CENTER);
  const [moved, setMoved] = useState(false);
  const [interacting, setInteracting] = useState(false);
  const [openPinId, setOpenPinId] = useState<string | null>(null);
  const [syncedCenter, setSyncedCenter] = useState<Coords | null>(center ?? null);
  const [layerId, setLayerId] = useState<MapLayerId>(initialLayer);
  const [fullscreen, setFullscreen] = useState(false);
  const [measuring, setMeasuring] = useState(false);
  const [measurePoints, setMeasurePoints] = useState<LatLng[]>([]);
  const geo = useGeolocation();

  const layer = MAP_LAYERS[layerId] ?? MAP_LAYERS.street;
  const maxZoom = layer.maxZoom;

  // Follow the prop when it changes — a new search result set re-centres the
  // map — but never fight the customer's own panning, which only changes
  // `centre`. Adjusted during render, not in an effect, so the map never
  // paints at the old position first.
  if (center && (center.lat !== syncedCenter?.lat || center.lng !== syncedCenter?.lng)) {
    setSyncedCenter(center);
    setCentre(center);
  }

  // Switching to a layer that stops at a lower zoom must pull the map back to
  // where that layer actually has tiles, or it goes blank on the switch.
  if (zoom > maxZoom) setZoom(maxZoom);

  useEffect(() => {
    const element = containerRef.current;
    if (!element) return;

    const observer = new ResizeObserver((entries) => {
      const rect = entries[0].contentRect;
      setSize({ width: rect.width, height: rect.height });
    });

    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  // Escape leaves fullscreen and cancels a measurement — the two states a
  // customer is most likely to want out of without hunting for a button.
  useEffect(() => {
    if (!fullscreen && !measuring) return;

    const onKey = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;

      if (measuring) {
        setMeasuring(false);
        setMeasurePoints([]);
      } else {
        setFullscreen(false);
      }
    };

    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [fullscreen, measuring]);

  // Fullscreen is a fixed overlay rather than the Fullscreen API: iOS Safari
  // refuses that API on anything but a <video>, and a control that silently
  // does nothing on a third of phones is worse than none.
  useEffect(() => {
    if (!fullscreen) return;

    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.body.style.overflow = previous;
    };
  }, [fullscreen]);

  // ---------------------------------------------------------- projection

  const centreX = lngToX(centre.lng, zoom);
  const centreY = latToY(centre.lat, zoom);

  const radians = (bearing * Math.PI) / 180;
  const cosB = Math.cos(radians);
  const sinB = Math.sin(radians);

  /** World offset → screen offset. */
  const rotate = useCallback(
    (dx: number, dy: number) => ({ x: dx * cosB - dy * sinB, y: dx * sinB + dy * cosB }),
    [cosB, sinB],
  );

  /** Screen offset → world offset. */
  const unrotate = useCallback(
    (dx: number, dy: number) => ({ x: dx * cosB + dy * sinB, y: -dx * sinB + dy * cosB }),
    [cosB, sinB],
  );

  const project = useCallback(
    (lat: number, lng: number) => {
      const offset = rotate(lngToX(lng, zoom) - centreX, latToY(lat, zoom) - centreY);
      return { x: offset.x + size.width / 2, y: offset.y + size.height / 2 };
    },
    [zoom, centreX, centreY, size.width, size.height, rotate],
  );

  const unproject = useCallback(
    (px: number, py: number): Coords => {
      const offset = unrotate(px - size.width / 2, py - size.height / 2);
      return {
        lat: yToLat(centreY + offset.y, zoom),
        lng: xToLng(centreX + offset.x, zoom),
      };
    },
    [zoom, centreX, centreY, size.width, size.height, unrotate],
  );

  /** Half the diagonal of what is on screen, in kilometres. */
  const viewport = useCallback((): MapViewport => {
    const west = xToLng(centreX - size.width / 2, zoom);
    const east = xToLng(centreX + size.width / 2, zoom);
    const north = yToLat(centreY - size.height / 2, zoom);
    const south = yToLat(centreY + size.height / 2, zoom);

    const latSpanKm = (north - south) * 111.045;
    const lngSpanKm = (east - west) * 111.045 * Math.cos((centre.lat * Math.PI) / 180);

    /*
     * The API filters by a CIRCLE (lat/lng/radius) and the viewport is a
     * rectangle, so the radius is half the diagonal — the circle that
     * circumscribes what is visible. That returns a little more than is on
     * screen, which is the right way round: a corner result being included is
     * better than one being silently dropped.
     */
    return {
      center: centre,
      radiusKm: Math.max(0.5, Math.min(500, Math.hypot(latSpanKm, lngSpanKm) / 2)),
      zoom,
    };
  }, [centreX, centreY, zoom, size.width, size.height, centre]);

  /*
   * Auto-search fires only after the gesture stops. Searching on every frame
   * of a drag would put a request on the wire per pointermove and make the
   * result list strobe under the customer's finger.
   */
  const settleTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const viewportRef = useRef(viewport);

  // Kept current in an effect, never during render: reading or writing a ref
  // while rendering is not safe under concurrent React.
  useEffect(() => {
    viewportRef.current = viewport;
  }, [viewport]);

  const scheduleAreaSearch = useCallback(() => {
    if (!autoSearch || !onAreaSearch) return;

    if (settleTimer.current) clearTimeout(settleTimer.current);
    settleTimer.current = setTimeout(() => {
      onAreaSearch(viewportRef.current());
      setMoved(false);
    }, 600);
  }, [autoSearch, onAreaSearch]);

  useEffect(
    () => () => {
      if (settleTimer.current) clearTimeout(settleTimer.current);
    },
    [],
  );

  /** Zoom while keeping the geography under (px, py) pinned to that point. */
  const zoomAround = useCallback(
    (delta: number, px?: number, py?: number) => {
      const anchorX = px ?? size.width / 2;
      const anchorY = py ?? size.height / 2;

      const next = clampZoom(zoom + delta, maxZoom);
      if (next === zoom) return;

      // The geography under the anchor before the zoom…
      const anchor = unproject(anchorX, anchorY);

      // …and the centre that puts it back under the anchor after it.
      const offset = unrotate(anchorX - size.width / 2, anchorY - size.height / 2);

      setZoom(next);
      setCentre({
        lat: yToLat(latToY(anchor.lat, next) - offset.y, next),
        lng: xToLng(lngToX(anchor.lng, next) - offset.x, next),
      });

      setMoved(true);
      scheduleAreaSearch();
    },
    [zoom, maxZoom, size.width, size.height, unproject, unrotate, scheduleAreaSearch],
  );

  /*
   * Re-frame when the result set changes.
   *
   * Keyed on the pin ids rather than the array identity: the parent rebuilds
   * that array on every render, and refitting on each one would yank the map
   * out from under someone mid-pan.
   */
  const pinKey = pins.map((pin) => pin.id).join("|");
  const [fittedKey, setFittedKey] = useState<string | null>(null);

  /*
   * Adjusted DURING RENDER, like the `center` sync above, rather than in an
   * effect. React re-renders immediately without painting the intermediate
   * frame, so the map never shows the old framing for a tick — and it keeps
   * this out of the effect-then-setState pattern that causes cascading renders.
   */
  if (fitToPins && pins.length > 0 && size.width > 0 && fittedKey !== pinKey) {
    setFittedKey(pinKey);

    const fit = zoomForBounds(pins, size.width, size.height);

    if (fit) {
      setCentre(fit.center);
      setZoom(clampZoom(fit.zoom, maxZoom));
      setMoved(false);
    }
  }

  // ------------------------------------------------------------ pointers

  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const gesture = useRef<{
    startCentre: Coords;
    startX: number;
    startY: number;
    startDistance: number;
    startZoom: number;
    dragged: boolean;
  } | null>(null);

  /** Index of the vertex currently being dragged, in draw mode. */
  const draggingVertex = useRef<number | null>(null);

  const pointerMid = () => {
    const list = [...pointers.current.values()];
    const x = list.reduce((sum, point) => sum + point.x, 0) / list.length;
    const y = list.reduce((sum, point) => sum + point.y, 0) / list.length;
    return { x, y };
  };

  const pointerSpread = () => {
    const list = [...pointers.current.values()];
    if (list.length < 2) return 0;
    return Math.hypot(list[0].x - list[1].x, list[0].y - list[1].y);
  };

  const beginGesture = () => {
    const mid = pointerMid();
    gesture.current = {
      startCentre: centre,
      startX: mid.x,
      startY: mid.y,
      startDistance: pointerSpread(),
      startZoom: zoom,
      dragged: false,
    };
  };

  const onPointerDown = (event: React.PointerEvent) => {
    if (!interactive) return;

    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    beginGesture();
    setInteracting(true);
  };

  const onPointerMove = (event: React.PointerEvent) => {
    if (!interactive) return;

    // Dragging a corner moves that corner, not the map.
    if (draggingVertex.current !== null && drawing && onDrawingChange) {
      const rect = containerRef.current?.getBoundingClientRect();
      if (!rect) return;

      const position = unproject(event.clientX - rect.left, event.clientY - rect.top);
      const next = [...drawing];
      next[draggingVertex.current] = [
        Number(position.lng.toFixed(7)),
        Number(position.lat.toFixed(7)),
      ];

      onDrawingChange(next);
      return;
    }

    if (!pointers.current.has(event.pointerId)) return;

    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });

    const state = gesture.current;
    if (!state) return;

    const rect = containerRef.current?.getBoundingClientRect();
    const mid = pointerMid();
    const dx = mid.x - state.startX;
    const dy = mid.y - state.startY;

    if (Math.hypot(dx, dy) > DRAG_THRESHOLD_PX) state.dragged = true;

    // Two pointers: pinch. The zoom delta is log2 of how far apart they moved.
    if (pointers.current.size >= 2 && state.startDistance > 0 && rect) {
      const ratio = pointerSpread() / state.startDistance;
      const nextZoom = clampZoom(state.startZoom + Math.log2(ratio), maxZoom);

      state.dragged = true;

      const anchorX = mid.x - rect.left;
      const anchorY = mid.y - rect.top;

      // Where the anchor's geography was when the pinch began — computed in
      // the gesture's own frame so the shape under the fingers stays put.
      const startOffset = unrotate(anchorX - dx - size.width / 2, anchorY - dy - size.height / 2);
      const anchorLat = yToLat(latToY(state.startCentre.lat, state.startZoom) + startOffset.y, state.startZoom);
      const anchorLng = xToLng(lngToX(state.startCentre.lng, state.startZoom) + startOffset.x, state.startZoom);

      const endOffset = unrotate(anchorX - size.width / 2, anchorY - size.height / 2);

      setZoom(nextZoom);
      setCentre({
        lat: yToLat(latToY(anchorLat, nextZoom) - endOffset.y, nextZoom),
        lng: xToLng(lngToX(anchorLng, nextZoom) - endOffset.x, nextZoom),
      });
      setMoved(true);
      return;
    }

    // Pan: a screen-space drag becomes a world-space one through the inverse
    // rotation, so a rotated map still follows the finger.
    const world = unrotate(dx, dy);

    setCentre({
      lat: yToLat(latToY(state.startCentre.lat, zoom) - world.y, zoom),
      lng: xToLng(lngToX(state.startCentre.lng, zoom) - world.x, zoom),
    });
    setMoved(true);
  };

  const endPointer = (event: React.PointerEvent) => {
    if (draggingVertex.current !== null) {
      draggingVertex.current = null;
      pointers.current.delete(event.pointerId);
      setInteracting(false);
      return;
    }

    if (!pointers.current.delete(event.pointerId)) return;

    if (pointers.current.size === 0) {
      const dragged = gesture.current?.dragged ?? false;
      gesture.current = null;
      setInteracting(false);
      if (dragged) scheduleAreaSearch();
    } else {
      // A finger lifted mid-pinch — re-baseline so the remaining one pans
      // cleanly instead of snapping.
      beginGesture();
    }
  };

  /*
   * Wheel zoom needs a NON-PASSIVE listener.
   *
   * React attaches wheel handlers passively, so preventDefault() from onWheel
   * is ignored and the page scrolls behind the map. Registering it by hand is
   * the only way to stop that.
   */
  useEffect(() => {
    const element = containerRef.current;
    if (!element || !interactive) return;

    const onWheel = (event: WheelEvent) => {
      event.preventDefault();

      const rect = element.getBoundingClientRect();
      const delta = -event.deltaY * (event.deltaMode === 1 ? 0.05 : 0.002);

      zoomAround(delta, event.clientX - rect.left, event.clientY - rect.top);
    };

    element.addEventListener("wheel", onWheel, { passive: false });
    return () => element.removeEventListener("wheel", onWheel);
  }, [interactive, zoomAround]);

  // -------------------------------------------------------------- tiles

  /*
   * Tiles render at the nearest INTEGER zoom and the wrapper is scaled by the
   * fractional remainder and rotated by the bearing. Markers are projected at
   * the true fractional zoom outside this wrapper, so they never scale or
   * rotate with it.
   */
  const tileZoom = Math.round(zoom);
  const tileScale = 2 ** (zoom - tileZoom);
  const template = safeTemplate(layer.url);

  const tiles = useMemo(() => {
    const scale = 2 ** tileZoom;
    const tileCentreX = lngToX(centre.lng, tileZoom);
    const tileCentreY = latToY(centre.lat, tileZoom);

    /*
     * The wrapper is scaled AND rotated, so it must cover more than the
     * viewport rectangle: at 45° the corners of the screen sit √2 further out
     * than the edges do. Under-covering shows as blank wedges in the corners
     * while turning, so the half-extents are grown by the rotated bounding box.
     */
    const spread = Math.abs(cosB) + Math.abs(sinB);
    const halfWidth = ((size.width / 2) * spread) / tileScale;
    const halfHeight = ((size.height / 2) * spread) / tileScale;

    const left = tileCentreX - halfWidth;
    const top = tileCentreY - halfHeight;

    const firstCol = Math.floor(left / TILE_SIZE);
    const lastCol = Math.floor((left + halfWidth * 2) / TILE_SIZE);
    const firstRow = Math.floor(top / TILE_SIZE);
    const lastRow = Math.floor((top + halfHeight * 2) / TILE_SIZE);

    const out: { key: string; url: string; left: number; top: number }[] = [];

    for (let col = firstCol; col <= lastCol; col++) {
      for (let row = firstRow; row <= lastRow; row++) {
        // Wrap horizontally, clamp vertically — there is no tile above the
        // north pole, and requesting one returns a 404 image.
        if (row < 0 || row >= scale) continue;

        const wrappedCol = ((col % scale) + scale) % scale;

        out.push({
          key: `${layerId}/${tileZoom}/${col}/${row}`,
          url: template
            .replace("{z}", String(tileZoom))
            .replace("{x}", String(wrappedCol))
            .replace("{y}", String(row)),
          left: col * TILE_SIZE - left - halfWidth + size.width / 2,
          top: row * TILE_SIZE - top - halfHeight + size.height / 2,
        });
      }
    }

    return out;
  }, [tileZoom, tileScale, centre.lat, centre.lng, size.width, size.height, template, layerId, cosB, sinB]);

  // ------------------------------------------------------------ clusters

  /**
   * Pins within ~46px of each other become one cluster.
   *
   * A grid rather than a distance-based algorithm: it is O(n) and stable while
   * panning, which matters because the alternative flickers as markers regroup
   * on every frame. Clustering is skipped once zoomed right in, where the
   * customer has explicitly asked to see individual results.
   */
  const clusters = useMemo<Cluster[]>(() => {
    const positioned = pins.map((pin) => ({ pin, ...project(pin.lat, pin.lng) }));

    if (zoom >= MAX_ZOOM - 3) {
      return positioned.map(({ pin, x, y }) => ({ key: pin.id, x, y, pins: [pin] }));
    }

    const CELL = 46;
    const buckets = new Map<string, Cluster>();

    for (const { pin, x, y } of positioned) {
      const key = `${Math.floor(x / CELL)}:${Math.floor(y / CELL)}`;
      const existing = buckets.get(key);

      if (existing) {
        existing.pins.push(pin);
        existing.x = (existing.x * (existing.pins.length - 1) + x) / existing.pins.length;
        existing.y = (existing.y * (existing.pins.length - 1) + y) / existing.pins.length;
      } else {
        buckets.set(key, { key, x, y, pins: [pin] });
      }
    }

    return [...buckets.values()];
  }, [pins, project, zoom]);

  const openPin = useMemo(
    () => (openPinId ? (pins.find((pin) => pin.id === openPinId) ?? null) : null),
    [openPinId, pins],
  );

  const openPinPosition = openPin ? project(openPin.lat, openPin.lng) : null;

  // ------------------------------------------------------------- shapes

  /** Every polygon, plus the one being drawn, as SVG path data. */
  const shapes = useMemo(() => {
    const toPath = (ring: Position[], close: boolean) =>
      ring
        .map(([lng, lat], index) => {
          const { x, y } = project(lat, lng);
          return `${index === 0 ? "M" : "L"}${x.toFixed(1)} ${y.toFixed(1)}`;
        })
        .join(" ") + (close && ring.length > 2 ? " Z" : "");

    return polygons.map((polygon) => ({
      id: polygon.id,
      label: polygon.label,
      tone: POLYGON_TONES[polygon.tone ?? "parcel"],
      // Holes are appended to the same path; `fill-rule: evenodd` punches them
      // out, which is one path element instead of a stack of masks.
      d: polygon.rings.map((ring) => toPath(ring, true)).join(" "),
      anchor: polygon.rings[0]?.length
        ? project(
            polygon.rings[0].reduce((sum, point) => sum + point[1], 0) / polygon.rings[0].length,
            polygon.rings[0].reduce((sum, point) => sum + point[0], 0) / polygon.rings[0].length,
          )
        : null,
    }));
  }, [polygons, project]);

  const drawPath = useMemo(() => {
    if (!draw || !drawing || drawing.length === 0) return null;

    const points = drawing.map(([lng, lat]) => project(lat, lng));
    const d =
      points.map((point, index) => `${index === 0 ? "M" : "L"}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(" ") +
      (points.length > 2 ? " Z" : "");

    return { d, points };
  }, [draw, drawing, project]);

  const drawMetrics = useMemo(() => {
    if (!drawing || drawing.length < 3) return null;

    return {
      area: ringArea(drawing),
      perimeter: ringPerimeter(drawing),
    };
  }, [drawing]);

  const measurePath = useMemo(() => {
    if (measurePoints.length === 0) return null;

    const points = measurePoints.map((point) => project(point.lat, point.lng));

    return {
      d: points.map((point, index) => `${index === 0 ? "M" : "L"}${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(" "),
      points,
      total: pathLength(measurePoints),
    };
  }, [measurePoints, project]);

  // ------------------------------------------------------------ controls

  const locate = async () => {
    const position = await geo.request();
    if (!position) return;

    setCentre(position);
    setZoom(clampZoom(15, maxZoom));
    setMoved(false);
    onAreaSearch?.({ center: position, radiusKm: 5, zoom: 15 });
  };

  /** Frame everything the map is showing — pins and parcels alike. */
  const fitAll = useCallback(() => {
    const points: LatLng[] = [
      ...pins.map((pin) => ({ lat: pin.lat, lng: pin.lng })),
      ...polygons.flatMap((polygon) =>
        polygon.rings.flat().map(([lng, lat]) => ({ lat, lng })),
      ),
    ];

    const fit = zoomForBounds(points, size.width, size.height);
    if (!fit) return;

    setCentre(fit.center);
    setZoom(clampZoom(fit.zoom, maxZoom));
    setMoved(true);
    scheduleAreaSearch();
  }, [pins, polygons, size.width, size.height, maxZoom, scheduleAreaSearch]);

  /*
   * A parcel-only map has no pins to fit, and opening it at the default zoom
   * over Dar es Salaam would show the customer a city, not their plot. Frame
   * the shape once, the first time it arrives.
   */
  const polygonKey = polygons.map((polygon) => polygon.id).join("|");
  const [framedPolygons, setFramedPolygons] = useState<string | null>(null);

  if (
    polygons.length > 0 &&
    pins.length === 0 &&
    !fitToPins &&
    size.width > 0 &&
    framedPolygons !== polygonKey
  ) {
    setFramedPolygons(polygonKey);

    const fit = zoomForBounds(
      polygons.flatMap((polygon) => polygon.rings.flat().map(([lng, lat]) => ({ lat, lng }))),
      size.width,
      size.height,
      48,
    );

    if (fit) {
      setCentre(fit.center);
      setZoom(clampZoom(fit.zoom, maxZoom));
    }
  }

  /** A click that was not a drag: add a corner, or a measure point. */
  const onMapClick = (event: React.MouseEvent) => {
    if (gesture.current?.dragged) return;

    const rect = containerRef.current?.getBoundingClientRect();
    if (!rect) return;

    const position = unproject(event.clientX - rect.left, event.clientY - rect.top);

    if (measuring) {
      setMeasurePoints((current) => [...current, position]);
      return;
    }

    if (draw && onDrawingChange) {
      onDrawingChange([
        ...(drawing ?? []),
        [Number(position.lng.toFixed(7)), Number(position.lat.toFixed(7))],
      ]);
    }
  };

  const controlClass =
    "flex h-9 w-9 items-center justify-center rounded-[var(--radius-control)] bg-surface text-ink shadow-sm ring-1 ring-line transition hover:bg-brand-soft hover:text-brand-ink disabled:cursor-not-allowed disabled:opacity-40";

  return (
    <div
      className={
        fullscreen
          ? "fixed inset-0 z-[60] bg-ink/90 p-3 sm:p-6"
          : "relative"
      }
    >
      <div
        ref={containerRef}
        className={`relative overflow-hidden rounded-[var(--radius-card)] border border-line bg-[#EEF4FF] select-none ${
          interactive ? "touch-none" : ""
        } ${fullscreen ? "h-full w-full" : ""} ${draw ? "cursor-crosshair" : ""} ${
          measuring ? "cursor-crosshair" : ""
        }`}
        style={fullscreen ? undefined : { height }}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endPointer}
        onPointerCancel={endPointer}
        onClick={onMapClick}
        onDoubleClick={(event) => {
          if (!interactive || draw || measuring) return;
          const rect = event.currentTarget.getBoundingClientRect();
          zoomAround(1, event.clientX - rect.left, event.clientY - rect.top);
        }}
        role="application"
        aria-label={draw ? "Draw the land boundary" : "Map"}
      >
        <div
          className={`absolute inset-0 ${
            interactive && !draw && !measuring ? "cursor-grab active:cursor-grabbing" : ""
          }`}
          style={{
            transform: `rotate(${bearing}deg) scale(${tileScale})`,
            transformOrigin: "center center",
          }}
        >
          {tiles.map((tile) => (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              key={tile.key}
              src={tile.url}
              alt=""
              width={TILE_SIZE}
              height={TILE_SIZE}
              draggable={false}
              className="absolute max-w-none"
              style={{ left: tile.left, top: tile.top }}
            />
          ))}
        </div>

        {/* Shapes: parcels, the outline being drawn, and any measurement. */}
        {(shapes.length > 0 || drawPath || measurePath) && (
          <svg
            className="pointer-events-none absolute inset-0 z-[5] h-full w-full"
            aria-hidden="true"
          >
            {shapes.map((shape) => (
              <path
                key={shape.id}
                d={shape.d}
                fill={shape.tone.fill}
                fillRule="evenodd"
                stroke={shape.tone.stroke}
                strokeWidth={2}
                strokeLinejoin="round"
              />
            ))}

            {drawPath && (
              <path
                d={drawPath.d}
                fill={drawing && drawing.length > 2 ? "rgba(20,184,166,0.22)" : "none"}
                stroke="#0d9488"
                strokeWidth={2}
                strokeDasharray={drawing && drawing.length > 2 ? undefined : "6 4"}
                strokeLinejoin="round"
              />
            )}

            {measurePath && (
              <path
                d={measurePath.d}
                fill="none"
                stroke="#ea580c"
                strokeWidth={2}
                strokeDasharray="6 4"
              />
            )}
          </svg>
        )}

        {/* Parcel labels sit above the shading, upright regardless of bearing. */}
        {shapes.map((shape) =>
          shape.label && shape.anchor ? (
            <span
              key={`${shape.id}-label`}
              className="pointer-events-none absolute z-[6] -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full bg-surface/95 px-2.5 py-1 text-[11px] font-bold text-ink shadow"
              style={{ left: shape.anchor.x, top: shape.anchor.y }}
            >
              {shape.label}
            </span>
          ) : null,
        )}

        {/* Draggable corners, in draw mode. */}
        {draw &&
          drawPath?.points.map((point, index) => (
            <button
              key={index}
              type="button"
              aria-label={`Corner ${index + 1} — drag to move, click to remove`}
              onPointerDown={(event) => {
                event.stopPropagation();
                draggingVertex.current = index;
                setInteracting(true);
              }}
              onClick={(event) => {
                event.stopPropagation();

                // Below four corners there is no polygon left to remove one
                // from, so the handle stops deleting rather than silently
                // destroying the shape.
                if (!drawing || drawing.length <= 3 || !onDrawingChange) return;

                onDrawingChange(drawing.filter((_, i) => i !== index));
              }}
              className="absolute z-10 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-brand shadow-md transition hover:scale-125 hover:bg-orange"
              style={{ left: point.x, top: point.y }}
            />
          ))}

        {/* Measure points. */}
        {measurePath?.points.map((point, index) => (
          <span
            key={index}
            className="pointer-events-none absolute z-10 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-orange shadow"
            style={{ left: point.x, top: point.y }}
          />
        ))}

        {/* Markers */}
        {clusters.map((cluster) => {
          const isCluster = cluster.pins.length > 1;
          const pin = cluster.pins[0];
          const tone = TONE_CLASSES[pin.tone ?? "listing"];
          const isActive = !isCluster && (activePinId === pin.id || openPinId === pin.id);

          if (
            cluster.x < -80 ||
            cluster.y < -80 ||
            cluster.x > size.width + 80 ||
            cluster.y > size.height + 80
          ) {
            return null;
          }

          return (
            <button
              key={cluster.key}
              type="button"
              onPointerDown={(event) => event.stopPropagation()}
              onClick={(event) => {
                event.stopPropagation();

                // A pan that happens to end on a marker is not a click on it.
                if (gesture.current?.dragged) return;

                if (isCluster) {
                  setCentre({
                    lat: yToLat(centreY - size.height / 2 + cluster.y, zoom),
                    lng: xToLng(centreX - size.width / 2 + cluster.x, zoom),
                  });
                  zoomAround(2);
                  return;
                }

                if (showPopups) setOpenPinId(pin.id);
                onPinClick?.(pin);
              }}
              aria-label={isCluster ? `${cluster.pins.length} results here` : pin.label}
              className={`absolute z-10 -translate-x-1/2 -translate-y-full rounded-full px-2.5 py-1 text-[11px] font-bold shadow-lg ${
                interacting ? "" : "transition-transform"
              } hover:scale-110 ${isCluster ? "bg-ink text-white" : tone} ${
                isActive ? "ring-2 ring-white scale-110" : ""
              }`}
              style={{ left: cluster.x, top: cluster.y }}
            >
              {isCluster ? cluster.pins.length : pin.sublabel || "•"}
            </button>
          );
        })}

        {/* Marker popup — label and coordinates only; see the note at the top
          of this file for what the marketplace version carries here. */}
      {openPin && openPinPosition && (
        <div
          className="absolute z-30 w-56 -translate-x-1/2 rounded-[var(--radius-card)] border border-line bg-surface p-3 shadow-xl"
          style={{
            left: Math.min(Math.max(openPinPosition.x, 116), Math.max(116, size.width - 116)),
            top: openPinPosition.y + 12,
          }}
          onPointerDown={(event) => event.stopPropagation()}
          onClick={(event) => event.stopPropagation()}
        >
          <button
            type="button"
            onClick={() => setOpenPinId(null)}
            aria-label="Close"
            className="absolute right-2 top-2 text-ink-faint transition hover:text-ink"
          >
            <X aria-hidden className="h-3.5 w-3.5" />
          </button>

          <p className="pr-4 text-sm font-semibold leading-snug text-ink">{openPin.label}</p>

          {openPin.meta && <p className="mt-0.5 text-xs text-ink-soft">{openPin.meta}</p>}
          {openPin.sublabel && (
            <p className="mt-1 text-sm font-bold text-brand-ink">{openPin.sublabel}</p>
          )}
        </div>
      )}

      {/* Base-layer switcher */}
        {interactive && allowLayers && (
          <div
            className="absolute left-3 bottom-6 z-20 flex overflow-hidden rounded-lg bg-surface shadow-md"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            {(Object.keys(MAP_LAYERS) as MapLayerId[]).map((id) => (
              <button
                key={id}
                type="button"
                onClick={() => setLayerId(id)}
                aria-pressed={layerId === id}
                className={`px-3 py-1.5 text-xs font-semibold transition ${
                  layerId === id ? "bg-brand text-white" : "text-ink hover:bg-brand-soft"
                }`}
              >
                {MAP_LAYERS[id].label}
              </button>
            ))}
          </div>
        )}

        {/* Controls */}
        {interactive && (
          <div
            className="absolute right-3 top-3 z-20 flex flex-col gap-2"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            <button
              type="button"
              onClick={() => zoomAround(1)}
              aria-label="Zoom in"
              disabled={zoom >= maxZoom}
              className={controlClass}
            >
              <Plus className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={() => zoomAround(-1)}
              aria-label="Zoom out"
              disabled={zoom <= MIN_ZOOM}
              className={controlClass}
            >
              <Minus className="h-4 w-4" />
            </button>
            <button type="button" onClick={locate} aria-label="Use my location" className={controlClass}>
              {geo.loading ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Crosshair className="h-4 w-4" />
              )}
            </button>

            {(pins.length > 1 || polygons.length > 0) && (
              <button type="button" onClick={fitAll} aria-label="Fit everything on screen" className={controlClass}>
                <Maximize2 className="h-4 w-4" />
              </button>
            )}

            {allowRotate && (
              <button
                type="button"
                onClick={() => setBearing((current) => (current + 45) % 360)}
                onDoubleClick={() => setBearing(0)}
                aria-label={
                  bearing === 0 ? "Rotate the map" : `Rotated ${bearing}° — double-click to reset`
                }
                title={bearing === 0 ? "Rotate" : "Double-click to face north"}
                className={controlClass}
              >
                <Compass className="h-4 w-4" style={{ transform: `rotate(${-bearing}deg)` }} />
              </button>
            )}

            {allowMeasure && (
              <button
                type="button"
                onClick={() => {
                  setMeasuring((current) => !current);
                  setMeasurePoints([]);
                }}
                aria-pressed={measuring}
                aria-label="Measure distance"
                className={`${controlClass} ${measuring ? "bg-warn text-white" : ""}`}
              >
                <Ruler className="h-4 w-4" />
              </button>
            )}

            {allowFullscreen && (
              <button
                type="button"
                onClick={() => setFullscreen((current) => !current)}
                aria-label={fullscreen ? "Exit fullscreen" : "Fullscreen"}
                className={controlClass}
              >
                {fullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
              </button>
            )}
          </div>
        )}

        {/* Drawing readout and undo, top-left so it never covers the controls. */}
        {draw && (
          <div
            className="absolute left-3 top-3 z-20 rounded-[var(--radius-card)] bg-surface/95 px-3 py-2 shadow-md"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            {drawMetrics ? (
              <>
                <p className="text-sm font-extrabold text-ink">{formatArea(drawMetrics.area)}</p>
                <p className="text-[11px] text-ink-soft">
                  {formatDistanceMetres(drawMetrics.perimeter)} perimeter ·{" "}
                  {drawing?.length ?? 0} corners
                </p>
              </>
            ) : (
              <p className="text-xs font-semibold text-ink">
                Tap each corner of the plot — {3 - (drawing?.length ?? 0)} more to close it
              </p>
            )}

            <div className="mt-2 flex gap-2">
              <button
                type="button"
                onClick={() => onDrawingChange?.((drawing ?? []).slice(0, -1))}
                disabled={!drawing?.length}
                className="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-[11px] font-semibold text-ink transition hover:border-teal hover:text-brand-ink disabled:opacity-40"
              >
                <Undo2 className="h-3 w-3" />
                Undo
              </button>
              <button
                type="button"
                onClick={() => onDrawingChange?.([])}
                disabled={!drawing?.length}
                className="inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-[11px] font-semibold text-ink transition hover:border-orange hover:text-orange disabled:opacity-40"
              >
                <Trash2 className="h-3 w-3" />
                Clear
              </button>
            </div>
          </div>
        )}

        {/* Measurement readout */}
        {measuring && !draw && (
          <div
            className="absolute left-3 top-3 z-20 rounded-[var(--radius-card)] bg-surface/95 px-3 py-2 shadow-md"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            <p className="text-sm font-extrabold text-ink">
              {measurePath ? formatDistanceMetres(measurePath.total) : "0 m"}
            </p>
            <p className="text-[11px] text-ink-soft">
              {measurePoints.length < 2
                ? "Tap two or more points to measure"
                : `${measurePoints.length} points · Esc to finish`}
            </p>

            {measurePoints.length > 0 && (
              <button
                type="button"
                onClick={() => setMeasurePoints([])}
                className="mt-2 inline-flex items-center gap-1 rounded-full border border-line px-2.5 py-1 text-[11px] font-semibold text-ink transition hover:border-orange hover:text-orange"
              >
                <Trash2 className="h-3 w-3" />
                Reset
              </button>
            )}
          </div>
        )}

        {/* Manual mode keeps the original button; auto mode needs no affordance. */}
        {onAreaSearch && !autoSearch && moved && !draw && !measuring && (
          <button
            type="button"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => {
              event.stopPropagation();
              onAreaSearch(viewport());
              setMoved(false);
            }}
            className="absolute left-1/2 top-3 z-20 flex -translate-x-1/2 items-center gap-2 rounded-full bg-ink px-4 py-2 text-sm font-semibold text-white shadow-lg transition hover:opacity-90"
          >
            <RefreshCw className="h-4 w-4" />
            Search this area
          </button>
        )}

        {loading && !draw && (
          <div className="absolute left-1/2 top-3 z-20 flex -translate-x-1/2 items-center gap-2 rounded-full bg-surface px-3 py-1.5 text-xs font-semibold text-ink shadow-md">
            <Loader2 className="h-3.5 w-3.5 animate-spin text-brand-ink" />
            Loading
          </div>
        )}

        {geo.error && (
          <p className="absolute bottom-8 right-3 z-20 max-w-xs rounded-lg bg-surface/95 px-3 py-2 text-xs text-ink-soft shadow">
            {geo.error}
          </p>
        )}

        <p className="pointer-events-none absolute bottom-0 right-0 z-20 bg-surface/80 px-2 py-0.5 text-[10px] text-ink-soft">
          {layer.attribution}
        </p>
      </div>
    </div>
  );
}
