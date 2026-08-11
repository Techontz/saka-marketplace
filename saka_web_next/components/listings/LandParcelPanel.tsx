"use client";

import { useState } from "react";
import { Compass, Copy, Check, ExternalLink, Ruler, Scan } from "lucide-react";

import { LazyMapView } from "@/components/map/LazyMapView";
import { googleDirectionsUrl, googleMapsUrl } from "@/lib/config";
import type { ApiBoundary } from "@/lib/types";

/**
 * The land parcel: its outline, its size, and its corners.
 *
 * The single most important fact about a plot is where its edges are, and
 * every marketplace in this market publishes a pin and a number of acres and
 * asks the buyer to take both on trust. This shows the shape the seller
 * actually drew, over satellite imagery, at the size the SERVER measured it —
 * so the acreage on the page and the acreage of the polygon cannot disagree.
 *
 * Opens on Satellite for the same reason: a boundary on a road map is an
 * outline in white space with nothing to check it against.
 */
export function LandParcelPanel({
  boundary,
  title,
  fallbackLat,
  fallbackLng,
}: {
  boundary: ApiBoundary;
  title: string;
  fallbackLat: number | null;
  fallbackLng: number | null;
}) {
  const [copied, setCopied] = useState(false);
  const [showCorners, setShowCorners] = useState(false);

  const centre = boundary.centroid
    ? { lat: boundary.centroid.latitude, lng: boundary.centroid.longitude }
    : fallbackLat !== null && fallbackLng !== null
      ? { lat: fallbackLat, lng: fallbackLng }
      : null;

  const outer = boundary.rings[0] ?? [];

  // The stored ring repeats its first point to close itself; a buyer counting
  // corners should not see that repeat listed twice.
  const corners = outer.length > 1 && outer[0][0] === outer[outer.length - 1][0] && outer[0][1] === outer[outer.length - 1][1]
    ? outer.slice(0, -1)
    : outer;

  const copyCoordinates = async () => {
    const text = corners
      .map(([lng, lat], index) => `${index + 1}. ${lat.toFixed(6)}, ${lng.toFixed(6)}`)
      .join("\n");

    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard is permission-gated and blocked outright in some in-app
      // browsers. The corner list is on the page either way, so failing here
      // costs nothing — an error toast for a copy button would be noise.
    }
  };

  return (
    <div className="rounded-xl border border-border bg-white p-6 sm:p-8">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h3 className="flex items-center gap-2 text-2xl font-extrabold text-navy">
            <Scan className="h-5 w-5 text-teal" />
            Land boundary
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Surveyed outline of the plot, measured from its corner coordinates.
          </p>
        </div>

        {centre && (
          <div className="flex flex-wrap gap-2">
            <a
              href={googleMapsUrl(centre.lat, centre.lng, title)}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 rounded-full border border-border px-4 py-2 text-sm font-semibold text-navy transition hover:border-teal hover:text-teal"
            >
              <ExternalLink className="h-4 w-4" />
              Google Maps
            </a>
            <a
              href={googleDirectionsUrl(centre.lat, centre.lng)}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1.5 rounded-full bg-teal px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
            >
              <Compass className="h-4 w-4" />
              Directions
            </a>
          </div>
        )}
      </div>

      <dl className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Metric label="Area" value={boundary.area.display} accent />
        <Metric label="Perimeter" value={boundary.perimeter_display} />
        <Metric label="Corners" value={String(boundary.vertex_count)} />
        <Metric
          label="Also"
          value={`${boundary.area.acres.toFixed(2)} ac · ${boundary.area.hectares.toFixed(2)} ha`}
        />
      </dl>

      <LazyMapView
        pins={[]}
        polygons={[{ id: "parcel", rings: boundary.rings, tone: "parcel", label: boundary.area.display }]}
        center={centre}
        zoom={17}
        height={420}
        layer="satellite"
        allowRotate
        allowMeasure
        allowFullscreen
      />

      <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
        <Ruler className="h-3.5 w-3.5" />
        Use the ruler to measure a road frontage, or the compass to turn the map to face the plot.
      </p>

      {(boundary.survey_reference || boundary.notes) && (
        <div className="mt-5 rounded-lg bg-page p-4">
          {boundary.survey_reference && (
            <p className="text-sm text-navy">
              <span className="font-semibold">Survey reference:</span> {boundary.survey_reference}
            </p>
          )}
          {boundary.notes && (
            <p className="mt-1 text-sm text-muted-foreground">{boundary.notes}</p>
          )}
        </div>
      )}

      <div className="mt-5 flex flex-wrap items-center gap-3">
        <button
          type="button"
          onClick={() => setShowCorners((current) => !current)}
          aria-expanded={showCorners}
          className="text-sm font-semibold text-teal transition hover:underline"
        >
          {showCorners ? "Hide corner coordinates" : `Show all ${corners.length} corner coordinates`}
        </button>

        {showCorners && (
          <button
            type="button"
            onClick={copyCoordinates}
            className="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-navy transition hover:border-teal hover:text-teal"
          >
            {copied ? <Check className="h-3.5 w-3.5 text-teal" /> : <Copy className="h-3.5 w-3.5" />}
            {copied ? "Copied" : "Copy"}
          </button>
        )}
      </div>

      {showCorners && (
        <ol className="mt-3 grid grid-cols-1 gap-1 text-sm text-muted-foreground sm:grid-cols-2">
          {corners.map(([lng, lat], index) => (
            <li key={index} className="flex gap-2 tabular-nums">
              <span className="w-6 shrink-0 font-semibold text-navy">{index + 1}.</span>
              {lat.toFixed(6)}, {lng.toFixed(6)}
            </li>
          ))}
        </ol>
      )}

      <p className="mt-5 border-t border-border pt-4 text-xs text-muted-foreground">
        Measurements are calculated by SAKA from the coordinates above, not entered by the seller.
        Always confirm the boundary against the title deed and an official survey before buying.
      </p>
    </div>
  );
}

function Metric({ label, value, accent = false }: { label: string; value: string; accent?: boolean }) {
  return (
    <div className="rounded-lg bg-page px-3 py-3">
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className={`mt-0.5 text-base font-extrabold ${accent ? "text-teal" : "text-navy"}`}>
        {value}
      </dd>
    </div>
  );
}
