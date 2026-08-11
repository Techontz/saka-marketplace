<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Listing;
use App\Models\ListingView;
use App\Services\Metrics\CounterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records a listing view off the request path.
 *
 * The unique key (listing_id, ip_hash, viewed_on) enforces one counted view per
 * client per day IN THE DATABASE. Doing it in application code would race under
 * concurrency; here a duplicate simply violates the constraint and is ignored.
 */
class RecordListingView implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $listingId,
        public readonly string $ipHash,
        public readonly ?int $userId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $referrer = null,
    ) {
        // Lowest-priority work in the system: a backlog of view writes must
        // never delay an auth email or an image resize.
        //
        // Set via onQueue(), NOT a `queue()` method: Laravel treats a `queue()`
        // method as CUSTOM QUEUEING LOGIC and calls it instead of pushing the
        // job, which silently drops it. And not via a `$queue` property either,
        // because the Queueable trait already declares one.
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        try {
            ListingView::create([
                'listing_id' => $this->listingId,
                'user_id' => $this->userId,
                'session_id' => $this->sessionId,
                'ip_hash' => $this->ipHash,
                'referrer' => $this->referrer,
                'viewed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Duplicate for today — the view is already counted.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return;
            }

            throw $e;
        }

        // Buffered in Redis and folded into MySQL once a minute. A direct
        // UPDATE here would serialise every request to a popular listing
        // behind a row lock on the hottest rows in the table.
        app(CounterService::class)->increment('view_count', $this->listingId);
    }
}
