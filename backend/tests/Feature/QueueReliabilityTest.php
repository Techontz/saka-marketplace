<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RetryFailedJobs;
use App\Domain\Identity\Enums\RoleSlug;
use App\Jobs\GenerateImageVariants;
use App\Jobs\RecordListingView;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\ListingView;
use App\Models\User;
use App\Notifications\NewInquiryNotification;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guards the queue contract itself.
 *
 * Two production defects motivated this file:
 *
 *  1. A `queue(): string` method on a job. Laravel's dispatcher treats a
 *     `queue()` method as CUSTOM QUEUEING LOGIC and calls it INSTEAD of
 *     pushing, so view tracking and image processing were silently dead —
 *     no error, no failed job, nothing in Redis.
 *
 *  2. Horizon's stock config supervises only `default`. Jobs assigned to
 *     `media` and `analytics` would have been pushed successfully and then sat
 *     in Redis forever, because nothing was consuming those queues.
 *
 * Both are invisible in normal feature tests, which fake the queue and assert
 * only that a job was dispatched.
 */
class QueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Every queue a job targets must have a Horizon supervisor consuming it. */
    public function test_horizon_supervises_every_queue_that_jobs_are_dispatched_to(): void
    {
        $supervised = [];

        foreach (config('horizon.defaults') as $supervisor) {
            foreach ((array) $supervisor['queue'] as $queue) {
                $supervised[] = $queue;
            }
        }

        foreach ($this->queuesUsedByJobs() as $queue => $job) {
            $this->assertContains(
                $queue,
                $supervised,
                "Job [{$job}] is dispatched to the [{$queue}] queue, but no Horizon supervisor consumes it. "
                .'The job would be pushed successfully and never run.',
            );
        }
    }

    /**
     * A `queue()` method on a queueable is a silent job-swallowing trap.
     *
     * @see Dispatcher::dispatchToQueue()
     */
    public function test_no_queueable_defines_a_queue_method(): void
    {
        foreach ($this->queueableClasses() as $class) {
            $this->assertFalse(
                method_exists($class, 'queue'),
                "[{$class}] defines a queue() method. Laravel calls it as custom queueing logic "
                .'instead of pushing the job, which discards it silently. Use $this->onQueue() in the constructor.',
            );
        }
    }

    public function test_jobs_land_on_their_declared_queues_when_dispatched(): void
    {
        Queue::fake();

        RecordListingView::dispatch(1, str_repeat('a', 64));
        GenerateImageVariants::dispatch(1);

        Queue::assertPushedOn('analytics', RecordListingView::class);
        Queue::assertPushedOn('media', GenerateImageVariants::class);
    }

    /**
     * The view job is replayed automatically by `saka:queue:retry`, so a second
     * run of the same payload must not double-count.
     */
    public function test_record_listing_view_is_idempotent_on_replay(): void
    {
        $seller = User::factory()->seller()->create();
        $listing = Listing::factory()->for($seller)->create(['view_count' => 0]);
        $ipHash = hash('sha256', '127.0.0.1');

        $job = new RecordListingView($listing->id, $ipHash);

        $job->handle();
        $job->handle(); // the replay

        $this->flushCounters();

        $this->assertSame(1, ListingView::where('listing_id', $listing->id)->count());
        $this->assertSame(1, (int) $listing->fresh()->view_count);
    }

    /** A missing row must be a no-op, not a retry loop against a deleted record. */
    public function test_image_variant_job_tolerates_a_deleted_media_row(): void
    {
        $job = new GenerateImageVariants(999_999);

        $job->handle();

        $this->assertTrue(true, 'handle() returned without throwing.');
    }

    public function test_replayable_job_list_only_contains_idempotent_jobs(): void
    {
        $replayable = (new ReflectionClass(RetryFailedJobs::class))
            ->getConstant('REPLAYABLE');

        // Both are idempotent by construction: RecordListingView is absorbed by
        // a unique key, GenerateImageVariants overwrites its own output.
        $this->assertSame(
            [GenerateImageVariants::class, RecordListingView::class],
            $replayable,
            'Adding a job here makes it eligible for automatic replay. Only add idempotent jobs.',
        );
    }

    /** Notifications must be queued so a dead mail server cannot fail a write. */
    public function test_inquiry_notification_is_queued(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new NewInquiryNotification(new Inquiry),
        );
    }

    // ------------------------------------------------------------- horizon

    public function test_horizon_gate_denies_ordinary_users(): void
    {
        $buyer = User::factory()->buyer()->create();

        $this->assertFalse(Gate::forUser($buyer)->allows('viewHorizon'));
    }

    /**
     * Deliberately stricter than the rest of the admin surface: the dashboard
     * shows raw job payloads, which carry inquiry bodies and seller contact
     * details.
     */
    public function test_horizon_gate_denies_an_ordinary_administrator(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleSlug::Admin->value);

        $this->assertFalse(Gate::forUser($admin)->allows('viewHorizon'));
    }

    public function test_horizon_gate_allows_a_super_administrator(): void
    {
        $superAdmin = User::where('email', config('saka.seeding.admin_email'))->firstOrFail();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewHorizon'));
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array<string, class-string> queue name => the job that uses it
     */
    private function queuesUsedByJobs(): array
    {
        $queues = [];

        foreach ($this->queueableClasses() as $class) {
            $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

            if (preg_match_all("/onQueue\('([a-z_-]+)'\)/", $source, $matches) === 0) {
                // No explicit queue: it lands on the connection default.
                $queues[(string) config('queue.connections.redis.queue')] = $class;

                continue;
            }

            foreach ($matches[1] as $queue) {
                $queues[$queue] = $class;
            }
        }

        return $queues;
    }

    /** @return array<int, class-string> */
    private function queueableClasses(): array
    {
        $classes = [];

        foreach ([app_path('Jobs'), app_path('Notifications')] as $directory) {
            foreach ((array) glob($directory.'/*.php') as $file) {
                $class = 'App\\'.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    substr((string) $file, strlen(app_path()) + 1),
                );

                if (! class_exists($class)) {
                    continue;
                }

                if ((new ReflectionClass($class))->implementsInterface(ShouldQueue::class)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
