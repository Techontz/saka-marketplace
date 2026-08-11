<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes raw `listing_views` older than the retention window.
 *
 * Safe only because RollupListingViews has already aggregated them into
 * `listing_view_daily`, which is retained indefinitely — the analytics survive,
 * the per-request rows do not.
 *
 * Deletes in bounded batches with a pause between them: a single
 * `DELETE ... WHERE viewed_at < ?` over millions of rows holds a long
 * transaction, bloats the binlog and can stall replicas.
 */
class PruneListingViews extends Command
{
    protected $signature = 'saka:views:prune {--days=90} {--batch=2000} {--max-batches=500}';

    protected $description = 'Prune raw listing view rows past the retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $batchSize = (int) $this->option('batch');
        $maxBatches = (int) $this->option('max-batches');

        $deleted = 0;
        $batches = 0;

        do {
            $affected = DB::table('listing_views')
                ->where('viewed_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $deleted += $affected;
            $batches++;

            if ($affected > 0 && $batches < $maxBatches) {
                usleep(50_000); // let replicas catch up
            }
        } while ($affected === $batchSize && $batches < $maxBatches);

        $this->info("Pruned {$deleted} raw view row(s) older than {$cutoff->toDateString()}.");

        if ($batches >= $maxBatches) {
            // Better to log a known backlog than to run unbounded.
            $this->warn('Batch ceiling reached — more rows remain for the next run.');
            Log::warning('views.prune_incomplete', ['deleted' => $deleted, 'cutoff' => $cutoff->toDateString()]);
        }

        return self::SUCCESS;
    }
}
