<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Prometheus text-format metrics.
 *
 * Deliberately NOT public: it exposes inventory volume and operational state.
 * Gated on a shared secret so a scraper can reach it without a user account.
 *
 * Values are cached for 30s — a scrape must never be able to hammer the
 * database, and Prometheus scrapes far more often than these numbers change.
 */
class MetricsController extends Controller
{
    public function __invoke(): Response
    {
        $metrics = Cache::remember(CacheKeys::METRICS, now()->addSeconds(30), fn () => $this->collect());

        return response($this->render($metrics), 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    /** @return array<string, array{help: string, type: string, value: float, labels?: array<string,string>}> */
    private function collect(): array
    {
        $byStatus = Listing::query()
            ->groupBy('status')->selectRaw('status, COUNT(*) c')
            ->pluck('c', 'status')->all();

        $metrics = [];

        foreach (ListingStatus::cases() as $status) {
            $metrics['saka_listings_total{status="'.$status->value.'"}'] = [
                'help' => 'Listings by status', 'type' => 'gauge',
                'value' => (float) ($byStatus[$status->value] ?? 0),
            ];
        }

        $metrics['saka_users_total'] = ['help' => 'Registered users', 'type' => 'gauge', 'value' => (float) User::count()];
        $metrics['saka_users_phone_verified_total'] = ['help' => 'Users with a verified phone', 'type' => 'gauge',
            'value' => (float) User::whereNotNull('phone_verified_at')->count()];
        $metrics['saka_inquiries_total'] = ['help' => 'Inquiries received', 'type' => 'gauge', 'value' => (float) Inquiry::count()];
        $metrics['saka_inquiries_unread'] = ['help' => 'Inquiries awaiting a seller', 'type' => 'gauge',
            'value' => (float) Inquiry::unread()->count()];
        $metrics['saka_reviews_pending_moderation'] = ['help' => 'Reviews awaiting moderation', 'type' => 'gauge',
            'value' => (float) Review::where('status', ReviewStatus::Pending)->count()];
        $metrics['saka_listings_pending_moderation'] = ['help' => 'Listings awaiting moderation', 'type' => 'gauge',
            'value' => (float) Listing::where('status', ListingStatus::PendingReview)->count()];

        // Queue depth per connection — the single most useful operational
        // number: a rising backlog is the earliest sign workers are wedged.
        foreach (['default', 'media', 'analytics', 'critical'] as $queue) {
            $metrics['saka_queue_depth{queue="'.$queue.'"}'] = [
                'help' => 'Pending jobs per queue', 'type' => 'gauge',
                'value' => (float) $this->queueDepth($queue),
            ];
        }

        $metrics['saka_failed_jobs_total'] = ['help' => 'Failed jobs', 'type' => 'gauge',
            'value' => (float) DB::table('failed_jobs')->count()];

        return $metrics;
    }

    private function queueDepth(string $queue): int
    {
        try {
            return (int) Redis::connection()->llen('queues:'.$queue);
        } catch (Throwable) {
            return -1; // Redis unreachable; -1 is distinguishable from "empty"
        }
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function render(array $metrics): string
    {
        $lines = [];
        $documented = [];

        foreach ($metrics as $name => $meta) {
            $base = strtok($name, '{');

            if (! isset($documented[$base])) {
                $lines[] = "# HELP {$base} {$meta['help']}";
                $lines[] = "# TYPE {$base} {$meta['type']}";
                $documented[$base] = true;
            }

            $lines[] = "{$name} {$meta['value']}";
        }

        return implode("\n", $lines)."\n";
    }
}
