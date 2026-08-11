<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\System;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The state every other test is written to avoid: schema migrated, nothing
 * seeded.
 *
 * This is not a hypothetical. It shipped. `deploy.sh` runs `migrate --force`
 * and never seeds, so production served `/categories`, `/listings` and
 * `/health/ready` with a 200 while every single registration returned a 500 —
 * `AuthService::register()` assigns the `buyer` role, and there were no roles.
 *
 * Note the deliberate absence of `protected bool $seed = true` here. Every
 * existing auth test sets it, which is exactly why a green suite of 534 said
 * nothing about a marketplace where nobody could create an account.
 */
class UnseededDeploymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Put this connection into the unseeded state, inside the test's own
     * transaction.
     *
     * Not simply "don't set $seed": RefreshDatabase migrates once per PROCESS
     * and commits whatever the first class asked for, so by the time this class
     * runs in a full suite the roles are already there. Running alone it would
     * pass for the wrong reason — which is the failure mode this whole file
     * exists to catch.
     */
    private function withoutReferenceData(): void
    {
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();

        // Spatie resolves roles through a cache; without this the deleted role
        // is still found and nothing reproduces.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(0, DB::table('roles')->count(), 'precondition: unseeded');
    }

    #[Test]
    public function readiness_refuses_to_report_ready_without_reference_data(): void
    {
        $this->withoutReferenceData();

        $response = $this->getJson('/api/v1/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.reference_data.ok', false);

        // The operator is told what to do, not merely that something is wrong.
        $this->assertStringContainsString(
            'db:seed',
            $response->json('data.checks.reference_data.hint'),
        );
    }

    #[Test]
    public function readiness_passes_reference_data_once_seeded(): void
    {
        $this->seed(RolePermissionSeeder::class);

        // Asserted on its own rather than through the overall status, so this
        // still means something on a machine with no Redis.
        $this->getJson('/api/v1/health/ready')
            ->assertJsonPath('data.checks.reference_data.ok', true);
    }

    /**
     * The failure that reached users as "Something went wrong. Please try
     * again." — the WEB client's fallback string, used only when the body
     * carries no SAKA envelope at all.
     */
    #[Test]
    public function an_unhandled_exception_still_answers_in_the_error_envelope(): void
    {
        $this->withoutReferenceData();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Asha',
            'last_name' => 'Mbwana',
            'email' => 'asha@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR')
            // Without this the client has nothing to quote in a bug report and
            // the log line cannot be found.
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertNotEmpty($response->json('error.request_id'));

        // The transaction rolled back: no half-built, role-less account is left
        // behind for the visitor to trip over when they try again.
        $this->assertDatabaseMissing('users', ['email' => 'asha@example.com']);
    }

    #[Test]
    public function the_envelope_does_not_leak_internals_when_debug_is_off(): void
    {
        $this->withoutReferenceData();
        config(['app.debug' => false]);

        $body = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Asha',
            'email' => 'asha@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(500)->getContent();

        // No class names, no file paths, no stack frames.
        $this->assertStringNotContainsString('Spatie', $body);
        $this->assertStringNotContainsString('vendor/', $body);
        $this->assertStringNotContainsString('guard', $body);
    }
}
