<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rate limits are a security control, so they get tests. These run against the
 * array cache store; in production the same limiters are Redis-backed so a
 * limit holds across every app server rather than per process.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    #[Test]
    public function repeated_failed_logins_are_throttled(): void
    {
        $payload = ['email' => 'seller@saka.test', 'password' => 'wrong-password'];

        // Limiter allows 5/min for this email+ip pair.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    #[Test]
    public function a_throttled_response_still_uses_the_standard_envelope(): void
    {
        $payload = ['email' => 'seller@saka.test', 'password' => 'wrong-password'];

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/auth/login', $payload);
        }

        $response->assertStatus(429)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    #[Test]
    public function the_liveness_endpoint_touches_no_dependency(): void
    {
        $this->getJson('/api/v1/health/live')
            ->assertOk()
            ->assertJsonPath('data.status', 'alive')
            ->assertJsonPath('data.version', 'v1');
    }

    #[Test]
    public function the_readiness_endpoint_reports_each_dependency(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonStructure(['data' => ['status', 'checks' => ['database' => ['ok', 'latency_ms']]]]);
    }

    #[Test]
    public function the_metrics_endpoint_is_not_public(): void
    {
        // Metrics expose inventory volume and queue depth — a wrong or absent
        // token must look like the route does not exist.
        $this->getJson('/api/v1/metrics')->assertStatus(404);
        $this->withHeader('X-Metrics-Token', 'wrong')->getJson('/api/v1/metrics')->assertStatus(404);
    }

    #[Test]
    public function the_metrics_endpoint_serves_prometheus_text_with_a_valid_token(): void
    {
        config()->set('saka.observability.metrics_token', 'test-token');

        $response = $this->withHeader('X-Metrics-Token', 'test-token')->get('/api/v1/metrics');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('saka_listings_total', $response->getContent());
        $this->assertStringContainsString('saka_queue_depth', $response->getContent());
    }

    #[Test]
    public function every_response_carries_the_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health/live')->assertOk();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));

        // Anonymous responses stay cacheable-by-the-browser but never shared.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function authenticated_responses_are_never_stored(): void
    {
        $user = User::where('email', 'buyer@saka.test')->firstOrFail();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/account/profile')->assertOk();

        // Dashboards and inquiries carry personal data; no intermediary or
        // browser should write them to disk.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function an_unknown_route_returns_the_standard_not_found_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    #[Test]
    public function an_inbound_request_id_is_echoed_back(): void
    {
        $response = $this->withHeader('X-Request-Id', 'trace-abc-123')
            ->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame('trace-abc-123', $response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function a_malicious_request_id_is_not_echoed(): void
    {
        $response = $this->withHeader('X-Request-Id', '<script>alert(1)</script>')
            ->getJson('/api/v1/health');

        // Never reflect arbitrary client input into logs and response headers.
        $this->assertNotSame('<script>alert(1)</script>', $response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8,64}$/', $response->headers->get('X-Request-Id'));
    }
}
