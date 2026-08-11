"use client";

import { useQuery } from "@tanstack/react-query";

import { apiGet } from "@/lib/api/browser";
import type { ApiAttribute } from "@/lib/types";

/**
 * Filters generated from whatever the selected category defines.
 *
 * This replaces the hardcoded Beds / Baths / Area boxes, which assumed every
 * listing was a flat. A marketplace that also sells cars, phones and labour
 * cannot ask "how many bathrooms" — and equally must not lose "transmission"
 * or "storage" just because nobody wrote a control for them.
 *
 * Nothing here knows a single attribute code. Each definition arrives from
 * `GET /categories/{slug}/attributes` carrying its own `input_type`, and a
 * control is chosen from that:
 *
 *   select  → radio list, one option per value
 *   boolean → single checkbox
 *   number  → min/max pair, because ranges are what people filter numbers by
 *   text    → a plain box (make, model, brand)
 *
 * Only `is_filterable` attributes appear: `warranty_months` and `service_area`
 * are real data but the API will not filter on them, and offering a control
 * that silently does nothing is worse than offering none.
 *
 * The query shape is the API's own: `attributes[code]` for an exact match and
 * `attributes[code][min|max]` for a range.
 */

export const ATTRIBUTE_PREFIX = "attributes";

/** URL key for an attribute filter, e.g. `attributes[mileage][max]`. */
export function attributeKey(code: string, bound?: "min" | "max"): string {
  return bound ? `${ATTRIBUTE_PREFIX}[${code}][${bound}]` : `${ATTRIBUTE_PREFIX}[${code}]`;
}

export function useCategoryAttributes(categorySlug: string | undefined) {
  return useQuery({
    queryKey: ["category-attributes", categorySlug],
    queryFn: () => apiGet<{ data: ApiAttribute[] }>(`/categories/${categorySlug}/attributes`),
    enabled: Boolean(categorySlug),
    // Taxonomy changes about as often as a deploy; there is no reason to
    // refetch it while somebody is adjusting filters.
    staleTime: 60 * 60 * 1000,
  });
}

function optionValue(option: string | { value: string; label?: string }): string {
  return typeof option === "string" ? option : option.value;
}

function optionLabel(option: string | { value: string; label?: string }): string {
  const raw = typeof option === "string" ? option : (option.label ?? option.value);
  return raw.charAt(0).toUpperCase() + raw.slice(1).replace(/[-_]/g, " ");
}

export function CategoryFilters({
  categorySlug,
  values,
  onChange,
  FilterBox,
}: {
  categorySlug: string | undefined;
  /** Current URL values, keyed exactly as {@link attributeKey} produces. */
  values: Record<string, string>;
  onChange: (patch: Record<string, string | null>) => void;
  FilterBox: React.ComponentType<{ title: string; children: React.ReactNode }>;
}) {
  const { data, isPending } = useCategoryAttributes(categorySlug);

  if (!categorySlug) {
    return (
      <FilterBox title="More filters">
        <p className="text-sm text-muted-foreground">
          Pick a category to filter on its own details — bedrooms for property,
          mileage for vehicles, storage for phones.
        </p>
      </FilterBox>
    );
  }

  if (isPending) {
    return (
      <FilterBox title="More filters">
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, index) => (
            <div key={index} className="h-9 animate-pulse rounded bg-page" />
          ))}
        </div>
      </FilterBox>
    );
  }

  const filterable = (data?.data ?? []).filter((attribute) => attribute.is_filterable);

  if (filterable.length === 0) {
    return (
      <FilterBox title="More filters">
        <p className="text-sm text-muted-foreground">
          This category has no extra filters yet.
        </p>
      </FilterBox>
    );
  }

  return (
    <>
      {filterable.map((attribute) => {
        const title = attribute.unit ? `${attribute.name} (${attribute.unit})` : attribute.name;

        // ---- select: one choice, clearable by re-clicking -----------------
        if (attribute.input_type === "select" && attribute.options.length > 0) {
          const key = attributeKey(attribute.code);
          const current = values[key] ?? "";

          return (
            <FilterBox key={attribute.code} title={title}>
              <div className="space-y-3">
                {attribute.options.map((option) => {
                  const value = optionValue(option);

                  return (
                    <label
                      key={value}
                      className="flex cursor-pointer items-center gap-3 text-sm text-navy"
                    >
                      <input
                        type="radio"
                        name={attribute.code}
                        checked={current === value}
                        onChange={() => onChange({ [key]: current === value ? null : value })}
                        className="h-4 w-4 accent-teal"
                      />
                      {optionLabel(option)}
                    </label>
                  );
                })}
              </div>
            </FilterBox>
          );
        }

        // ---- boolean ------------------------------------------------------
        if (attribute.input_type === "boolean") {
          const key = attributeKey(attribute.code);
          const checked = values[key] === "1";

          return (
            <FilterBox key={attribute.code} title={title}>
              <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm text-navy">
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={() => onChange({ [key]: checked ? null : "1" })}
                  className="h-4 w-4 accent-teal"
                />
                {attribute.name}
              </label>
            </FilterBox>
          );
        }

        // ---- number: a range ----------------------------------------------
        if (attribute.input_type === "number") {
          const minKey = attributeKey(attribute.code, "min");
          const maxKey = attributeKey(attribute.code, "max");

          return (
            <FilterBox key={attribute.code} title={title}>
              <div className="flex items-center gap-2">
                <input
                  type="number"
                  inputMode="numeric"
                  min={attribute.min_value ?? undefined}
                  max={attribute.max_value ?? undefined}
                  value={values[minKey] ?? ""}
                  onChange={(event) => onChange({ [minKey]: event.target.value || null })}
                  placeholder="Min"
                  aria-label={`Minimum ${attribute.name}`}
                  className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
                />
                <span className="text-muted-foreground">–</span>
                <input
                  type="number"
                  inputMode="numeric"
                  min={attribute.min_value ?? undefined}
                  max={attribute.max_value ?? undefined}
                  value={values[maxKey] ?? ""}
                  onChange={(event) => onChange({ [maxKey]: event.target.value || null })}
                  placeholder="Max"
                  aria-label={`Maximum ${attribute.name}`}
                  className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
                />
              </div>
            </FilterBox>
          );
        }

        // ---- text ----------------------------------------------------------
        const key = attributeKey(attribute.code);

        return (
          <FilterBox key={attribute.code} title={title}>
            <input
              value={values[key] ?? ""}
              onChange={(event) => onChange({ [key]: event.target.value || null })}
              placeholder={`Any ${attribute.name.toLowerCase()}`}
              aria-label={attribute.name}
              className="w-full rounded-[5px] border border-border px-3 py-2 outline-none focus:border-teal"
            />
          </FilterBox>
        );
      })}
    </>
  );
}
