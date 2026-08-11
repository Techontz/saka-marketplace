<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Metrics\CounterService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Counters live in Redis, which RefreshDatabase cannot roll back.
        // Clearing them per test keeps runs independent.
        try {
            Redis::del('counters:listings');
        } catch (\Throwable) {
            // Redis unavailable — CounterService falls back to direct writes.
        }
    }

    /**
     * Fold buffered counters into the database.
     *
     * View, favourite and inquiry counts are buffered in Redis and flushed by
     * the scheduler once a minute, so they are EVENTUALLY consistent. Tests
     * call this explicitly rather than asserting an immediate write — which
     * keeps the asynchronous contract visible instead of hidden.
     */
    protected function flushCounters(): void
    {
        app(CounterService::class)->flush();
    }

    /**
     * Re-resolve authentication whenever a request carries its OWN credentials.
     *
     * Laravel reuses one container per test method and Sanctum's RequestGuard
     * caches the resolved user in a property. Without this, a second call in the
     * same test keeps the user resolved by the first — so a revoked token or a
     * mid-test ban would appear to still work and a real regression would be
     * invisible. In production every request gets a fresh container.
     *
     * Scoped to requests carrying an Authorization header on purpose:
     * `actingAs()` deliberately installs a user on the guard, and blanket-
     * forgetting guards would wipe it. Credentials on the wire must be
     * re-verified; an explicitly impersonated user must not be.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): TestResponse
    {
        if ($this->requestCarriesCredentials($server)) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** @param array<string, mixed> $server */
    private function requestCarriesCredentials(array $server): bool
    {
        if (isset($server['HTTP_AUTHORIZATION'])) {
            return true;
        }

        foreach (array_keys($this->defaultHeaders) as $header) {
            if (strcasecmp((string) $header, 'Authorization') === 0) {
                return true;
            }
        }

        return false;
    }
}
