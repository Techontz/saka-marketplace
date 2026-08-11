<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Models\AdCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Brings `ad_campaigns.status` back in step with each campaign's dates.
 *
 * This does NOT control what is served — `AdCampaign::scopeServable` reads the
 * dates directly, so a missed run of this command changes nothing a visitor
 * sees. What it maintains is the admin's view: "show me everything live" is an
 * indexed equality lookup because of this column, and a campaign list where
 * finished campaigns still say "Active" is one nobody trusts.
 *
 * Only campaigns whose status FOLLOWS the schedule are touched. Draft, paused
 * and archived are human decisions, and a paused campaign whose end date passes
 * must stay paused — flipping it to expired would mean re-dating it by hand
 * before it could ever resume.
 */
class RefreshAdCampaignStatuses extends Command
{
    protected $signature = 'saka:ads:refresh-statuses';

    protected $description = 'Move ad campaigns between scheduled, active and expired as their dates pass';

    public function handle(): int
    {
        $now = Carbon::now();

        $followsSchedule = array_map(
            fn (AdCampaignStatus $status): string => $status->value,
            array_filter(AdCampaignStatus::cases(), fn (AdCampaignStatus $s): bool => $s->followsSchedule()),
        );

        $changed = 0;

        AdCampaign::query()
            ->whereIn('status', $followsSchedule)
            // Chunked by id: a status update would otherwise shift rows out of
            // the result set mid-pagination and skip the ones behind them.
            ->chunkById(200, function ($campaigns) use ($now, &$changed): void {
                foreach ($campaigns as $campaign) {
                    $target = $campaign->scheduledStatus($now);

                    if ($target === $campaign->status) {
                        continue;
                    }

                    // forceFill: `status` is guarded against mass assignment
                    // precisely so a request body can never move it. The
                    // scheduler is the legitimate exception.
                    $campaign->forceFill(['status' => $target->value])->save();
                    $changed++;
                }
            });

        $this->info("Updated {$changed} campaign status(es).");

        return self::SUCCESS;
    }
}
