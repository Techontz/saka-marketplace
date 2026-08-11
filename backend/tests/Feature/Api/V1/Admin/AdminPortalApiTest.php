<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Listing\Enums\ListingStatus;
use App\Models\AuditEvent;
use App\Models\HomepageBanner;
use App\Models\Listing;
use App\Models\PublicPlaceCategory;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Milestone 11 admin API: dashboard, listing administration, homepage CMS,
 * public places, system controls and the audit trail.
 *
 * The emphasis is on the things that would be expensive to get wrong: the
 * grading of destructive actions, partial failure in bulk operations, and the
 * privilege boundaries. A dashboard returning a slightly stale count is a
 * nuisance; a moderator permanently deleting a row, or a bulk action rolling
 * back 49 good approvals because the 50th was invalid, is not.
 */
class AdminPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function superAdmin(): User
    {
        return User::where('email', config('saka.seeding.admin_email'))->firstOrFail();
    }

    private function moderator(): User
    {
        $user = User::factory()->create();
        $user->assignRole('moderator');

        return $user;
    }

    private function listing(ListingStatus $status = ListingStatus::Published): Listing
    {
        $seller = User::factory()->seller()->create();

        return Listing::factory()->ownedBy($seller)->status($status)->create();
    }

    // ------------------------------------------------------------- dashboard

    #[Test]
    public function the_overview_returns_integer_counters(): void
    {
        $this->listing();

        $data = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/stats/overview')
            ->assertOk()
            ->json('data');

        // MySQL returns COUNT/SUM as strings. A client doing arithmetic on
        // "6" + 1 gets "61", so the cast at the boundary is load-bearing.
        foreach ([...array_values($data['users']), ...array_values($data['listings'])] as $value) {
            $this->assertIsInt($value);
        }
    }

    #[Test]
    public function revenue_is_reported_as_unavailable_rather_than_zero(): void
    {
        $revenue = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/stats/overview')
            ->assertOk()
            ->json('data.revenue');

        // "TZS 0" reads as a platform that has taken no money. This one says
        // it cannot answer, which is the truth until payments ship.
        $this->assertFalse($revenue['available']);
        $this->assertNotEmpty($revenue['reason']);
    }

    #[Test]
    public function growth_series_have_one_point_per_day_including_empty_days(): void
    {
        $data = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/stats/growth?days=14')
            ->assertOk()
            ->json('data');

        foreach (['listings', 'users', 'vendors', 'inquiries', 'views'] as $series) {
            $this->assertCount(
                14,
                $data[$series],
                "[{$series}] is not gap-filled. A sparse series makes a chart draw a ".
                'straight line across days with no activity, which reads as steady traffic.',
            );
        }
    }

    #[Test]
    public function analytics_require_the_analytics_permission(): void
    {
        $buyer = User::factory()->buyer()->create();

        $this->actingAs($buyer, 'sanctum')
            ->getJson('/api/v1/admin/stats/overview')
            ->assertForbidden();
    }

    // -------------------------------------------------------------- listings

    #[Test]
    public function the_admin_index_sees_every_status_unlike_the_public_one(): void
    {
        $this->listing(ListingStatus::Draft);
        $this->listing(ListingStatus::Rejected);
        $this->listing(ListingStatus::Published);

        /*
         * The guest view FIRST, before any actingAs.
         *
         * `actingAs` persists on the test case, and the public endpoint's
         * VisibilityScope deliberately shows staff everything — so measuring
         * "public" while still authenticated as an admin compares the admin
         * view to itself. That is what this test originally did, and it
         * silently proved nothing.
         */
        $guest = $this->getJson('/api/v1/listings')->assertOk()->json('meta.total');

        $admin = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/listings')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(0, $guest, 'A guest must not see draft or rejected listings.');
        $this->assertSame(3, $admin, 'Moderation must see every status.');
    }

    #[Test]
    public function listings_can_be_filtered_by_status_and_searched_by_partial_title(): void
    {
        $listing = $this->listing(ListingStatus::Draft);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/listings?status=draft')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Infix, which FULLTEXT cannot do — moderators search fragments.
        $fragment = substr($listing->title, 3, 8);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/listings?q='.urlencode($fragment))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function an_invalid_status_transition_is_rejected_with_the_allowed_set(): void
    {
        $listing = $this->listing(ListingStatus::Published);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/transition", ['status' => 'draft'])
            ->assertStatus(409);

        // Telling the client only "no" forces it to guess; the allowed set is
        // what lets the UI render the right buttons.
        $this->assertNotEmpty($response->json('error.details.allowed'));
    }

    #[Test]
    public function deleting_a_listing_is_reversible(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/listings/{$listing->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('listings', ['id' => $listing->id]);

        // Still reachable in admin — the deleted one is precisely the listing a
        // moderator needs to look at.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/admin/listings/{$listing->uuid}")
            ->assertOk()
            ->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('listings', ['id' => $listing->id]);
    }

    #[Test]
    public function permanent_deletion_is_refused_to_an_ordinary_administrator(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/listings/{$listing->uuid}/force")
            ->assertForbidden();

        $this->assertDatabaseHas('listings', ['id' => $listing->id]);
    }

    #[Test]
    public function a_super_admin_can_permanently_delete_and_it_is_audited_first(): void
    {
        $listing = $this->listing();
        $uuid = $listing->uuid;

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->deleteJson("/api/v1/admin/listings/{$uuid}/force")
            ->assertOk();

        $this->assertDatabaseMissing('listings', ['id' => $listing->id]);

        // The row is gone, so the audit entry is the only remaining record of
        // what was destroyed — it has to carry the detail, not just the id.
        $event = AuditEvent::where('action', 'listing.force_deleted')->latest('id')->first();

        $this->assertNotNull($event);
        $this->assertSame($uuid, $event->before['uuid']);
        $this->assertNotEmpty($event->before['title']);
    }

    #[Test]
    public function a_bulk_action_reports_partial_failure_instead_of_aborting(): void
    {
        $ok = $this->listing(ListingStatus::PendingReview);
        // Already published: published -> published is not a legal transition.
        $bad = $this->listing(ListingStatus::Published);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/listings/bulk', [
                'action' => 'approve',
                'uuids' => [$ok->uuid, $bad->uuid],
            ])
            ->assertOk();

        // A moderator clearing a queue wants the ones that worked, plus a list
        // of what did not — not a rollback of everything.
        $this->assertSame([$ok->uuid], $response->json('data.succeeded'));
        $this->assertCount(1, $response->json('data.failed'));
        $this->assertSame(ListingStatus::Published, $ok->fresh()->status);
    }

    #[Test]
    public function bulk_cannot_be_used_to_permanently_delete(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/listings/bulk', [
                'action' => 'force_delete',
                'uuids' => [$listing->uuid],
            ])
            ->assertStatus(422);
    }

    // --------------------------------------------------------------- vendors

    #[Test]
    public function requesting_more_information_keeps_the_request_pending(): void
    {
        $verification = VerificationRequest::factory()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/verifications/{$verification->uuid}/request-info", [
                'message' => 'The ID photo is cut off — please re-upload it in full.',
            ])
            ->assertOk();

        $fresh = $verification->fresh();

        // Still actionable. Rejecting a seller whose photo was merely blurry is
        // the wrong outcome, and so is leaving the request in limbo.
        $this->assertSame('pending', $fresh->status->value);
        $this->assertNull($fresh->reviewed_at);
        $this->assertStringContainsString('cut off', (string) $fresh->rejection_reason);
    }

    // ----------------------------------------------------------------- users

    #[Test]
    public function an_admin_sends_a_reset_link_rather_than_setting_a_password(): void
    {
        Notification::fake();

        $victim = User::factory()->buyer()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/users/{$victim->uuid}/password-reset")
            ->assertOk();

        // No endpoint anywhere accepts a new password for another account: an
        // admin who can set one can sign in as that user, and no audit trail
        // can tell that apart from the real person.
        Notification::assertSentTo($victim, ResetPassword::class);
    }

    #[Test]
    public function user_activity_shows_both_directions(): void
    {
        $admin = $this->admin();
        $victim = User::factory()->buyer()->create();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/users/{$victim->uuid}/status", ['status' => 'suspended'])
            ->assertOk();

        $received = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$victim->uuid}/activity")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($received);
        $this->assertSame('received', $received[0]['direction']);

        $performed = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/users/{$admin->uuid}/activity")
            ->assertOk()
            ->json('data');

        $this->assertSame('performed', $performed[0]['direction']);
    }

    // ------------------------------------------------------------------- CMS

    #[Test]
    public function a_banner_link_must_be_http_or_https(): void
    {
        // A javascript: href stored here executes for every visitor to the
        // homepage — stored XSS with a very wide blast radius.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Malicious',
                'placement' => 'hero',
                'link_url' => 'javascript:alert(document.cookie)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_url');
    }

    #[Test]
    public function a_banner_reports_whether_it_is_live_separately_from_active(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Expired campaign',
                'placement' => 'hero',
                'is_active' => true,
                'starts_at' => now()->subMonth()->toIso8601String(),
                'ends_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertCreated()
            // Active but outside its window. The list has to distinguish these,
            // or an administrator cannot tell why a banner is not showing.
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_live', false);
    }

    #[Test]
    public function a_banner_window_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Impossible',
                'placement' => 'hero',
                'starts_at' => now()->addWeek()->toIso8601String(),
                'ends_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    #[Test]
    public function reordering_banners_applies_the_whole_arrangement(): void
    {
        $first = HomepageBanner::create(['title' => 'A', 'placement' => 'hero', 'position' => 0]);
        $second = HomepageBanner::create(['title' => 'B', 'placement' => 'hero', 'position' => 10]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/banners/reorder', [
                'order' => [$second->uuid, $first->uuid],
            ])
            ->assertOk();

        $this->assertLessThan($first->fresh()->position, $second->fresh()->position);
    }

    #[Test]
    public function a_homepage_section_key_cannot_be_repointed(): void
    {
        // `key` binds to a React component. Renaming it orphans the section
        // rather than renaming it, so the API refuses to accept the field.
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/v1/admin/sections/trending', ['key' => 'hijacked'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    #[Test]
    public function sections_cannot_be_created_or_deleted(): void
    {
        // Only PATCH and reorder exist. A section with no component renders
        // nothing while looking like a bug.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/sections', ['key' => 'invented', 'title' => 'New'])
            ->assertStatus(405);
    }

    // ---------------------------------------------------------------- places

    #[Test]
    public function creating_a_place_recounts_its_category(): void
    {
        $category = PublicPlaceCategory::first();
        $before = $category->place_count;

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/places', [
                'name' => 'Test Clinic',
                'public_place_category_id' => $category->id,
            ])
            ->assertCreated();

        $this->assertSame($before + 1, $category->fresh()->place_count);
    }

    #[Test]
    public function moving_a_place_recounts_both_categories(): void
    {
        $categories = PublicPlaceCategory::limit(2)->get();
        [$from, $to] = [$categories[0], $categories[1]];

        $slug = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/places', [
                'name' => 'Relocating Place',
                'public_place_category_id' => $from->id,
            ])
            ->json('data.slug');

        $fromBefore = $from->fresh()->place_count;
        $toBefore = $to->fresh()->place_count;

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/admin/places/{$slug}", ['public_place_category_id' => $to->id])
            ->assertOk();

        // Recounting only the destination leaves the origin overstated forever.
        $this->assertSame($fromBefore - 1, $from->fresh()->place_count);
        $this->assertSame($toBefore + 1, $to->fresh()->place_count);
    }

    #[Test]
    public function a_category_holding_places_cannot_be_deleted(): void
    {
        $category = PublicPlaceCategory::whereHas('places')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/place-categories/{$category->slug}")
            ->assertStatus(409);
    }

    // ---------------------------------------------------------------- system

    #[Test]
    public function the_system_report_does_not_leak_configuration(): void
    {
        $body = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson('/api/v1/admin/system')
            ->assertOk()
            ->content();

        // An admin surface is a credential-harvesting target. Knowing the cache
        // driver does not justify exposing the environment.
        foreach (['APP_KEY', 'DB_PASSWORD', 'password', 'secret', 'AWS_'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    #[Test]
    public function cache_clearing_is_restricted_and_targeted(): void
    {
        $this->actingAs($this->moderator(), 'sanctum')
            ->postJson('/api/v1/admin/system/cache', ['target' => 'taxonomy'])
            ->assertForbidden();

        // No "flush everything" target: cache and queue can share a Redis
        // instance, and a FLUSHDB from a settings screen discards queued jobs.
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/system/cache', ['target' => 'flush'])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/system/cache', ['target' => 'taxonomy'])
            ->assertOk();
    }

    // ----------------------------------------------------------------- audit

    #[Test]
    public function administrative_actions_are_recorded(): void
    {
        $listing = $this->listing(ListingStatus::PendingReview);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/listings/{$listing->uuid}/transition", [
                'status' => 'published',
                'reason' => 'Looks fine',
            ])
            ->assertOk();

        $event = AuditEvent::where('action', 'listing.status_changed')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $event->actor_id);
        // Denormalised, so the entry survives deletion of the account — which
        // is exactly when you need to know who did it.
        $this->assertSame($admin->email, $event->actor_label);
        $this->assertSame('pending_review', $event->before['status']);
        $this->assertSame('published', $event->after['status']);
    }

    #[Test]
    public function the_audit_chain_links_each_entry_to_its_predecessor(): void
    {
        $logger = app(AuditLogger::class);
        $admin = $this->admin();

        $logger->record('test.one', $admin);
        $logger->record('test.two', $admin);
        $logger->record('test.three', $admin);

        $events = AuditEvent::orderBy('id')->get();

        for ($i = 1; $i < $events->count(); $i++) {
            $this->assertSame(
                $events[$i - 1]->hash,
                $events[$i]->prev_hash,
                'The audit chain is broken. An entry edited or deleted directly in the database '.
                'must invalidate every hash after it — that is the whole tamper-evidence property.',
            );
        }
    }

    #[Test]
    public function the_audit_log_never_records_credentials(): void
    {
        $logger = app(AuditLogger::class);

        $logger->record('test.sensitive', $this->admin(), null, [], [
            'email' => 'someone@example.com',
            'password' => 'hunter2',
            'remember_token' => 'abc123',
        ]);

        $event = AuditEvent::where('action', 'test.sensitive')->firstOrFail();

        // A password hash in an audit row is a permanent offline-cracking
        // target; a token is a live credential.
        $this->assertArrayHasKey('email', $event->after);
        $this->assertArrayNotHasKey('password', $event->after);
        $this->assertArrayNotHasKey('remember_token', $event->after);
    }

    #[Test]
    public function a_failing_audit_write_is_swallowed_not_thrown(): void
    {
        /*
         * The write is made to fail for real, by exceeding `action`'s
         * varchar(100) under MySQL strict mode.
         *
         * Dropping the table would be the obvious way to simulate this and is
         * wrong: DDL implicitly COMMITs, which detonates RefreshDatabase's
         * surrounding transaction and fails the test with a savepoint error
         * that has nothing to do with the behaviour under test.
         *
         * The trail matters. It does not matter more than the platform
         * continuing to serve requests.
         */
        $result = app(AuditLogger::class)->record(str_repeat('x', 300), $this->admin());

        $this->assertNull($result, 'A failed audit write must be swallowed and logged, not thrown.');
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[Test]
    public function reading_the_audit_log_requires_its_own_permission(): void
    {
        // Strictly more sensitive than a chart of signups: it is who did what
        // to whom, so `analytics.view` is not enough.
        $this->actingAs($this->moderator(), 'sanctum')
            ->getJson('/api/v1/admin/activity')
            ->assertForbidden();
    }
}
