<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\ListingBoundary;
use App\Services\Listing\LandBoundaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingBoundary
 *
 * A parcel outline, in the two shapes a client actually needs: the raw rings
 * for drawing it on a map, and pre-formatted measurements for showing it to a
 * human.
 *
 * `area.display` is computed server-side on purpose. Tanzanian land is quoted
 * in acres below ten and hectares above, and every client re-implementing that
 * rule is how two surfaces end up disagreeing about the size of the same plot.
 */
class ListingBoundaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $areas = app(LandBoundaryService::class);

        return [
            // GeoJSON winding: [[[lng, lat], ...]], outer ring first.
            'rings' => $this->rings,

            'area' => $areas->areaSummary((float) $this->area_sqm),

            'perimeter_m' => round((float) $this->perimeter_m, 2),
            'perimeter_display' => $this->perimeter_m >= 1000
                ? number_format((float) $this->perimeter_m / 1000, 2).' km'
                : number_format((float) $this->perimeter_m, 0).' m',

            'vertex_count' => max(0, count($this->outerRing()) - 1),

            'centroid' => $this->centroid_latitude !== null ? [
                'latitude' => (float) $this->centroid_latitude,
                'longitude' => (float) $this->centroid_longitude,
            ] : null,

            'bounds' => $this->min_latitude !== null ? [
                'min_latitude' => (float) $this->min_latitude,
                'max_latitude' => (float) $this->max_latitude,
                'min_longitude' => (float) $this->min_longitude,
                'max_longitude' => (float) $this->max_longitude,
            ] : null,

            'survey_reference' => $this->survey_reference,
            'notes' => $this->notes,

            // Ready to hand to any GIS tool without the client knowing our
            // storage shape.
            'geojson' => $this->toGeoJson(),

            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
