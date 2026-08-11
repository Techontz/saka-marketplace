<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\SlugReferenceResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets write endpoints accept the same slugs the read endpoints hand out.
 *
 * The public API is slug-addressed by design: categories, regions, districts,
 * wards, amenities and facilities are all published by slug and their numeric
 * ids are never exposed. Writes, however, were specified in terms of foreign
 * keys — so a client that had only ever read from this API had no way to
 * discover the id a write demanded.
 *
 * Rather than leak ids into every public payload, the write side accepts either
 * form: a `*_slug` is resolved to its `*_id` before validation, and lists of
 * taxonomy terms accept slugs alongside ids. Ids keep working unchanged.
 */
trait ResolvesSlugReferences
{
    /**
     * @param  array<string, class-string<Model>>  $map  id field => model
     */
    protected function resolveSlugReferences(array $map): void
    {
        SlugReferenceResolver::resolve($this, $map);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function resolveSlugList(string $field, string $model): void
    {
        SlugReferenceResolver::resolveList($this, $field, $model);
    }
}
