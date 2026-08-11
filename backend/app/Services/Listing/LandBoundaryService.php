<?php

declare(strict_types=1);

namespace App\Services\Listing;

use App\Models\Listing;
use App\Models\ListingBoundary;
use Illuminate\Support\Facades\DB;

/**
 * Land parcel geometry: normalise it, measure it, store it.
 *
 * Every derived number a buyer sees — area, perimeter, centroid — is computed
 * HERE from the coordinates the seller drew. None of them is accepted from the
 * request. A marketplace where the advertised acreage is a free-text field the
 * seller controls is a marketplace with a systematic misrepresentation problem.
 *
 * MEASUREMENT
 * -----------
 * Area uses the spherical-excess formula, which is exact on a sphere and
 * within a few parts in 10^3 of the true ellipsoidal area at these latitudes —
 * far tighter than the accuracy of a boundary traced by hand on satellite
 * imagery, which is what the seller is actually doing. Perimeter is the
 * haversine sum of the edges.
 *
 * A planar shoelace on raw degrees was the obvious alternative and is wrong:
 * a degree of longitude is ~110 km at the equator and ~0 at the pole, so it
 * over-reports every parcel by 1/cos(latitude) unless it is corrected — and
 * once corrected it is the same amount of code as doing it properly.
 */
class LandBoundaryService
{
    /** Mean Earth radius, metres (IUGG). */
    private const EARTH_RADIUS_M = 6_371_008.8;

    private const SQM_PER_ACRE = 4046.8564224;

    private const SQM_PER_HECTARE = 10_000.0;

    /**
     * Create or replace a listing's parcel outline.
     *
     * @param  array<int, array<int, array{0: float|int|string, 1: float|int|string}>>  $rings
     */
    public function save(
        Listing $listing,
        array $rings,
        ?string $surveyReference = null,
        ?string $notes = null,
    ): ListingBoundary {
        $normalised = array_values(array_filter(
            array_map(fn (array $ring) => $this->normaliseRing($ring), $rings),
            static fn (array $ring) => count($ring) >= 4,
        ));

        if ($normalised === []) {
            throw new \InvalidArgumentException('A boundary needs at least three distinct corners.');
        }

        $outer = $normalised[0];
        $metrics = $this->measure($normalised);

        return DB::transaction(function () use ($listing, $normalised, $outer, $metrics, $surveyReference, $notes) {
            $boundary = ListingBoundary::firstOrNew(['listing_id' => $listing->getKey()]);

            $boundary->fill([
                'listing_id' => $listing->getKey(),
                'rings' => $normalised,
                'survey_reference' => $surveyReference,
                'notes' => $notes,
            ]);

            // Guarded columns: assigned explicitly so they can only ever be set
            // from geometry this class measured.
            $boundary->area_sqm = round($metrics['area_sqm'], 2);
            $boundary->perimeter_m = round($metrics['perimeter_m'], 2);
            $boundary->centroid_latitude = round($metrics['centroid'][1], 7);
            $boundary->centroid_longitude = round($metrics['centroid'][0], 7);
            $boundary->min_latitude = round($metrics['bbox']['min_lat'], 7);
            $boundary->max_latitude = round($metrics['bbox']['max_lat'], 7);
            $boundary->min_longitude = round($metrics['bbox']['min_lng'], 7);
            $boundary->max_longitude = round($metrics['bbox']['max_lng'], 7);

            $boundary->save();

            /*
             * A parcel with no pin is unreachable: it would not appear on the
             * map, in a radius search, or behind a Directions link. The
             * centroid is the obvious pin, so adopt it — but only when the
             * seller has not already placed one themselves, since a hand-placed
             * pin usually marks the gate rather than the middle of the field.
             */
            if ($listing->latitude === null || $listing->longitude === null) {
                $listing->forceFill([
                    'latitude' => $boundary->centroid_latitude,
                    'longitude' => $boundary->centroid_longitude,
                ])->save();
            }

            unset($outer);

            return $boundary->refresh();
        });
    }

    public function delete(Listing $listing): void
    {
        ListingBoundary::where('listing_id', $listing->getKey())->delete();
    }

    /**
     * Close, deduplicate and clamp one ring.
     *
     * Sellers click corners; they do not click a closing point on top of the
     * first one, and a double-click frequently lands two identical vertices.
     * Both are fixed here so the stored geometry is always a valid closed ring
     * regardless of how carefully it was drawn.
     *
     * Public so a validator can measure exactly what would be stored, rather
     * than measuring the raw request and rejecting a valid three-corner plot
     * for having no closing point.
     *
     * @param  array<int, array{0: float|int|string, 1: float|int|string}>  $ring
     * @return array<int, array{0: float, 1: float}>
     */
    public function normaliseRing(array $ring): array
    {
        $points = [];

        foreach ($ring as $point) {
            $lng = (float) ($point[0] ?? 0);
            $lat = (float) ($point[1] ?? 0);

            // Consecutive duplicates carry no shape and break the area sign.
            $previous = end($points);

            if ($previous !== false
                && abs($previous[0] - $lng) < 1e-9
                && abs($previous[1] - $lat) < 1e-9) {
                continue;
            }

            $points[] = [round($lng, 7), round($lat, 7)];
        }

        // Drop an explicit closing point before counting, then re-close once.
        $count = count($points);

        if ($count >= 2) {
            $first = $points[0];
            $last = $points[$count - 1];

            if (abs($first[0] - $last[0]) < 1e-9 && abs($first[1] - $last[1]) < 1e-9) {
                array_pop($points);
            }
        }

        if (count($points) < 3) {
            return [];
        }

        $points[] = $points[0];

        return $points;
    }

    /**
     * Area, perimeter, centroid and bounding box for a set of rings.
     *
     * Ring 0 adds area; every later ring is a hole and subtracts it.
     *
     * @param  array<int, array<int, array{0: float, 1: float}>>  $rings
     * @return array{area_sqm: float, perimeter_m: float, centroid: array{0: float, 1: float}, bbox: array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}}
     */
    public function measure(array $rings): array
    {
        $area = 0.0;
        $perimeter = 0.0;

        $minLat = 90.0;
        $maxLat = -90.0;
        $minLng = 180.0;
        $maxLng = -180.0;

        foreach ($rings as $index => $ring) {
            $ringArea = abs($this->signedSphericalArea($ring));

            $area += $index === 0 ? $ringArea : -$ringArea;
            $perimeter += $this->ringPerimeter($ring);

            foreach ($ring as [$lng, $lat]) {
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
            }
        }

        return [
            'area_sqm' => max(0.0, $area),
            'perimeter_m' => $perimeter,
            'centroid' => $this->centroid($rings[0]),
            'bbox' => [
                'min_lat' => $minLat,
                'max_lat' => $maxLat,
                'min_lng' => $minLng,
                'max_lng' => $maxLng,
            ],
        ];
    }

    /**
     * Signed area of a closed ring on a sphere, in square metres.
     *
     * The sign encodes winding order, which is why it is not absolute here —
     * measure() decides what to do with it.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private function signedSphericalArea(array $ring): float
    {
        $count = count($ring);

        if ($count < 4) {
            return 0.0;
        }

        $total = 0.0;

        // The ring is closed, so the last point repeats the first; iterate the
        // distinct vertices and wrap.
        for ($i = 0; $i < $count - 1; $i++) {
            [$lng1, $lat1] = $ring[$i];
            [$lng2, $lat2] = $ring[$i + 1];

            $total += deg2rad($lng2 - $lng1)
                * (2 + sin(deg2rad($lat1)) + sin(deg2rad($lat2)));
        }

        return $total * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2.0;
    }

    /** @param array<int, array{0: float, 1: float}> $ring */
    private function ringPerimeter(array $ring): float
    {
        $total = 0.0;

        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            $total += $this->haversine($ring[$i], $ring[$i + 1]);
        }

        return $total;
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private function haversine(array $a, array $b): float
    {
        $lat1 = deg2rad($a[1]);
        $lat2 = deg2rad($b[1]);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($b[0] - $a[0]);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * Area centroid of the outer ring.
     *
     * Computed in a local equirectangular projection about the ring's own mean
     * latitude, which removes the longitude distortion over a parcel-sized
     * extent. A degenerate ring (zero area, e.g. three collinear points) falls
     * back to the vertex mean rather than dividing by zero.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array{0: float, 1: float}
     */
    private function centroid(array $ring): array
    {
        $count = count($ring) - 1;

        if ($count < 3) {
            return [$ring[0][0] ?? 0.0, $ring[0][1] ?? 0.0];
        }

        $meanLat = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $meanLat += $ring[$i][1];
        }

        $meanLat /= $count;
        $scale = cos(deg2rad($meanLat)) ?: 1.0;

        $twiceArea = 0.0;
        $x = 0.0;
        $y = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $x1 = $ring[$i][0] * $scale;
            $y1 = $ring[$i][1];
            $x2 = $ring[$i + 1][0] * $scale;
            $y2 = $ring[$i + 1][1];

            $cross = $x1 * $y2 - $x2 * $y1;

            $twiceArea += $cross;
            $x += ($x1 + $x2) * $cross;
            $y += ($y1 + $y2) * $cross;
        }

        if (abs($twiceArea) < 1e-12) {
            $sumLng = 0.0;
            $sumLat = 0.0;

            for ($i = 0; $i < $count; $i++) {
                $sumLng += $ring[$i][0];
                $sumLat += $ring[$i][1];
            }

            return [$sumLng / $count, $sumLat / $count];
        }

        return [$x / (3 * $twiceArea) / $scale, $y / (3 * $twiceArea)];
    }

    /**
     * Does any pair of non-adjacent edges cross?
     *
     * A self-intersecting outline is a bow tie: the area formula returns the
     * difference of the two lobes rather than their sum, so it under-reports —
     * and the shaded polygon a buyer sees is not the parcel. Rejecting it at
     * the validator is the only place this can be caught before it is stored.
     *
     * O(n²) and deliberately so: the vertex cap is small, and a sweep-line here
     * would be more code to review than the thing it replaces.
     *
     * @param  array<int, array{0: float|int|string, 1: float|int|string}>  $ring
     */
    public function selfIntersects(array $ring): bool
    {
        $points = $this->normaliseRing($ring);
        $n = count($points) - 1;

        if ($n < 4) {
            return false;
        }

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                // Skip edges that share a vertex — they touch by definition.
                if ($j === $i || $j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue;
                }

                if ($this->segmentsCross($points[$i], $points[$i + 1], $points[$j], $points[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{0: float, 1: float}  $p1
     * @param  array{0: float, 1: float}  $p2
     * @param  array{0: float, 1: float}  $p3
     * @param  array{0: float, 1: float}  $p4
     */
    private function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = $this->cross($p3, $p4, $p1);
        $d2 = $this->cross($p3, $p4, $p2);
        $d3 = $this->cross($p1, $p2, $p3);
        $d4 = $this->cross($p1, $p2, $p4);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    /**
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     * @param  array{0: float, 1: float}  $c
     */
    private function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    /**
     * Human-readable area in the units Tanzanian land is actually traded in.
     *
     * Square metres below a quarter-acre, acres up to ten, hectares above —
     * "48,562 sqm" means nothing to a buyer who thinks in acres.
     *
     * @return array{sqm: float, acres: float, hectares: float, display: string}
     */
    public function areaSummary(float $sqm): array
    {
        $acres = $sqm / self::SQM_PER_ACRE;
        $hectares = $sqm / self::SQM_PER_HECTARE;

        $display = match (true) {
            $sqm < 1012 => number_format($sqm, 0).' m²',
            $acres < 10 => number_format($acres, 2).' acres',
            default => number_format($hectares, 2).' ha',
        };

        return [
            'sqm' => round($sqm, 2),
            'acres' => round($acres, 4),
            'hectares' => round($hectares, 4),
            'display' => $display,
        ];
    }
}
