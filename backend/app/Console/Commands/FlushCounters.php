<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\CounterService;
use Illuminate\Console\Command;

/**
 * Folds Redis-buffered counters into MySQL. Runs every minute.
 */
class FlushCounters extends Command
{
    protected $signature = 'saka:counters:flush';

    protected $description = 'Flush buffered listing counters from Redis to the database';

    public function handle(CounterService $counters): int
    {
        $result = $counters->flush();

        $this->info("Flushed {$result['flushed']} counter update(s) across {$result['listings']} listing(s).");

        return self::SUCCESS;
    }
}
