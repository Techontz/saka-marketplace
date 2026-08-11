"use client";

import dynamic from "next/dynamic";
import type { ComponentProps } from "react";
import { Loader2 } from "lucide-react";

import type { MapView as MapViewType } from "@/components/map/MapView";

/**
 * MapView, loaded only when a map is actually going to be shown.
 *
 * MapView is the largest client component in the app — projection maths, tile
 * management, pointer and pinch handling, polygon rendering and the measure
 * tool. It was in the main bundle of every page that could POSSIBLY show a
 * map, which includes the listings grid, where most sessions never switch to
 * the map tab at all.
 *
 * `ssr: false` is correct rather than merely convenient: the component measures
 * its own container with a ResizeObserver and projects tiles from that width,
 * so a server render produces a tile grid for an 800px assumption that is
 * immediately thrown away and re-rendered on mount. Skipping it removes work
 * from the server and a layout shift from the client.
 *
 * The placeholder holds the exact height the map will take, so nothing below it
 * jumps when the chunk lands.
 */
export const LazyMapView = dynamic(
  () => import("@/components/map/MapView").then((module) => module.MapView),
  {
    ssr: false,
    loading: () => (
      <div
        className="flex items-center justify-center rounded-xl border border-border bg-[#EEF4FF]"
        style={{ height: 420 }}
      >
        <span className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin text-teal" />
          Loading map…
        </span>
      </div>
    ),
  },
) as typeof MapViewType;

export type LazyMapViewProps = ComponentProps<typeof MapViewType>;
