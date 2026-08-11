<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Listing;

use App\Services\Listing\LandBoundaryService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validating a hand-drawn parcel.
 *
 * The shape checks matter more than the field checks here. A polygon that
 * passes `array|min:3` can still be a bow tie, a line, or a rectangle spanning
 * two countries — and each of those produces a plausible-looking area that is
 * wrong. They are rejected at the door rather than stored and shown.
 */
class StoreListingBoundaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the controller's route binding, which only
        // resolves listings belonging to the authenticated seller.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxVertices = (int) config('saka.listings.boundary_max_vertices', 500);

        return [
            'rings' => ['required', 'array', 'min:1', 'max:10'],
            'rings.*' => ['array', 'min:3', 'max:'.$maxVertices],
            'rings.*.*' => ['array', 'size:2'],

            // GeoJSON order: longitude first. Getting this backwards puts every
            // Tanzanian parcel in the Indian Ocean off Somalia, so the ranges
            // are asserted rather than assumed.
            'rings.*.*.0' => ['required', 'numeric', 'between:-180,180'],
            'rings.*.*.1' => ['required', 'numeric', 'between:-90,90'],

            'survey_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rings.*.min' => 'A land boundary needs at least three corners.',
            'rings.*.*.0.between' => 'A boundary corner has an invalid longitude.',
            'rings.*.*.1.between' => 'A boundary corner has an invalid latitude.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only run geometry checks once the field rules passed; otherwise
            // the service would be measuring malformed input.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = app(LandBoundaryService::class);
            $rings = (array) $this->input('rings', []);

            foreach ($rings as $index => $ring) {
                if ($service->selfIntersects((array) $ring)) {
                    $validator->errors()->add(
                        "rings.{$index}",
                        'The boundary crosses itself. Move the corners so the outline does not overlap.',
                    );
                }
            }

            // Measure the CLOSED ring — what would actually be stored. A raw
            // three-corner request has no closing point and would measure zero.
            $outer = $service->normaliseRing((array) ($rings[0] ?? []));

            if ($outer === []) {
                $validator->errors()->add('rings.0', 'A land boundary needs at least three distinct corners.');

                return;
            }

            $metrics = $service->measure([$outer]);

            /*
             * A zero-area outline is three points in a line: it draws as a
             * stroke with nothing inside, and reports "0 m²". That is the
             * "maps render only lines" failure in a different disguise, and it
             * is worth catching here rather than letting a buyer see it.
             */
            if ($metrics['area_sqm'] < 1.0) {
                $validator->errors()->add(
                    'rings.0',
                    'That outline encloses no area. The corners may be in a straight line.',
                );
            }

            // 100 km² is roughly a quarter of Dar es Salaam. Anything larger is
            // a misplaced corner, not a plot for sale.
            if ($metrics['area_sqm'] > 100_000_000) {
                $validator->errors()->add(
                    'rings.0',
                    'That outline is too large to be a single parcel. Check for a corner in the wrong place.',
                );
            }
        });
    }
}
