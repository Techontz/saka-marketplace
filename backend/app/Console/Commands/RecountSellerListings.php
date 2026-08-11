<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seller\SellerCounterService;
use Illuminate\Console\Command;

/**
 * Reconciles every seller's listing counters.
 *
 * The counters are maintained on the write path, but a denormalised number
 * drifts eventually — a direct database fix, a restored soft-delete, a failed
 * job. This is the same safety net `saka:taxonomy:recount` provides for
 * category counts, and it is cheap enough to run nightly.
 */
class RecountSellerListings extends Command
{
    protected $signature = 'saka:sellers:recount';

    protected $description = "Recompute every seller profile's listing counters from source";

    public function handle(SellerCounterService $counters): int
    {
        $updated = $counters->recountAll();

        $this->info("Recounted {$updated} seller profile(s).");

        return self::SUCCESS;
    }
}
