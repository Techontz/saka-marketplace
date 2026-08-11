<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Models\PromotionRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Closes promotion requests whose window passed before anyone reviewed them.
 *
 * Without this a request sits in the queue forever, and an operator clearing a
 * backlog on Friday approves a promotion for "Monday to Wednesday" that has
 * already been and gone — minting a campaign that is expired on arrival and
 * telling the vendor their promotion was approved when it will never run.
 *
 * Approval also re-checks the window, so this is not the only guard. It is what
 * keeps the queue honest: a request nobody can act on should not be counted as
 * work outstanding.
 *
 * Drafts are left alone. A vendor's unfinished wizard is theirs to abandon, and
 * expiring it would delete their work without asking.
 */
class ExpirePromotionRequests extends Command
{
    protected $signature = 'saka:promotions:expire';

    protected $description = 'Expire pending promotion requests whose requested window has closed';

    public function handle(): int
    {
        $today = Carbon::now()->startOfDay();

        // A window that ENDS today is still usable — a same-day promotion is a
        // real thing — so this compares against the start of today rather than
        // "now", and only rows strictly before it are gone.
        $expired = PromotionRequest::query()
            ->where('status', PromotionRequestStatus::Pending->value)
            ->whereDate('requested_end', '<', $today->toDateString())
            ->update(['status' => PromotionRequestStatus::Expired->value]);

        $this->info("Expired {$expired} promotion request(s).");

        return self::SUCCESS;
    }
}
