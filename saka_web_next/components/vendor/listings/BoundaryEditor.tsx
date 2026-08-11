"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Info, Trash2 } from "lucide-react";

import { BoundaryMap, type BoundaryMeta } from "@/components/vendor/maps/BoundaryMap";
import { Button, Card, Field, FormError, Input, Textarea } from "@/components/vendor/ui";
import { apiGet, apiSend } from "@/lib/vendor/api/browser";
import { formatArea, formatDistanceMetres, type Position } from "@/lib/vendor/geo";

/**
 * Draw the plot.
 *
 * A land listing's most important fact is where its edges are, and until now a
 * seller could only state an acreage in a text field that nobody could check.
 * This stores the actual corners; the SERVER measures them and its answer is
 * what the buyer sees, so an advertised size cannot exceed the shape that
 * supports it.
 *
 * The area shown while drawing is computed client-side from the same formula
 * the API uses, which is what makes dragging a corner feel responsive. It is
 * feedback, not the record — the response after Save replaces it, and if the
 * two ever disagree the server's figure is the one that stands.
 */

type BoundaryResponse = {
  data: {
    rings: Position[][];
    area: { sqm: number; acres: number; hectares: number; display: string };
    perimeter_display: string;
    vertex_count: number;
    survey_reference: string | null;
    notes: string | null;
  } | null;
  meta?: { supported: boolean };
};

export function BoundaryEditor({ uuid, listingTitle }: { uuid: string; listingTitle: string }) {
  const queryClient = useQueryClient();

  const [points, setPoints] = useState<Position[] | null>(null);
  const [meta, setMeta] = useState<BoundaryMeta | null>(null);
  const [reference, setReference] = useState<string | null>(null);
  const [notes, setNotes] = useState<string | null>(null);

  const query = useQuery({
    queryKey: ["vendor-boundary", uuid],
    queryFn: () => apiGet<BoundaryResponse>(`/seller/listings/${uuid}/boundary`),
  });

  /*
   * Seed the editor ONCE from the server, during render rather than in an
   * effect. An effect would paint an empty map first and then drop the parcel
   * in; re-seeding on every refetch would throw away corners the seller is
   * still placing.
   */
  const stored = query.data?.data ?? null;
  const [hydrated, setHydrated] = useState(false);

  if (query.data && !hydrated) {
    setHydrated(true);
    setPoints(openRing(stored?.rings?.[0] ?? []));
    setReference(stored?.survey_reference ?? "");
    setNotes(stored?.notes ?? "");
  }

  const save = useMutation({
    mutationFn: () =>
      apiSend(`/seller/listings/${uuid}/boundary`, "PUT", {
        // The API closes the ring itself, so an open one is what it wants.
        rings: [points ?? []],
        survey_reference: reference || null,
        notes: notes || null,
      }),
    onSuccess: async () => {
      setHydrated(false);
      await queryClient.invalidateQueries({ queryKey: ["vendor-boundary", uuid] });
      await queryClient.invalidateQueries({ queryKey: ["vendor-listing", uuid] });
    },
  });

  const remove = useMutation({
    mutationFn: () => apiSend(`/seller/listings/${uuid}/boundary`, "DELETE"),
    onSuccess: async () => {
      setPoints([]);
      setMeta(null);
      setHydrated(false);
      await queryClient.invalidateQueries({ queryKey: ["vendor-boundary", uuid] });
    },
  });

  if (query.isPending) {
    return (
      <Card>
        <div className="h-[520px] animate-pulse rounded-[var(--radius-card)] bg-muted-soft" />
      </Card>
    );
  }

  if (query.data?.meta?.supported === false) {
    return (
      <Card>
        <div className="flex items-start gap-3 px-5 py-6">
          <Info aria-hidden className="mt-0.5 h-5 w-5 shrink-0 text-ink-faint" />
          <div>
            <p className="text-sm font-medium text-ink">
              This category does not use a land boundary
            </p>
            <p className="mt-1 text-sm text-ink-soft">
              Boundaries apply to plots. Move this listing into a land category if it is a plot for
              sale.
            </p>
          </div>
        </div>
      </Card>
    );
  }

  const current = points ?? [];
  const isClosed = current.length >= 3;

  return (
    <Card>
      <div className="border-b border-line px-5 py-4">
        <h2 className="text-sm font-semibold text-ink">Land boundary</h2>
        <p className="mt-1 text-sm text-ink-soft">
          Trace the corners of {listingTitle} on the satellite view. Buyers see the shaded outline
          and the size SAKA calculates from it.
        </p>
      </div>

      <div className="px-5 py-5">
        <BoundaryMap
          value={current}
          onChange={(next, changed) => {
            setPoints(next);
            setMeta(changed);
          }}
          height={480}
        />

        {/* The stored measurements, once there are any. These are the SERVER's
            numbers, which is why they are shown separately from the live
            readout on the map. */}
        {stored && (
          <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Metric label="Saved area" value={stored.area.display} />
            <Metric label="Perimeter" value={stored.perimeter_display} />
            <Metric label="Corners" value={String(stored.vertex_count)} />
            <Metric
              label="In acres"
              value={`${stored.area.acres.toFixed(2)} ac`}
            />
          </dl>
        )}

        {meta && !stored && isClosed && (
          <p className="mt-4 rounded-[var(--radius-control)] bg-brand-soft px-4 py-3 text-sm text-brand-ink">
            {formatArea(meta.areaSqm)} · {formatDistanceMetres(meta.perimeterM)} perimeter. Save to
            publish this to the listing.
          </p>
        )}

        <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Survey reference" hint="Optional. The plot number on the approved survey plan.">
            <Input
              value={reference ?? ""}
              onChange={(event) => setReference(event.target.value)}
              placeholder="e.g. DSM/01234/2026"
              maxLength={120}
            />
          </Field>

          <Field label="Notes" hint="Optional. Anything a buyer should know about the corners.">
            <Textarea
              value={notes ?? ""}
              onChange={(event) => setNotes(event.target.value)}
              rows={2}
              maxLength={2000}
              placeholder="Corners marked with concrete beacons."
            />
          </Field>
        </div>

        <div className="mt-4">
          <FormError error={save.error ?? remove.error} />
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3">
        <span className="text-xs text-ink-faint">
          {isClosed
            ? "SAKA recalculates the area from these corners when you save."
            : "At least three corners are needed before this can be saved."}
        </span>

        <div className="flex gap-2">
          {stored && (
            <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
              <Trash2 aria-hidden className="h-4 w-4" />
              Remove boundary
            </Button>
          )}

          <Button
            variant="primary"
            loading={save.isPending}
            disabled={!isClosed}
            onClick={() => save.mutate()}
          >
            Save boundary
          </Button>
        </div>
      </div>
    </Card>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-[var(--radius-control)] bg-muted-soft px-3 py-2">
      <dt className="text-[11px] font-medium uppercase tracking-wide text-ink-faint">{label}</dt>
      <dd className="mt-0.5 text-sm font-semibold text-ink">{value}</dd>
    </div>
  );
}

/** A stored ring repeats its first point; the editor works on an open one. */
function openRing(ring: Position[]): Position[] {
  if (ring.length < 2) return ring;

  const first = ring[0];
  const last = ring[ring.length - 1];

  return Math.abs(first[0] - last[0]) < 1e-9 && Math.abs(first[1] - last[1]) < 1e-9
    ? ring.slice(0, -1)
    : ring;
}
