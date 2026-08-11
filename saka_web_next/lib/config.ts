/**
 * Public runtime configuration.
 *
 * NEXT_PUBLIC_ only where the value is genuinely needed in the browser. The API
 * origin is deliberately NOT public: the browser talks to this app's own proxy,
 * which is what keeps the access token in an httpOnly cookie.
 */

/**
 * Read an environment variable, treating BLANK as absent.
 *
 * `??` only falls back on null/undefined, and a declared-but-empty key in
 * `.env.local` — `NEXT_PUBLIC_MAP_TILE_URL=` — is an empty STRING. That is the
 * normal way to write "leave this at the default" in a dotenv file, and with
 * `??` it silently won through: the tile URL became "", every tile rendered as
 * an <img> with no src, and the map showed its border and markers over blank
 * space. Anything optional and env-driven must go through this.
 */
function env(value: string | undefined, fallback: string): string {
  const trimmed = value?.trim();
  return trimmed ? trimmed : fallback;
}

export const SITE_URL = env(process.env.NEXT_PUBLIC_SITE_URL, "http://localhost:3000").replace(
  /\/$/,
  "",
);

/**
 * Map tiles.
 *
 * OpenStreetMap needs no key, which is what lets maps work out of the box. A
 * Google Maps key is optional and only ever used to build "open in" links —
 * those work without a key too.
 */
export const MAP_TILE_URL = env(
  process.env.NEXT_PUBLIC_MAP_TILE_URL,
  "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
);

export const MAP_ATTRIBUTION = "© OpenStreetMap contributors";

/**
 * Google AdSense.
 *
 * The publisher id is genuinely public — it ships in the page source of every
 * AdSense site on the internet — so NEXT_PUBLIC_ is correct here and is not an
 * exception to the rule above. What must never be public is the AdSense API
 * credential, which this app does not have and does not need.
 *
 * BLANK MEANS OFF, and off means the script is never loaded and no slot ever
 * renders one. That is the default, and it is what makes an unconfigured
 * deployment — a review app, a local checkout, a staging box — silently
 * ad-free rather than emitting requests to Google with somebody else's id, or
 * worse, serving live ads on a test domain and putting the AdSense account at
 * risk of policy action.
 */
export const ADSENSE_CLIENT = env(process.env.NEXT_PUBLIC_ADSENSE_CLIENT, "");

/**
 * The slot id per placement, as configured in the AdSense dashboard.
 *
 * One variable holding `placement:slotId` pairs rather than one variable per
 * placement: placements are added in code, and a deployment that gains a new
 * slot should not need an infrastructure change to go with it. A placement
 * with no pair here simply renders nothing.
 *
 *   NEXT_PUBLIC_ADSENSE_SLOTS="listings_inline:1234567890,footer:0987654321"
 */
export const ADSENSE_SLOTS: Record<string, string> = Object.fromEntries(
  env(process.env.NEXT_PUBLIC_ADSENSE_SLOTS, "")
    .split(",")
    .map((pair) => pair.trim())
    .filter(Boolean)
    .map((pair) => {
      const [placement, slot] = pair.split(":").map((part) => part.trim());
      return [placement, slot] as const;
    })
    .filter(([placement, slot]) => Boolean(placement) && Boolean(slot)),
);

export const ADSENSE_ENABLED = ADSENSE_CLIENT !== "";

/**
 * The base layers the map can switch between.
 *
 * Satellite is not a nicety here. A land parcel is a shape over bare ground:
 * on a road map its outline sits on white space with nothing to check it
 * against, and the seller drawing it has no way to trace the actual boundary.
 * On imagery both are possible, which is why the boundary editor opens on it.
 *
 * Esri's World Imagery serves tiles in {z}/{y}/{x} order — Y BEFORE X, unlike
 * every other provider here. Getting that backwards does not error; it renders
 * a coherent-looking mosaic of the wrong place, which is the hardest kind of
 * map bug to spot. The order lives in the template so there is one place to
 * read it.
 *
 * Every host listed here must also be in `img-src` in next.config.ts, or the
 * browser blocks the tiles and the map goes blank with only a console entry to
 * say why.
 */
export type MapLayerId = "street" | "satellite" | "terrain";

export const MAP_LAYERS: Record<
  MapLayerId,
  { label: string; url: string; attribution: string; maxZoom: number }
> = {
  street: {
    label: "Map",
    url: MAP_TILE_URL,
    attribution: "© OpenStreetMap contributors",
    maxZoom: 19,
  },
  satellite: {
    label: "Satellite",
    url: "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
    attribution: "Imagery © Esri, Maxar, Earthstar Geographics",
    maxZoom: 19,
  },
  terrain: {
    label: "Terrain",
    // OpenTopoMap asks that heavy use be cached or self-hosted; it stops
    // serving above z17, so the cap is theirs rather than ours.
    url: "https://tile.opentopomap.org/{z}/{x}/{y}.png",
    attribution: "© OpenTopoMap (CC-BY-SA), © OpenStreetMap contributors",
    maxZoom: 17,
  },
};

/** Dar es Salaam. Used when a customer has not shared their location. */
export const DEFAULT_CENTER = { lat: -6.7924, lng: 39.2083 };

export function googleMapsUrl(lat: number, lng: number, label?: string): string {
  const query = label ? `${lat},${lng}(${encodeURIComponent(label)})` : `${lat},${lng}`;
  return `https://www.google.com/maps/search/?api=1&query=${query}`;
}

export function googleDirectionsUrl(lat: number, lng: number): string {
  return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
}

/**
 * Apple Maps, offered only where it will actually work.
 *
 * maps.apple.com on Android or Windows opens a web page that immediately tells
 * the user to get an Apple device, so the link is hidden rather than broken.
 */
export function appleMapsUrl(lat: number, lng: number, label?: string): string {
  const name = label ? `&q=${encodeURIComponent(label)}` : "";
  return `https://maps.apple.com/?ll=${lat},${lng}${name}`;
}

export function isAppleDevice(): boolean {
  if (typeof navigator === "undefined") return false;
  return /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent);
}
