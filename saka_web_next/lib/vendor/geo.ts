/**
 * Map geometry for the boundary editor.
 *
 * A VERBATIM COPY of `lib/geo.ts` in the marketplace app, and it has to stay
 * one. The seller watches the area change as they drag a corner here, and the
 * buyer reads the area on the marketplace listing; if these two files drift,
 * the same plot is advertised at two different sizes.
 *
 * All three implementations — this, the marketplace copy and the server's
 * LandBoundaryService — use the same mean Earth radius and the same
 * spherical-excess formula, so the number the seller sees while drawing is the
 * number the server stores.
 *
 * The two apps are separate Next projects with no shared package; duplicating
 * one dependency-free file is the smaller cost.
 */

export const TILE_SIZE = 256;

/** Mean Earth radius, metres (IUGG) — the same constant the API uses. */
const EARTH_RADIUS_M = 6_371_008.8;

const SQM_PER_ACRE = 4046.8564224;
const SQM_PER_HECTARE = 10_000;

export type LatLng = { lat: number; lng: number };

/** GeoJSON order: [longitude, latitude]. */
export type Position = [number, number];

export function lngToX(lng: number, zoom: number): number {
  return ((lng + 180) / 360) * TILE_SIZE * 2 ** zoom;
}

export function latToY(lat: number, zoom: number): number {
  const sin = Math.sin((lat * Math.PI) / 180);
  const y = 0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI);
  return y * TILE_SIZE * 2 ** zoom;
}

export function xToLng(x: number, zoom: number): number {
  return (x / (TILE_SIZE * 2 ** zoom)) * 360 - 180;
}

export function yToLat(y: number, zoom: number): number {
  const n = Math.PI - (2 * Math.PI * y) / (TILE_SIZE * 2 ** zoom);
  return (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}

/** Great-circle distance in metres. */
export function haversine(a: LatLng, b: LatLng): number {
  const lat1 = (a.lat * Math.PI) / 180;
  const lat2 = (b.lat * Math.PI) / 180;
  const dLat = lat2 - lat1;
  const dLng = ((b.lng - a.lng) * Math.PI) / 180;

  const h =
    Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

  return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

/**
 * Area of a closed ring in square metres, by spherical excess.
 *
 * Accepts an open ring — the closing edge is added here — because that is what
 * a half-drawn polygon looks like while the seller is still clicking corners.
 */
export function ringArea(ring: Position[]): number {
  if (ring.length < 3) return 0;

  let total = 0;

  for (let i = 0; i < ring.length; i++) {
    const [lng1, lat1] = ring[i];
    const [lng2, lat2] = ring[(i + 1) % ring.length];

    total +=
      (((lng2 - lng1) * Math.PI) / 180) *
      (2 + Math.sin((lat1 * Math.PI) / 180) + Math.sin((lat2 * Math.PI) / 180));
  }

  return Math.abs((total * EARTH_RADIUS_M * EARTH_RADIUS_M) / 2);
}

/** Perimeter of a closed ring in metres, including the closing edge. */
export function ringPerimeter(ring: Position[]): number {
  if (ring.length < 2) return 0;

  let total = 0;

  for (let i = 0; i < ring.length; i++) {
    const a = ring[i];
    const b = ring[(i + 1) % ring.length];

    // An open two-point "ring" is a line, not a loop — do not double it.
    if (i === ring.length - 1 && ring.length < 3) break;

    total += haversine({ lat: a[1], lng: a[0] }, { lat: b[1], lng: b[0] });
  }

  return total;
}

/** Length of an open path in metres — what the measure tool reports. */
export function pathLength(points: LatLng[]): number {
  let total = 0;

  for (let i = 1; i < points.length; i++) {
    total += haversine(points[i - 1], points[i]);
  }

  return total;
}

/**
 * Area in the unit land is actually traded in here.
 *
 * Mirrors LandBoundaryService::areaSummary so the client-side preview and the
 * saved value read identically.
 */
export function formatArea(sqm: number): string {
  const acres = sqm / SQM_PER_ACRE;
  const hectares = sqm / SQM_PER_HECTARE;

  if (sqm < 1012) return `${Math.round(sqm).toLocaleString()} m²`;
  if (acres < 10) return `${acres.toFixed(2)} acres`;

  return `${hectares.toFixed(2)} ha`;
}

export function formatDistanceMetres(metres: number): string {
  if (metres < 1000) return `${Math.round(metres).toLocaleString()} m`;
  return `${(metres / 1000).toFixed(2)} km`;
}

/** Centroid of a ring, for placing a label or a pin. */
export function ringCentroid(ring: Position[]): LatLng | null {
  if (ring.length === 0) return null;

  let lat = 0;
  let lng = 0;

  for (const [x, y] of ring) {
    lng += x;
    lat += y;
  }

  return { lat: lat / ring.length, lng: lng / ring.length };
}

/** The tightest lat/lng box containing every point. */
export function bounds(points: LatLng[]): {
  minLat: number;
  maxLat: number;
  minLng: number;
  maxLng: number;
} | null {
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

  return { minLat, maxLat, minLng, maxLng };
}
