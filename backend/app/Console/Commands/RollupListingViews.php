<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Folds raw `listing_views` rows into `listing_view_daily`.
 *
 * `listing_views` is the fastest-growing table in the system and exists only
 * long enough to be aggregated. Everything downstream (seller analytics,
 * popularity) reads the daily table, so the raw rows can then be pruned.
 *
 * Aggregates by DAY and upserts, so re-running for a day is idempotent — which
 * matters because a failed scheduler run will simply be retried.
 */
class RollupListingViews extends Command
{
    protected $signature = 'saka:views:rollup {--days=2}';

    protected $description = 'Aggregate raw listing views into the daily rollup table';

    public function handle(): int
    {
        // Re-processes the last N days rather than only yesterday: a view
        // recorded just after midnight, or a worker that lagged, would
        // otherwise be missed permanently.
        $from = now()->subDays((int) $this->option('days'))->startOfDay();

        $rows = DB::table('listing_views')
            ->where('viewed_at', '>=', $from)
            ->groupBy('listing_id', 'viewed_on')
            ->selectRaw('listing_id, viewed_on as date, COUNT(*) as views, COUNT(DISTINCT ip_hash) as unique_views')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No views to roll up.');

            return self::SUCCESS;
        }

        $payload = $rows->map(fn ($row) => [
            'listing_id' => $row->listing_id,
            'date' => $row->date,
            'views' => $row->views,
            'unique_views' => $row->unique_views,
        ])->all();

        foreach (array_chunk($payload, 500) as $batch) {
            DB::table('listing_view_daily')->upsert(
                $batch,
                ['listing_id', 'date'],
                ['views', 'unique_views'],
            );
        }

        $this->info('Rolled up '.count($payload).' listing/day row(s).');

        return self::SUCCESS;
    }
}
