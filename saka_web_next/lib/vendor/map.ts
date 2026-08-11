/**
 * Map configuration for the vendor portal.
 *
 * Mirrors the marketplace's `lib/config.ts` map section. Kept in its own module
 * rather than added to `lib/config.ts` here, because that file is about the
 * marketplace ORIGIN and this is about tile providers — unrelated concerns that
 * happen to share a filename in the other app.
 *
 * Every host below must also appear in `img-src` in next.config.ts, or the
 * browser silently blocks the tiles and the map renders as an empty grid.
 */

function env(value: string | undefined, fallback: string): string {
  const trimmed = value?.trim();
  return trimmed ? trimmed : fallback;
}

export type MapLayerId = "street" | "satellite" | "terrain";

export const MAP_LAYERS: Record<
  MapLayerId,
  { label: string; url: string; attribution: string; maxZoom: number }
> = {
  satellite: {
    label: "Satellite",
    /*
     * Esri serves {z}/{y}/{x} — Y BEFORE X, unlike every other provider here.
     * Getting that backwards does not error; it renders a coherent-looking
     * mosaic of the wrong place, which is the hardest kind of map bug to spot.
     */
    url: "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
    attribution: "Imagery © Esri, Maxar, Earthstar Geographics",
    maxZoom: 19,
  },
  street: {
    label: "Map",
    url: env(
      process.env.NEXT_PUBLIC_MAP_TILE_URL,
      "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
    ),
    attribution: "© OpenStreetMap contributors",
    maxZoom: 19,
  },
  terrain: {
    label: "Terrain",
    url: "https://tile.opentopomap.org/{z}/{x}/{y}.png",
    attribution: "© OpenTopoMap (CC-BY-SA), © OpenStreetMap contributors",
    maxZoom: 17,
  },
};

/** Dar es Salaam. Used when a listing has no coordinates yet. */
export const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };
