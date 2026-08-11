<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Advertising\AdMetricsService;
use Illuminate\Console\Command;

/**
 * Folds Redis-buffered ad impressions into MySQL. Runs every minute.
 *
 * Separate from `saka:counters:flush` rather than folded into it: that command
 * writes to `listings`, this one upserts a rollup table and bumps two others,
 * and a failure in either must not abandon the other's buffer mid-flight.
 */
class FlushAdImpressions extends Command
{
    protected $signature = 'saka:ads:flush-impressions';

    protected $description = 'Flush buffered advertisement impressions from Redis to the database';

    public function handle(AdMetricsService $metrics): int
    {
        $result = $metrics->flushImpressions();

        $this->info("Flushed {$result['impressions']} impression(s) across {$result['rows']} rollup row(s).");

        return self::SUCCESS;
    }
}
