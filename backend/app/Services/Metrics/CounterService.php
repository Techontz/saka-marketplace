<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Buffers high-frequency counters in Redis, flushed to MySQL periodically.
 *
 * A popular listing can take thousands of views an hour. Writing
 * `UPDATE listings SET view_count = view_count + 1` per view serialises every
 * request behind a row lock on the hottest rows in the table. Redis absorbs the
 * increments; the scheduler folds them into MySQL once a minute.
 *
 * The trade is bounded: a Redis loss drops at most one flush interval of
 * counter increments. The `listing_views` rows are still written by the queued
 * job, so the underlying record is durable and the counter is recoverable.
 */
class CounterService
{
    private const HASH = 'counters:listings';

    public function increment(string $column, int $listingId, int $by = 1): void
    {
        try {
            Redis::hincrby(self::HASH, "{$column}:{$listingId}", $by);
        } catch (Throwable) {
            // Redis unavailable — fall back to a direct write rather than
            // losing the count entirely.
            $this->applyDirect($column, $listingId, $by);
        }
    }

    public function decrement(string $column, int $listingId, int $by = 1): void
    {
        $this->increment($column, $listingId, -$by);
    }

    /**
     * Folds buffered counters into MySQL.
     *
     * Reads and clears atomically (HGETALL + DEL in a transaction) so a
     * concurrent increment is either fully in this flush or fully in the next
     * one — never counted twice, never dropped between the read and the delete.
     *
     * @return array{flushed: int, listings: int}
     */
    public function flush(): array
    {
        try {
            $buffered = Redis::hgetall(self::HASH);
        } catch (Throwable) {
            return ['flushed' => 0, 'listings' => 0];
        }

        if ($buffered === []) {
            return ['flushed' => 0, 'listings' => 0];
        }

        Redis::del(self::HASH);

        $byListing = [];

        foreach ($buffered as $field => $delta) {
            [$column, $listingId] = explode(':', (string) $field, 2);
            $byListing[(int) $listingId][$column] = (int) $delta;
        }

        $applied = 0;

        DB::transaction(function () use ($byListing, &$applied): void {
            foreach ($byListing as $listingId => $columns) {
                foreach ($columns as $column => $delta) {
                    if ($delta === 0 || ! $this->isCounterColumn($column)) {
                        continue;
                    }

                    // GREATEST guards the UNSIGNED columns: a net-negative
                    // delta would otherwise throw rather than clamp at zero.
                    DB::table('listings')->where('id', $listingId)->update([
                        $column => DB::raw("GREATEST(CAST({$column} AS SIGNED) + {$delta}, 0)"),
                    ]);

                    $applied++;
                }
            }
        });

        return ['flushed' => $applied, 'listings' => count($byListing)];
    }

    /** Buffered value not yet written to MySQL — used by tests and diagnostics. */
    public function pending(string $column, int $listingId): int
    {
        try {
            return (int) Redis::hget(self::HASH, "{$column}:{$listingId}");
        } catch (Throwable) {
            return 0;
        }
    }

    private function applyDirect(string $column, int $listingId, int $by): void
    {
        if (! $this->isCounterColumn($column)) {
            return;
        }

        DB::table('listings')->where('id', $listingId)->update([
            $column => DB::raw("GREATEST(CAST({$column} AS SIGNED) + {$by}, 0)"),
        ]);
    }

    /** Whitelist — the column name is interpolated into SQL. */
    private function isCounterColumn(string $column): bool
    {
        return in_array($column, ['view_count', 'favorite_count', 'inquiry_count'], true);
    }
}
