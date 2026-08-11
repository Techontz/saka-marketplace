<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Listing\Enums\ListingStatus;
use App\Support\Cache\CacheKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every district and ward a coordinate.
 *
 * `regions.latitude`, `districts.latitude` and `wards.latitude` exist in the
 * schema and were populated for nine regions and nothing else — 0 of 155
 * districts and 0 of 70 wards. That was survivable while location was a text
 * box. It is not survivable now that picking a place from the autocomplete has
 * to set a map centre and a search radius: without a coordinate, choosing
 * "Mikocheni" could filter the list but could not move the map.
 *
 * WHERE THE COORDINATE COMES FROM
 * -------------------------------
 * The average position of everything actually located there — published
 * listings first, and public places as well, since a district may have a
 * hospital and a bus station in it before it has a listing. That is a truthful
 * definition: "the middle of what we know is in this district". It is also
 * self-correcting, because it improves as the catalogue grows, and it never
 * invents a position for somewhere we know nothing about — those stay NULL and
 * the API falls back to the parent region.
 *
 * The alternative was a hardcoded gazetteer of 155 districts. That would be
 * more precise and would be wrong the moment the administrative boundaries
 * change, with no way to tell that it had.
 *
 * Idempotent and safe to re-run. Scheduled alongside the taxonomy recount.
 */
class BackfillLocationCentroids extends Command
{
    protected $signature = 'saka:locations:centroids {--force : Recompute even where a coordinate is already stored}';

    protected $description = 'Derive district and ward coordinates from the listings and places located in them';

    public function handle(): int
    {
        $statuses = array_map(
            fn (ListingStatus $status): string => $status->value,
            ListingStatus::publiclyVisible(),
        );

        $districts = $this->backfill('districts', 'district_id', $statuses);
        $wards = $this->backfill('wards', 'ward_id', $statuses);

        CacheKeys::flushLocations();

        $this->info("Set coordinates on {$districts} districts and {$wards} wards.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function backfill(string $table, string $foreignKey, array $statuses): int
    {
        $rows = DB::table($table)
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('latitude'))
            ->pluck('id');

        if ($rows->isEmpty()) {
            return 0;
        }

        $listingCentroids = DB::table('listings')
            ->select($foreignKey.' as location_id')
            ->selectRaw('AVG(latitude) AS lat, AVG(longitude) AS lng, COUNT(*) AS total')
            ->whereIn($foreignKey, $rows)
            ->whereNull('deleted_at')
            ->whereIn('status', $statuses)
            ->whereNotNull('latitude')
            ->groupBy($foreignKey)
            ->get()
            ->keyBy('location_id');

        /*
         * Public places only contribute where the schema knows about them.
         * `public_places` records a district but not a ward, so the ward pass
         * runs on listings alone rather than pretending otherwise.
         */
        $placeCentroids = $foreignKey === 'district_id'
            ? DB::table('public_places')
                ->select('district_id as location_id')
                ->selectRaw('AVG(latitude) AS lat, AVG(longitude) AS lng, COUNT(*) AS total')
                ->whereIn('district_id', $rows)
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->groupBy('district_id')
                ->get()
                ->keyBy('location_id')
            : collect();

        $updated = 0;

        foreach ($rows as $id) {
            $listing = $listingCentroids->get($id);
            $place = $placeCentroids->get($id);

            $totalWeight = (int) ($listing->total ?? 0) + (int) ($place->total ?? 0);

            if ($totalWeight === 0) {
                continue;
            }

            // Weighted by how many rows each source contributed, so a district
            // with forty listings and one bank is not pulled to the bank.
            $lat = (((float) ($listing->lat ?? 0)) * (int) ($listing->total ?? 0)
                + ((float) ($place->lat ?? 0)) * (int) ($place->total ?? 0)) / $totalWeight;

            $lng = (((float) ($listing->lng ?? 0)) * (int) ($listing->total ?? 0)
                + ((float) ($place->lng ?? 0)) * (int) ($place->total ?? 0)) / $totalWeight;

            DB::table($table)->where('id', $id)->update([
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                'updated_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
    }
}
