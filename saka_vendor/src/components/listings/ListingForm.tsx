"use client";

import { useQuery } from "@tanstack/react-query";
import { useMemo } from "react";

import { Checkbox, Field, Input, Select, Textarea } from "@/components/ui";
import { apiGet } from "@/lib/api/browser";
import type {
  Attribute,
  BusinessType,
  Category,
  Envelope,
  Location,
  TaxonomyTerm,
} from "@/lib/api/types";

/**
 * The listing form, shared by create and edit.
 *
 * TWO THINGS MAKE THIS MULTI-VERTICAL:
 *
 * 1. The category picker is PRE-FILTERED by the vendor's business type — a car
 *    dealer sees Vehicles first, a landlord sees Property. It is a filter, not
 *    a restriction: a hotel that also sells furniture is a real business, and
 *    "show everything" stays one click away.
 *
 * 2. The attribute fields are fetched FOR THE CHOSEN CATEGORY. A vehicle asks
 *    for mileage and fuel type; a flat asks for bedrooms and floor area. There
 *    is no per-vertical branching here at all — the API returns the field set,
 *    and this renders whatever it is given.
 *
 * Everything here is addressed by SLUG. The catalog and location endpoints
 * publish slugs and never numeric ids, so a form built on ids could not have
 * populated a single dropdown; the write endpoints accept the same slugs.
 */

export type ListingDraft = {
  title: string;
  description: string;
  category_slug: string;
  purpose: string;
  price: string;
  currency: string;
  price_unit: string;
  is_negotiable: boolean;
  condition: string;
  region_slug: string;
  district_slug: string;
  ward_slug: string;
  address_line: string;
  available_from: string;
  attributes: Record<string, string>;
  amenities: string[];
  facilities: string[];
};

export const EMPTY_LISTING: ListingDraft = {
  title: "",
  description: "",
  category_slug: "",
  purpose: "sale",
  price: "",
  currency: "TZS",
  price_unit: "total",
  is_negotiable: false,
  condition: "used",
  region_slug: "",
  district_slug: "",
  ward_slug: "",
  address_line: "",
  available_from: "",
  attributes: {},
  amenities: [],
  facilities: [],
};

const PURPOSES = ["sale", "rent", "lease"];
const PRICE_UNITS = ["total", "monthly", "yearly", "daily", "hourly", "negotiable"];
const CONDITIONS = ["new", "used", "refurbished"];

export function listingPayload(draft: ListingDraft): Record<string, unknown> {
  return {
    title: draft.title,
    description: draft.description || null,
    category_slug: draft.category_slug,
    purpose: draft.purpose,
    price: draft.price === "" ? null : Number(draft.price),
    currency: draft.currency,
    price_unit: draft.price_unit,
    is_negotiable: draft.is_negotiable,
    condition: draft.condition,
    region_slug: draft.region_slug || null,
    district_slug: draft.district_slug || null,
    ward_slug: draft.ward_slug || null,
    address_line: draft.address_line || null,
    available_from: draft.available_from || null,
    // Empty values are stripped: sending "" for an unfilled number would fail
    // validation on a field the vendor deliberately left blank.
    attributes: Object.fromEntries(
      Object.entries(draft.attributes).filter(([, value]) => value !== "" && value !== null),
    ),
    amenities: draft.amenities,
    facilities: draft.facilities,
  };
}

export function ListingFields({
  draft,
  onChange,
  businessType,
}: {
  draft: ListingDraft;
  onChange: (patch: Partial<ListingDraft>) => void;
  businessType: BusinessType | null;
}) {
  const categories = useQuery({
    queryKey: ["categories"],
    queryFn: () => apiGet<Envelope<Category[]>>("/categories").then((r) => r.data),
    staleTime: 60 * 60 * 1000,
  });

  const regions = useQuery({
    queryKey: ["locations", "regions"],
    queryFn: () => apiGet<Envelope<Location[]>>("/locations/regions").then((r) => r.data),
    staleTime: 60 * 60 * 1000,
  });

  const amenities = useQuery({
    queryKey: ["amenities"],
    queryFn: () => apiGet<Envelope<TaxonomyTerm[]>>("/amenities").then((r) => r.data),
    staleTime: 60 * 60 * 1000,
  });

  const facilities = useQuery({
    queryKey: ["facilities"],
    queryFn: () => apiGet<Envelope<TaxonomyTerm[]>>("/facilities").then((r) => r.data),
    staleTime: 60 * 60 * 1000,
  });

  /**
   * Leaf categories only — listings attach to leaves, never to a vertical.
   * Ordered so the vendor's own trade comes first.
   */
  const leafOptions = useMemo(() => {
    const roots = categories.data ?? [];
    const preferred = new Set(businessType?.category_slugs ?? []);

    const ordered = [...roots].sort((a, b) => {
      const aPreferred = preferred.has(a.slug) ? 0 : 1;
      const bPreferred = preferred.has(b.slug) ? 0 : 1;
      return aPreferred - bPreferred;
    });

    return ordered.map((root) => ({
      root,
      children: (root.children ?? []).filter((child) => child.is_leaf),
      preferred: preferred.has(root.slug),
    }));
  }, [categories.data, businessType]);

  // The chosen category decides which attribute fields exist.
  const selectedCategory = useMemo(() => {
    for (const group of leafOptions) {
      const match = group.children.find((child) => child.slug === draft.category_slug);
      if (match) return match;
    }
    return null;
  }, [leafOptions, draft.category_slug]);

  const attributes = useQuery({
    queryKey: ["category-attributes", selectedCategory?.slug],
    queryFn: () =>
      apiGet<Envelope<Attribute[]>>(`/categories/${selectedCategory!.slug}/attributes`).then(
        (r) => r.data,
      ),
    enabled: Boolean(selectedCategory?.slug),
  });

  const districts = useQuery({
    queryKey: ["locations", "districts", draft.region_slug],
    queryFn: () =>
      apiGet<Envelope<Location[]>>(`/locations/regions/${draft.region_slug}/districts`).then(
        (r) => r.data,
      ),
    enabled: Boolean(draft.region_slug),
  });

  return (
    <div className="space-y-6">
      <section className="space-y-4">
        <Field label="Title" required>
          <Input
            value={draft.title}
            onChange={(event) => onChange({ title: event.target.value })}
            placeholder="What are you listing?"
            maxLength={191}
          />
        </Field>

        <Field label="Category" required hint="Decides which details buyers can filter on.">
          <Select
            value={draft.category_slug}
            onChange={(event) =>
              // Changing the category invalidates the attribute values — they
              // belong to the old category's field set.
              onChange({ category_slug: event.target.value, attributes: {} })
            }
          >
            <option value="">Choose a category…</option>
            {leafOptions.map((group) => (
              <optgroup
                key={group.root.slug}
                label={group.preferred ? `${group.root.name} (your trade)` : group.root.name}
              >
                {group.children.map((child) => (
                  <option key={child.slug} value={child.slug}>
                    {child.name}
                  </option>
                ))}
              </optgroup>
            ))}
          </Select>
        </Field>

        <Field label="Description" hint="What makes it worth choosing? Be specific.">
          <Textarea
            rows={6}
            value={draft.description}
            onChange={(event) => onChange({ description: event.target.value })}
          />
        </Field>
      </section>

      <section className="space-y-4 border-t border-line pt-5">
        <p className="text-[13px] font-medium text-ink">Price</p>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Amount">
            <Input
              type="number"
              inputMode="numeric"
              min="0"
              value={draft.price}
              onChange={(event) => onChange({ price: event.target.value })}
            />
          </Field>

          <Field label="Per">
            <Select
              value={draft.price_unit}
              onChange={(event) => onChange({ price_unit: event.target.value })}
            >
              {PRICE_UNITS.map((unit) => (
                <option key={unit} value={unit}>
                  {unit === "total" ? "Total" : unit.charAt(0).toUpperCase() + unit.slice(1)}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Purpose">
            <Select
              value={draft.purpose}
              onChange={(event) => onChange({ purpose: event.target.value })}
            >
              {PURPOSES.map((purpose) => (
                <option key={purpose} value={purpose}>
                  {purpose.charAt(0).toUpperCase() + purpose.slice(1)}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <div className="flex flex-wrap items-end gap-6">
          <Checkbox
            label="Price is negotiable"
            checked={draft.is_negotiable}
            onChange={(event) => onChange({ is_negotiable: event.target.checked })}
          />

          <div className="w-[180px]">
            <Field label="Condition">
              <Select
                value={draft.condition}
                onChange={(event) => onChange({ condition: event.target.value })}
              >
                {CONDITIONS.map((condition) => (
                  <option key={condition} value={condition}>
                    {condition.charAt(0).toUpperCase() + condition.slice(1)}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
        </div>
      </section>

      <section className="space-y-4 border-t border-line pt-5">
        <p className="text-[13px] font-medium text-ink">Where is it?</p>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Region">
            <Select
              value={draft.region_slug}
              onChange={(event) =>
                onChange({ region_slug: event.target.value, district_slug: "", ward_slug: "" })
              }
            >
              <option value="">Choose…</option>
              {(regions.data ?? []).map((region) => (
                <option key={region.slug} value={region.slug}>
                  {region.name}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="District">
            <Select
              value={draft.district_slug}
              disabled={!draft.region_slug}
              onChange={(event) => onChange({ district_slug: event.target.value, ward_slug: "" })}
            >
              <option value="">{draft.region_slug ? "Choose…" : "Pick a region first"}</option>
              {(districts.data ?? []).map((district) => (
                <option key={district.slug} value={district.slug}>
                  {district.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        <Field label="Address or neighbourhood">
          <Input
            value={draft.address_line}
            onChange={(event) => onChange({ address_line: event.target.value })}
            placeholder="e.g. Masaki"
          />
        </Field>
      </section>

      {/*
        The dynamic part. Whatever the API says this category needs, rendered as
        it comes — no per-vertical code here at all.
      */}
      {selectedCategory && (
        <section className="space-y-4 border-t border-line pt-5">
          <p className="text-[13px] font-medium text-ink">{selectedCategory.name} details</p>

          {attributes.isPending ? (
            <div className="h-20 animate-pulse rounded bg-muted-soft/50" />
          ) : (attributes.data?.length ?? 0) === 0 ? (
            <p className="text-sm text-ink-soft">
              No extra details are needed for this category.
            </p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2">
              {(attributes.data ?? []).map((attribute) => (
                <Field
                  key={attribute.code}
                  label={attribute.name + (attribute.unit ? ` (${attribute.unit})` : "")}
                  required={attribute.is_required}
                >
                  <AttributeInput
                    attribute={attribute}
                    value={draft.attributes[attribute.code] ?? ""}
                    onChange={(value) =>
                      onChange({ attributes: { ...draft.attributes, [attribute.code]: value } })
                    }
                  />
                </Field>
              ))}
            </div>
          )}
        </section>
      )}

      <section className="space-y-4 border-t border-line pt-5">
        <p className="text-[13px] font-medium text-ink">Amenities</p>
        <TermPicker
          terms={amenities.data ?? []}
          selected={draft.amenities}
          onChange={(next) => onChange({ amenities: next })}
        />

        <p className="pt-2 text-[13px] font-medium text-ink">Nearby facilities</p>
        <TermPicker
          terms={facilities.data ?? []}
          selected={draft.facilities}
          onChange={(next) => onChange({ facilities: next })}
        />
      </section>
    </div>
  );
}

function AttributeInput({
  attribute,
  value,
  onChange,
}: {
  attribute: Attribute;
  value: string;
  onChange: (value: string) => void;
}) {
  if (attribute.input_type === "select" || attribute.input_type === "multiselect") {
    return (
      <Select value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">Choose…</option>
        {(attribute.options ?? []).map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </Select>
    );
  }

  if (attribute.input_type === "boolean") {
    return (
      <Select value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">Not specified</option>
        <option value="1">Yes</option>
        <option value="0">No</option>
      </Select>
    );
  }

  return (
    <Input
      type={attribute.input_type === "number" ? "number" : attribute.input_type === "date" ? "date" : "text"}
      inputMode={attribute.input_type === "number" ? "numeric" : undefined}
      value={value}
      onChange={(event) => onChange(event.target.value)}
    />
  );
}

function TermPicker({
  terms,
  selected,
  onChange,
}: {
  terms: TaxonomyTerm[];
  selected: string[];
  onChange: (next: string[]) => void;
}) {
  if (terms.length === 0) {
    return <p className="text-sm text-ink-faint">None available.</p>;
  }

  return (
    <div className="flex flex-wrap gap-1.5">
      {terms.map((term) => {
        const active = selected.includes(term.slug);

        return (
          <button
            key={term.slug}
            type="button"
            aria-pressed={active}
            onClick={() =>
              onChange(
                active ? selected.filter((slug) => slug !== term.slug) : [...selected, term.slug],
              )
            }
            className={
              "rounded-full border px-3 py-1 text-[13px] transition-colors " +
              (active
                ? "border-brand bg-brand-soft text-brand-ink"
                : "border-line text-ink-soft hover:border-line-strong hover:text-ink")
            }
          >
            {term.name}
          </button>
        );
      })}
    </div>
  );
}
