"use client";

/**
 * Price, as brackets rather than two empty number boxes.
 *
 * Min/Max inputs ask the customer a question they cannot answer: nobody
 * browsing plots in Kigamboni knows whether to type 50,000,000 or 500,000,000,
 * so the field stays empty and the filter goes unused. A row of brackets turns
 * that into one tap and, more usefully, TELLS them what the market costs
 * before they have to guess.
 *
 * ── WHY THE BRACKETS ARE PER VERTICAL, NOT ONE LADDER ─────────────────────
 * A single scale cannot serve a 45,000 TZS phone case and a 2.4 billion TZS
 * hotel. Each vertical gets brackets drawn from what it actually sells, and
 * rentals get their own set because a monthly rent and a sale price differ by
 * three orders of magnitude in the same category.
 *
 * ── WHY NOT COMPUTE THEM FROM THE DATA ────────────────────────────────────
 * Deriving quantiles from live listings was the first idea and is worse: the
 * brackets would move under the customer as the catalogue changes, two people
 * would see different filters, and a shareable URL would stop meaning the same
 * thing. These are fixed, legible round numbers — which is what makes them
 * scannable.
 *
 * The chips only FILL the inputs. Manual entry keeps working, and a manual
 * value that happens to match a bracket lights that bracket up.
 */

export type PriceBracket = {
  label: string;
  /** Minor units, matching the API. `null` means unbounded on that side. */
  min: number | null;
  max: number | null;
};

const M = 1_000_000;
const K = 1_000;

/** Sale prices: land, houses, cars, plant. */
const SALE: PriceBracket[] = [
  { label: "Under 50M", min: null, max: 50 * M },
  { label: "50M – 100M", min: 50 * M, max: 100 * M },
  { label: "100M – 250M", min: 100 * M, max: 250 * M },
  { label: "250M – 500M", min: 250 * M, max: 500 * M },
  { label: "500M – 1B", min: 500 * M, max: 1000 * M },
  { label: "1B+", min: 1000 * M, max: null },
];

/** Monthly rent and lease. */
const RENT: PriceBracket[] = [
  { label: "Under 300K", min: null, max: 300 * K },
  { label: "300K – 500K", min: 300 * K, max: 500 * K },
  { label: "500K – 1M", min: 500 * K, max: 1 * M },
  { label: "1M – 2M", min: 1 * M, max: 2 * M },
  { label: "2M – 5M", min: 2 * M, max: 5 * M },
  { label: "5M+", min: 5 * M, max: null },
];

const VEHICLES: PriceBracket[] = [
  { label: "Under 5M", min: null, max: 5 * M },
  { label: "5M – 15M", min: 5 * M, max: 15 * M },
  { label: "15M – 30M", min: 15 * M, max: 30 * M },
  { label: "30M – 60M", min: 30 * M, max: 60 * M },
  { label: "60M – 100M", min: 60 * M, max: 100 * M },
  { label: "100M+", min: 100 * M, max: null },
];

/** Phones, laptops, TVs — and, at the same scale, furniture and fashion. */
const RETAIL: PriceBracket[] = [
  { label: "Under 100K", min: null, max: 100 * K },
  { label: "100K – 300K", min: 100 * K, max: 300 * K },
  { label: "300K – 700K", min: 300 * K, max: 700 * K },
  { label: "700K – 1.5M", min: 700 * K, max: 1.5 * M },
  { label: "1.5M – 3M", min: 1.5 * M, max: 3 * M },
  { label: "3M+", min: 3 * M, max: null },
];

const SMALL_RETAIL: PriceBracket[] = [
  { label: "Under 30K", min: null, max: 30 * K },
  { label: "30K – 75K", min: 30 * K, max: 75 * K },
  { label: "75K – 150K", min: 75 * K, max: 150 * K },
  { label: "150K – 350K", min: 150 * K, max: 350 * K },
  { label: "350K – 750K", min: 350 * K, max: 750 * K },
  { label: "750K+", min: 750 * K, max: null },
];

/** Monthly salary. */
const SALARY: PriceBracket[] = [
  { label: "Under 500K", min: null, max: 500 * K },
  { label: "500K – 1M", min: 500 * K, max: 1 * M },
  { label: "1M – 2M", min: 1 * M, max: 2 * M },
  { label: "2M – 3.5M", min: 2 * M, max: 3.5 * M },
  { label: "3.5M – 5M", min: 3.5 * M, max: 5 * M },
  { label: "5M+", min: 5 * M, max: null },
];

/** Service call-outs and day rates. */
const SERVICES: PriceBracket[] = [
  { label: "Under 50K", min: null, max: 50 * K },
  { label: "50K – 150K", min: 50 * K, max: 150 * K },
  { label: "150K – 500K", min: 150 * K, max: 500 * K },
  { label: "500K – 2M", min: 500 * K, max: 2 * M },
  { label: "2M+", min: 2 * M, max: null },
];

const BY_VERTICAL: Record<string, PriceBracket[]> = {
  property: SALE,
  vehicles: VEHICLES,
  electronics: RETAIL,
  furniture: RETAIL,
  fashion: SMALL_RETAIL,
  jobs: SALARY,
  services: SERVICES,
  agriculture: RETAIL,
  pets: RETAIL,
  construction: RETAIL,
  industrial: SALE,
};

/**
 * The right ladder for what is being browsed.
 *
 * Purpose beats category: a house to RENT belongs on the rent ladder, not the
 * 1-billion sale ladder, and getting that wrong makes every bracket useless on
 * the largest vertical in the catalogue.
 */
export function bracketsFor(vertical: string | undefined, purpose: string | undefined): PriceBracket[] {
  if (purpose === "rent" || purpose === "lease") return RENT;
  if (purpose === "hire") return SERVICES;

  return BY_VERTICAL[vertical ?? ""] ?? SALE;
}

export function PriceRangeChips({
  vertical,
  purpose,
  min,
  max,
  onSelect,
  currency = "TZS",
}: {
  vertical: string | undefined;
  purpose: string | undefined;
  /** Current draft values, as strings, so a chip can show itself as active. */
  min: string;
  max: string;
  onSelect: (min: string, max: string) => void;
  currency?: string;
}) {
  const brackets = bracketsFor(vertical, purpose);

  const isActive = (bracket: PriceBracket) =>
    String(bracket.min ?? "") === min.trim() && String(bracket.max ?? "") === max.trim();

  return (
    <div>
      <div className="flex flex-wrap gap-2">
        {brackets.map((bracket) => {
          const active = isActive(bracket);

          return (
            <button
              key={bracket.label}
              type="button"
              aria-pressed={active}
              onClick={() =>
                // Tapping the active chip clears it — otherwise the only way
                // out of a bracket is to empty two number fields by hand.
                active
                  ? onSelect("", "")
                  : onSelect(String(bracket.min ?? ""), String(bracket.max ?? ""))
              }
              className={`rounded-full border px-3 py-1.5 text-[13px] font-semibold transition ${
                active
                  ? "border-teal bg-teal text-white"
                  : "border-border bg-white text-navy hover:border-teal hover:text-teal"
              }`}
            >
              {bracket.label}
            </button>
          );
        })}
      </div>

      <p className="mt-2 text-xs text-muted-foreground">
        Prices in {currency}. Tap a range or type your own below.
      </p>
    </div>
  );
}
