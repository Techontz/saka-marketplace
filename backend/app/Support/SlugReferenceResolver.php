<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Requests\Concerns\ResolvesSlugReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Turns the slugs the API publishes back into the ids the API writes.
 *
 * See {@see ResolvesSlugReferences} for why this
 * exists. The logic lives here rather than in the trait so controllers that
 * validate inline — the vendor profile is one — can reuse it without being a
 * FormRequest.
 */
final class SlugReferenceResolver
{
    /**
     * Resolve `<name>_slug` inputs into their `<name>_id` counterparts.
     *
     * An id already in the payload wins: a client that sends both meant the id.
     * An explicit `null` slug clears the id, which is how a nullable reference
     * is unset.
     *
     * @param  array<string, class-string<Model>>  $map  id field => model
     */
    public static function resolve(Request $request, array $map): void
    {
        $merge = [];

        foreach ($map as $idField => $model) {
            $slugField = str_replace('_id', '_slug', $idField);

            if ($request->filled($idField) || ! $request->exists($slugField)) {
                continue;
            }

            $slug = $request->input($slugField);

            if ($slug === null || $slug === '') {
                $merge[$idField] = null;

                continue;
            }

            if (! is_string($slug)) {
                continue;
            }

            $id = $model::query()->where('slug', $slug)->value('id');

            // An unresolvable slug is left alone rather than nulled, so the
            // validator reports it against the field the client actually sent.
            if ($id !== null) {
                $merge[$idField] = $id;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /**
     * Resolve a list that may hold slugs, ids, or a mix of both.
     *
     * @param  class-string<Model>  $model
     */
    public static function resolveList(Request $request, string $field, string $model): void
    {
        $values = $request->input($field);

        if (! is_array($values) || $values === []) {
            return;
        }

        $slugs = array_values(array_filter(
            $values,
            static fn ($value): bool => is_string($value) && ! ctype_digit($value),
        ));

        if ($slugs === []) {
            return;
        }

        /** @var array<string, int> $ids */
        $ids = $model::query()->whereIn('slug', $slugs)->pluck('id', 'slug')->all();

        $request->merge([
            $field => array_values(array_map(
                static fn ($value) => is_string($value) && isset($ids[$value]) ? $ids[$value] : $value,
                $values,
            )),
        ]);
    }
}
