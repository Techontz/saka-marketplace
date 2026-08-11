<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateImageVariants;
use App\Jobs\RecordListingView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Selective failed-job recovery.
 *
 * `queue:retry all` is a blunt instrument: it replays poison messages that will
 * fail again, and jobs so old their referenced rows are gone. This retries only
 * jobs that (a) failed inside a recent window, and (b) are of a class known to
 * be safely replayable — every such job is idempotent by construction.
 *
 * Anything outside that set is left for a human, which is the correct default
 * for automation that mutates data.
 */
class RetryFailedJobs extends Command
{
    protected $signature = 'saka:queue:retry
                            {--hours=24 : only retry jobs that failed within this window}
                            {--limit=100}
                            {--dry-run}';

    protected $description = 'Retry recent failed jobs that are safe to replay';

    /**
     * Jobs that may be replayed automatically.
     *
     * Each is idempotent: GenerateImageVariants overwrites its own output, and
     * RecordListingView is absorbed by a unique key on re-insert.
     */
    private const REPLAYABLE = [
        GenerateImageVariants::class,
        RecordListingView::class,
    ];

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        $failed = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->orderBy('failed_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($failed->isEmpty()) {
            $this->info('No recent failed jobs.');

            return self::SUCCESS;
        }

        $retried = 0;
        $skipped = 0;

        foreach ($failed as $job) {
            $class = $this->resolveJobClass($job->payload);

            if ($class === null || ! in_array($class, self::REPLAYABLE, true)) {
                $skipped++;
                $this->line('  skipped (not auto-replayable): '.($class ?? 'unknown'));

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would retry: {$class} [{$job->uuid}]");
                $retried++;

                continue;
            }

            Artisan::call('queue:retry', ['id' => [$job->uuid]]);
            $retried++;
        }

        $this->info("Retried {$retried}, skipped {$skipped}.");

        if ($skipped > 0) {
            Log::warning('queue.failed_jobs_need_review', [
                'skipped' => $skipped,
                'window_hours' => (int) $this->option('hours'),
            ]);
        }

        return self::SUCCESS;
    }

    private function resolveJobClass(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        return $decoded['displayName'] ?? null;
    }
}
