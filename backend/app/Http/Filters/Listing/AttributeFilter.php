<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Domain\Catalog\Enums\AttributeDataType;
use App\Models\Attribute;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Dynamic EAV filtering — the payoff of the multi-vertical design.
 *
 * `?attributes[beds][min]=2&attributes[fuel_type]=diesel` filters a Property by
 * bedrooms and a Vehicle by fuel type through the same code path, with no
 * per-vertical branching anywhere.
 *
 * Each attribute becomes its own EXISTS so multiple attributes AND together;
 * the typed value column is chosen from the attribute's data_type, which is
 * what keeps a range filter an index scan instead of a string comparison.
 */
class AttributeFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $requested = $query->filters->attributes;

        if ($requested === []) {
            return $next($query);
        }

        /** @var array<string, Attribute> $attributes */
        $attributes = Attribute::query()
            ->whereIn('code', array_keys($requested))
            ->get()
            ->keyBy('code')
            ->all();

        foreach ($requested as $code => $value) {
            $attribute = $attributes[$code] ?? null;

            // Unknown attribute codes are ignored rather than fatal: a stale
            // bookmark should not 500. Validation rejects them on write paths.
            if ($attribute === null || $value === null || $value === '') {
                continue;
            }

            $query->builder->whereExists(
                fn (QueryBuilder $q) => $this->constrain($q, $attribute, $value),
            );
        }

        return $next($query);
    }

    private function constrain(QueryBuilder $q, Attribute $attribute, mixed $value): QueryBuilder
    {
        $q->selectRaw('1')
            ->from('listing_attribute_values as lav')
            ->whereColumn('lav.listing_id', 'listings.id')
            ->where('lav.attribute_id', $attribute->id);

        $column = 'lav.'.$attribute->data_type->column();

        // Range: ['min' => x, 'max' => y]
        if (is_array($value) && (array_key_exists('min', $value) || array_key_exists('max', $value))) {
            if (isset($value['min']) && $value['min'] !== '') {
                $q->where($column, '>=', $this->cast($attribute, $value['min']));
            }

            if (isset($value['max']) && $value['max'] !== '') {
                $q->where($column, '<=', $this->cast($attribute, $value['max']));
            }

            return $q;
        }

        // Multi-select: any of these values.
        if (is_array($value)) {
            return $q->whereIn($column, array_map(
                fn ($v) => $this->cast($attribute, $v),
                array_values($value),
            ));
        }

        return $q->where($column, $this->cast($attribute, $value));
    }

    private function cast(Attribute $attribute, mixed $value): mixed
    {
        return match ($attribute->data_type) {
            AttributeDataType::Integer => (int) $value,
            AttributeDataType::Decimal => (float) $value,
            AttributeDataType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
