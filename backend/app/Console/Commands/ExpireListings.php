<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Listing;
use App\Services\Listing\ListingStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves published listings past their expiry date to Expired.
 *
 * Goes through ListingStatusService rather than a bulk UPDATE so every
 * expiry is validated against the transition table, written to
 * listing_status_histories and removed from the search index — a mass update
 * would silently skip all three.
 *
 * Chunked because this runs against the whole table: loading every expired
 * listing into memory is the classic way a nightly command starts OOM-ing
 * eighteen months after launch.
 */
class ExpireListings extends Command
{
    protected $signature = 'saka:listings:expire {--chunk=200} {--dry-run}';

    protected $description = 'Expire published listings whose expiry date has passed';

    public function handle(ListingStatusService $status): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expired = 0;
        $failed = 0;

        Listing::query()
            ->where('status', ListingStatus::Published)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($listings) use ($status, $dryRun, &$expired, &$failed): void {
                foreach ($listings as $listing) {
                    if ($dryRun) {
                        $this->line("  would expire: {$listing->slug}");
                        $expired++;

                        continue;
                    }

                    try {
                        $status->transition($listing, ListingStatus::Expired);
                        $expired++;
                    } catch (Throwable $e) {
                        // One bad row must not abort the sweep.
                        $failed++;
                        Log::error('listings.expire_failed', [
                            'listing_id' => $listing->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Expired {$expired} listing(s)".($failed > 0 ? ", {$failed} failed" : '').'.');

        if ($expired > 0 && ! $dryRun) {
            Log::info('listings.expired', ['count' => $expired, 'failed' => $failed]);
        }

        return self::SUCCESS;
    }
}
