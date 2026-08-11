<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Listing\Enums\ListingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes `listings.popularity_score`, which orders the Trending surface.
 *
 * The score blends recent engagement with age so a listing published a year ago
 * cannot sit at the top forever on accumulated views:
 *
 *   score = (views_30d * 1) + (favorites * 5) + (inquiries * 10)
 *           weighted by a recency decay over the last 60 days
 *
 * Inquiries are weighted heaviest because they are the closest thing to intent
 * this marketplace has — a view is cheap, a message is not.
 *
 * Runs as ONE set-based UPDATE per chunk rather than per-listing writes; at
 * 100k listings the row-by-row version takes minutes and holds locks the whole
 * time.
 */
class RecomputePopularity extends Command
{
    protected $signature = 'saka:listings:popularity {--days=30} {--chunk=5000}';

    protected $description = 'Recompute listing popularity scores';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $chunk = (int) $this->option('chunk');
        $since = now()->subDays($days)->toDateString();

        $updated = 0;
        $lastId = 0;

        do {
            $ids = DB::table('listings')
                ->where('id', '>', $lastId)
                ->whereIn('status', [ListingStatus::Published->value, ListingStatus::Paused->value])
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $lastId = (int) $ids->last();

            $affected = DB::update(<<<'SQL'
                UPDATE listings l
                LEFT JOIN (
                    SELECT listing_id, SUM(views) AS recent_views
                    FROM listing_view_daily
                    WHERE date >= ?
                    GROUP BY listing_id
                ) v ON v.listing_id = l.id
                SET l.popularity_score = ROUND(
                    (
                        COALESCE(v.recent_views, 0) * 1.0
                        + l.favorite_count * 5.0
                        + l.inquiry_count * 10.0
                    )
                    /* Recency decay: halves roughly every 60 days since publish. */
                    * (1 / (1 + GREATEST(DATEDIFF(NOW(), COALESCE(l.published_at, l.created_at)), 0) / 60))
                , 4)
                WHERE l.id IN (
            SQL.$ids->map(fn () => '?')->implode(',').')',
                array_merge([$since], $ids->all()),
            );

            $updated += $affected;
        } while ($ids->count() === $chunk);

        $this->info("Recomputed popularity for {$updated} listing(s).");

        return self::SUCCESS;
    }
}
