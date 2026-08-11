<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Where?" — one input, every kind of place.
 *
 * The location filter was a free-text box, so a customer had to already know
 * whether "Masaki" was a ward, a district or neither, and typing it produced a
 * `place` LIKE query that could not move the map because it never resolved to
 * a coordinate.
 *
 * This resolves what someone typed into a REAL PLACE: the exact filter
 * parameter to apply, and a coordinate and radius to frame the map with. The
 * client does not have to know the difference between a ward and a landmark —
 * that is what the `filter` block on each row is for.
 *
 * FIVE SOURCES, FIVE QUERIES, NO N+1
 * ----------------------------------
 * Regions, districts, wards, public places and businesses are each queried
 * once with their parent eager-joined, then merged and ranked in PHP. Ranking
 * in the database would need a UNION across five differently-shaped tables for
 * no benefit at these limits.
 *
 * Cached for ten minutes on the normalised term, because an autocomplete is hit
 * on every keystroke and the same prefixes recur constantly between users.
 */
class LocationSearchController extends Controller
{
    /**
     * How far around each kind of place is worth searching, in kilometres.
     *
     * A region is a province — 50 km barely covers one, but a larger radius
     * makes the geo prefilter useless and the region SLUG filter is exact
     * anyway, so the radius here only frames the map. A landmark is a single
     * building, and someone searching near one means walking distance.
     */
    private const RADIUS_KM = [
        'region' => 50.0,
        'district' => 15.0,
        'ward' => 5.0,
        'place' => 3.0,
        'business' => 3.0,
    ];

    /**
     * Tie-break weight when two places match equally well.
     *
     * More specific first: someone typing "Masaki" wants the neighbourhood,
     * not the region that contains it.
     */
    private const TYPE_RANK = [
        'ward' => 0,
        'place' => 1,
        'district' => 2,
        'business' => 3,
        'region' => 4,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            // Lets a caller ask only for administrative areas (the listings
            // filter) or only for points (the map's "near this landmark").
            'types' => ['nullable', 'string', 'max:120'],
        ]);

        $term = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 8);

        $types = $validated['types'] ?? null;
        $wanted = $types === null
            ? array_keys(self::TYPE_RANK)
            : array_values(array_intersect(
                array_map('trim', explode(',', $types)),
                array_keys(self::TYPE_RANK),
            ));

        if ($wanted === []) {
            return response()->json(['data' => []]);
        }

        sort($wanted);

        $key = 'locations:search:'.md5(mb_strtolower($term).'|'.implode(',', $wanted)).":{$limit}";

        $results = Cache::remember($key, now()->addMinutes(10), function () use ($term, $limit, $wanted): array {
            $rows = [];

            foreach ($wanted as $type) {
                $rows = array_merge($rows, match ($type) {
                    'region' => $this->regions($term, $limit),
                    'district' => $this->districts($term, $limit),
                    'ward' => $this->wards($term, $limit),
                    'place' => $this->places($term, $limit),
                    'business' => $this->businesses($term, $limit),
                    default => [],
                });
            }

            return $this->rank($rows, $term, $limit);
        });

        return response()->json(['data' => $results]);
    }

    /**
     * A LIKE pattern with the wildcards a user typed treated as literals.
     *
     * Without this, typing `%` matches everything and typing `_` matches any
     * single character — neither of which anyone means.
     */
    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }

    /** @return list<array<string, mixed>> */
    private function regions(string $term, int $limit): array
    {
        return DB::table('regions')
            ->where('is_active', true)
            ->where('name', 'like', $this->like($term))
            ->orderByDesc('listing_count')
            ->limit($limit)
            ->get(['name', 'slug', 'latitude', 'longitude', 'listing_count'])
            ->map(fn ($row): array => $this->row(
                type: 'region',
                label: $row->name,
                context: 'Region',
                slug: $row->slug,
                latitude: $row->latitude,
                longitude: $row->longitude,
                listingCount: (int) $row->listing_count,
                filterParam: 'region',
                filterValue: $row->slug,
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function districts(string $term, int $limit): array
    {
        return DB::table('districts as d')
            ->join('regions as r', 'r.id', '=', 'd.region_id')
            ->where('d.is_active', true)
            ->where('d.name', 'like', $this->like($term))
            ->orderByDesc('d.listing_count')
            ->limit($limit)
            ->get([
                'd.name', 'd.slug', 'd.latitude', 'd.longitude', 'd.listing_count',
                'r.name as region_name', 'r.latitude as region_latitude', 'r.longitude as region_longitude',
            ])
            ->map(fn ($row): array => $this->row(
                type: 'district',
                label: $row->name,
                context: $row->region_name,
                slug: $row->slug,
                // Fall back to the region when the district has no centroid of
                // its own — see BackfillLocationCentroids. A slightly wide map
                // beats a map that does not move.
                latitude: $row->latitude ?? $row->region_latitude,
                longitude: $row->longitude ?? $row->region_longitude,
                listingCount: (int) $row->listing_count,
                filterParam: 'district',
                filterValue: $row->slug,
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function wards(string $term, int $limit): array
    {
        return DB::table('wards as w')
            ->join('districts as d', 'd.id', '=', 'w.district_id')
            ->join('regions as r', 'r.id', '=', 'd.region_id')
            ->where('w.is_active', true)
            ->where('w.name', 'like', $this->like($term))
            ->limit($limit)
            ->get([
                'w.name', 'w.slug', 'w.latitude', 'w.longitude',
                'd.name as district_name', 'd.latitude as district_latitude', 'd.longitude as district_longitude',
                'r.name as region_name',
            ])
            ->map(fn ($row): array => $this->row(
                type: 'ward',
                label: $row->name,
                context: $row->district_name.', '.$row->region_name,
                slug: $row->slug,
                latitude: $row->latitude ?? $row->district_latitude,
                longitude: $row->longitude ?? $row->district_longitude,
                listingCount: null,
                filterParam: 'ward',
                filterValue: $row->slug,
            ))
            ->all();
    }

    /**
     * Landmarks: hospitals, schools, malls, bus terminals.
     *
     * These are the places people actually navigate by — "near Mlimani City"
     * is a more natural search than any ward name — and every one of them has
     * a real coordinate, so they always move the map.
     *
     * @return list<array<string, mixed>>
     */
    private function places(string $term, int $limit): array
    {
        return DB::table('public_places as p')
            ->leftJoin('public_place_categories as c', 'c.id', '=', 'p.public_place_category_id')
            ->leftJoin('districts as d', 'd.id', '=', 'p.district_id')
            ->where('p.is_active', true)
            ->where('p.name', 'like', $this->like($term))
            ->whereNotNull('p.latitude')
            ->limit($limit)
            ->get([
                'p.name', 'p.slug', 'p.latitude', 'p.longitude',
                'c.name as category_name', 'c.icon as category_icon', 'd.name as district_name',
            ])
            ->map(fn ($row): array => $this->row(
                type: 'place',
                label: $row->name,
                context: trim(($row->category_name ?? 'Landmark').($row->district_name ? ', '.$row->district_name : '')),
                slug: $row->slug,
                latitude: $row->latitude,
                longitude: $row->longitude,
                listingCount: null,
                // No administrative filter applies: searching "near Muhimbili"
                // is a radius search, not a ward one.
                filterParam: null,
                filterValue: null,
                icon: $row->category_icon,
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function businesses(string $term, int $limit): array
    {
        return DB::table('seller_profiles as s')
            ->leftJoin('districts as d', 'd.id', '=', 's.district_id')
            ->whereNotNull('s.onboarding_completed_at')
            // seller_profiles is not soft-deleted; public_places is, and that
            // asymmetry is why each table's guard is written out rather than
            // assumed.
            ->whereNull('s.deleted_at')
            ->where('s.display_name', 'like', $this->like($term))
            ->whereNotNull('s.latitude')
            ->orderByDesc('s.active_listings')
            ->limit($limit)
            ->get([
                's.display_name', 's.slug', 's.latitude', 's.longitude', 's.active_listings',
                'd.name as district_name',
            ])
            ->map(fn ($row): array => $this->row(
                type: 'business',
                label: $row->display_name,
                context: trim('Business'.($row->district_name ? ', '.$row->district_name : '')),
                slug: $row->slug,
                latitude: $row->latitude,
                longitude: $row->longitude,
                listingCount: (int) $row->active_listings,
                filterParam: null,
                filterValue: null,
            ))
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(
        string $type,
        string $label,
        string $context,
        string $slug,
        mixed $latitude,
        mixed $longitude,
        ?int $listingCount,
        ?string $filterParam,
        ?string $filterValue,
        ?string $icon = null,
    ): array {
        return [
            // Stable across types, so a client can key a list by it.
            'id' => $type.':'.$slug,
            'type' => $type,
            'label' => $label,
            'context' => $context,
            'slug' => $slug,
            'icon' => $icon,
            'latitude' => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
            'radius_km' => self::RADIUS_KM[$type],
            'listing_count' => $listingCount,
            /*
             * Exactly what the client should put in the query string.
             *
             * Handing back the parameter name rather than expecting the client
             * to map five types onto four filters is what keeps this from
             * being reimplemented — differently — in the marketplace, the
             * vendor portal and the admin app.
             */
            'filter' => $filterParam === null ? null : ['param' => $filterParam, 'value' => $filterValue],
        ];
    }

    /**
     * Best matches first.
     *
     * A prefix match beats a match in the middle of a name: someone typing
     * "Mba" means Mbagala, not "Kimara Mbagala Road". After that it is how much
     * is actually there, then how specific the place is — because a result with
     * nothing in it wastes the tap.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, string $term, int $limit): array
    {
        $needle = mb_strtolower($term);

        usort($rows, function (array $a, array $b) use ($needle): int {
            $aPrefix = str_starts_with(mb_strtolower((string) $a['label']), $needle) ? 0 : 1;
            $bPrefix = str_starts_with(mb_strtolower((string) $b['label']), $needle) ? 0 : 1;

            if ($aPrefix !== $bPrefix) {
                return $aPrefix <=> $bPrefix;
            }

            // A place with listings is more useful than one without, but only
            // where the count means something — wards and landmarks carry null.
            $aHas = ($a['listing_count'] ?? 0) > 0 ? 0 : 1;
            $bHas = ($b['listing_count'] ?? 0) > 0 ? 0 : 1;

            if ($a['listing_count'] !== null && $b['listing_count'] !== null && $aHas !== $bHas) {
                return $aHas <=> $bHas;
            }

            $aRank = self::TYPE_RANK[$a['type']] ?? 9;
            $bRank = self::TYPE_RANK[$b['type']] ?? 9;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return ($b['listing_count'] ?? 0) <=> ($a['listing_count'] ?? 0);
        });

        // Two districts can share a name across regions, so dedupe on the id
        // rather than the label — both are real answers and both should show.
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }

            $seen[$row['id']] = true;
            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
