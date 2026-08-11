<?php

declare(strict_types=1);

namespace App\Http\Filters\Listing;

use App\Models\Category;
use Closure;

/**
 * Category browse, subtree-aware.
 *
 * Filtering on "Property" must include Apartments, Houses and every other leaf
 * beneath it. The materialised path makes that a single indexed LIKE rather
 * than a recursive CTE on the hot browse path.
 *
 * A subcategory, when supplied, wins — it is the more specific intent.
 */
class CategoryFilter
{
    public function __invoke(ListingQuery $query, Closure $next): ListingQuery
    {
        $slug = $query->filters->subcategorySlug ?? $query->filters->categorySlug;

        if ($slug === null) {
            return $next($query);
        }

        $category = Category::query()->where('slug', $slug)->first();

        if ($category === null) {
            // Unknown category yields an empty result rather than silently
            // returning everything — a typo must not look like "no filter".
            $query->builder->whereRaw('1 = 0');

            return $next($query);
        }

        $query->builder->whereIn(
            'listings.category_id',
            Category::query()
                ->where('id', $category->id)
                ->orWhere('path', 'like', $category->path.'/%')
                ->select('id'),
        );

        return $next($query);
    }
}
