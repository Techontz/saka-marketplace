/**
 * Number formatting that cannot throw.
 *
 * The dashboard crashed with "Cannot read properties of undefined (reading
 * 'toLocaleString')" because a counter the UI expected was absent from the API
 * response. The root cause was a field-name mismatch and is fixed at the
 * source, but a dashboard is the wrong place to discover a contract drift: a
 * single missing counter took down the whole screen, including the seven
 * figures that had arrived correctly.
 *
 * So every number on this surface goes through here. A missing value renders
 * as a dash rather than a zero — "0 views" and "we don't know" are different
 * facts, and quietly showing the first is how a vendor concludes their listing
 * is dead when the counter simply failed to load.
 */

export function formatNumber(value: unknown, fallback = "—"): string {
  const numeric = typeof value === "string" ? Number(value) : value;

  if (typeof numeric !== "number" || !Number.isFinite(numeric)) return fallback;

  return numeric.toLocaleString();
}

/** Same, but for places where a real zero is the right default (counts of rows). */
export function formatCount(value: unknown): string {
  return formatNumber(value, "0");
}

/** Coerces to a number for arithmetic and comparisons. Never NaN. */
export function toNumber(value: unknown, fallback = 0): number {
  const numeric = typeof value === "string" ? Number(value) : value;

  return typeof numeric === "number" && Number.isFinite(numeric) ? numeric : fallback;
}

/** Money, with the currency the API reported. Falls back when either half is absent. */
export function formatMoney(
  price: { amount?: number | null; currency?: string | null } | null | undefined,
): string {
  if (!price || price.amount === null || price.amount === undefined) return "Price on request";

  return `${price.currency ?? "TZS"} ${formatNumber(price.amount, "—")}`;
}
