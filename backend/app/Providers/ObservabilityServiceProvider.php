<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Production logging and tracing wiring.
 *
 * Three things happen here:
 *  1. Slow queries are logged with their bindings redacted.
 *  2. Queue jobs are logged with timing and failure detail.
 *  3. The request correlation id is carried into queued jobs, so a user report
 *     can be traced from the HTTP request through the worker that finished the
 *     work asynchronously. Without this the trail stops at the job boundary.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->logSlowQueries();
        $this->propagateRequestIdIntoJobs();
        $this->logQueueActivity();
    }

    private function logSlowQueries(): void
    {
        $threshold = (int) config('saka.observability.slow_query_ms');

        if ($threshold <= 0 || $this->app->runningUnitTests()) {
            return;
        }

        DB::listen(function (QueryExecuted $query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::warning('db.slow_query', [
                'sql' => $query->sql,
                // Bindings are NOT logged: they routinely contain emails,
                // phone numbers and password hashes.
                'bindings_count' => count($query->bindings),
                'time_ms' => $query->time,
                'connection' => $query->connectionName,
            ]);
        });
    }

    /**
     * Carries `request_id` from the web request into every job it dispatches,
     * and restores it into the log context inside the worker.
     */
    private function propagateRequestIdIntoJobs(): void
    {
        Queue::createPayloadUsing(function (): array {
            $requestId = request()->attributes->get('request_id');

            return $requestId !== null ? ['request_id' => $requestId] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            $requestId = $event->job->payload()['request_id'] ?? null;

            Log::shareContext(array_filter([
                'request_id' => $requestId,
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
            ]));
        });
    }

    private function logQueueActivity(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        Queue::after(function (JobProcessed $event): void {
            Log::info('queue.job_processed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
            ]);
        });

        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job_failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });
    }
}
