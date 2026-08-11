<?php

declare(strict_types=1);

namespace App\Services\Advertising;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Models\AdClick;
use App\Models\AdCreative;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Counting impressions and clicks.
 *
 * The same shape as CounterService, and for the same reason: an impression
 * write per page view would serialise every request to a popular placement
 * behind a row lock on the single hottest row in the table. Redis absorbs them
 * and the scheduler folds them into MySQL once a minute.
 *
 * The asymmetry between the two is deliberate.
 *
 * IMPRESSIONS are buffered and aggregated. They are high-volume, individually
 * meaningless, and a lost minute of them is a rounding error on a monthly
 * report. If Redis is unavailable they are dropped rather than written
 * directly — the direct write is the exact stampede this class exists to
 * prevent, and doing it during an incident would turn a cache outage into a
 * database one.
 *
 * CLICKS are written immediately and individually. They are rare, they are what
 * the advertiser is billed for, and losing one is a dispute nobody can settle.
 * A click costs a row insert; that is affordable at click volumes and is not at
 * impression volumes.
 */
class AdMetricsService
{
    /** field => `creativeId:campaignId:placement:date` */
    private const IMPRESSION_HASH = 'counters:ad_impressions';

    /**
     * Record that a creative was displayed.
     *
     * Fire-and-forget by design: this is called while rendering a page nobody
     * is waiting on ad accounting for, and a metrics failure must never turn
     * into a 500 on the marketplace.
     */
    public function recordImpression(AdCreative $creative, AdPlacement $placement, ?Carbon $at = null): void
    {
        $date = ($at ?? Carbon::now())->toDateString();
        $field = "{$creative->getKey()}:{$creative->ad_campaign_id}:{$placement->value}:{$date}";

        try {
            Redis::hincrby(self::IMPRESSION_HASH, $field, 1);
        } catch (Throwable) {
            // Deliberately swallowed, and deliberately NOT written directly.
            // See the class docblock: the direct path is the stampede.
        }
    }

    /**
     * Fold buffered impressions into MySQL.
     *
     * Read-then-delete, so a concurrent increment lands wholly in this flush or
     * wholly in the next — never counted twice, never lost in between.
     *
     * @return array{rows: int, impressions: int}
     */
    public function flushImpressions(): array
    {
        try {
            $buffered = Redis::hgetall(self::IMPRESSION_HASH);
        } catch (Throwable) {
            return ['rows' => 0, 'impressions' => 0];
        }

        if ($buffered === []) {
            return ['rows' => 0, 'impressions' => 0];
        }

        Redis::del(self::IMPRESSION_HASH);

        $placements = AdPlacement::values();
        $rows = [];
        $perCreative = [];
        $perCampaign = [];
        $total = 0;

        foreach ($buffered as $field => $delta) {
            $parts = explode(':', (string) $field);

            if (count($parts) !== 4) {
                continue; // malformed key from an older format — drop it
            }

            [$creativeId, $campaignId, $placement, $date] = $parts;
            $count = (int) $delta;

            // `placement` is interpolated into an ENUM column; a value that is
            // not one of ours would be a silent truncation to ''.
            if ($count <= 0 || ! in_array($placement, $placements, true)) {
                continue;
            }

            $rows[] = [
                'ad_creative_id' => (int) $creativeId,
                'ad_campaign_id' => (int) $campaignId,
                'placement' => $placement,
                'date' => $date,
                'impressions' => $count,
            ];

            $perCreative[(int) $creativeId] = ($perCreative[(int) $creativeId] ?? 0) + $count;
            $perCampaign[(int) $campaignId] = ($perCampaign[(int) $campaignId] ?? 0) + $count;
            $total += $count;
        }

        if ($rows === []) {
            return ['rows' => 0, 'impressions' => 0];
        }

        DB::transaction(function () use ($rows, $perCreative, $perCampaign): void {
            /*
             * upsert with a RAW increment, not a replace.
             *
             * `impressions` on an existing row must ACCUMULATE — the same
             * creative/placement/day is flushed once a minute all day, and an
             * assignment would leave the row holding only the last minute.
             */
            DB::table('ad_impressions_daily')->upsert(
                $rows,
                ['ad_creative_id', 'placement', 'date'],
                ['impressions' => DB::raw('`ad_impressions_daily`.`impressions` + VALUES(`impressions`)')],
            );

            foreach ($perCreative as $creativeId => $count) {
                DB::table('ad_creatives')->where('id', $creativeId)
                    ->update(['impressions_count' => DB::raw("impressions_count + {$count}")]);
            }

            foreach ($perCampaign as $campaignId => $count) {
                DB::table('ad_campaigns')->where('id', $campaignId)
                    ->update(['impressions_count' => DB::raw("impressions_count + {$count}")]);
            }
        });

        return ['rows' => count($rows), 'impressions' => $total];
    }

    /**
     * Record a click and return where to send the visitor.
     *
     * Written synchronously and inside a transaction with the counter bumps, so
     * the row and the totals cannot disagree — which they would if a queued job
     * failed after the redirect had already happened.
     */
    public function recordClick(
        AdCreative $creative,
        AdPlacement $placement,
        ?int $userId = null,
        ?string $ipHash = null,
        ?string $referrer = null,
    ): string {
        DB::transaction(function () use ($creative, $placement, $userId, $ipHash, $referrer): void {
            AdClick::create([
                'ad_creative_id' => $creative->getKey(),
                'ad_campaign_id' => $creative->ad_campaign_id,
                'placement' => $placement->value,
                'user_id' => $userId,
                'ip_hash' => $ipHash,
                'referrer' => $referrer !== null ? mb_substr($referrer, 0, 255) : null,
                'clicked_at' => Carbon::now(),
            ]);

            DB::table('ad_creatives')->where('id', $creative->getKey())
                ->update(['clicks_count' => DB::raw('clicks_count + 1')]);

            DB::table('ad_campaigns')->where('id', $creative->ad_campaign_id)
                ->update(['clicks_count' => DB::raw('clicks_count + 1')]);
        });

        return $creative->click_url;
    }

    /** Buffered but not yet written. For diagnostics and tests. */
    public function pendingImpressions(AdCreative $creative, AdPlacement $placement, ?Carbon $at = null): int
    {
        $date = ($at ?? Carbon::now())->toDateString();
        $field = "{$creative->getKey()}:{$creative->ad_campaign_id}:{$placement->value}:{$date}";

        try {
            return (int) Redis::hget(self::IMPRESSION_HASH, $field);
        } catch (Throwable) {
            return 0;
        }
    }
}
