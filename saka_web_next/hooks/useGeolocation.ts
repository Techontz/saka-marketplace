"use client";

import { useCallback, useState } from "react";

export type Coords = { lat: number; lng: number };

/**
 * The customer's position, asked for only when it is needed.
 *
 * Ported from the original hook with one deliberate change: it no longer
 * requests a location on mount. The browser permission prompt appearing the
 * instant someone lands on the site is the fastest way to have it denied
 * permanently — and a denial is remembered, which would break "near me" for
 * good. It now fires when the customer asks for something location-based.
 */
export function useGeolocation() {
  const [coords, setCoords] = useState<Coords | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const request = useCallback((): Promise<Coords | null> => {
    if (typeof navigator === "undefined" || !navigator.geolocation) {
      setError("Location is not supported by your browser.");
      return Promise.resolve(null);
    }

    setLoading(true);
    setError(null);

    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          const next = { lat: position.coords.latitude, lng: position.coords.longitude };
          setCoords(next);
          setLoading(false);
          resolve(next);
        },
        (cause) => {
          setError(
            cause.code === cause.PERMISSION_DENIED
              ? "Location permission was declined. You can still search by area name."
              : "We couldn't work out where you are.",
          );
          setLoading(false);
          resolve(null);
        },
        { enableHighAccuracy: false, timeout: 10_000, maximumAge: 60_000 },
      );
    });
  }, []);

  return { coords, error, loading, request, setCoords };
}

export function distanceMeters(a: Coords, b: Coords): number {
  const R = 6371e3;
  const toRad = (degrees: number) => (degrees * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLng = toRad(b.lng - a.lng);
  const lat1 = toRad(a.lat);
  const lat2 = toRad(b.lat);
  const h =
    Math.sin(dLat / 2) ** 2 + Math.sin(dLng / 2) ** 2 * Math.cos(lat1) * Math.cos(lat2);
  return 2 * R * Math.asin(Math.sqrt(h));
}

export function formatMetres(m: number): string {
  if (m < 1000) return `${Math.round(m)}m`;
  return `${(m / 1000).toFixed(m < 10_000 ? 1 : 0)} km`;
}
