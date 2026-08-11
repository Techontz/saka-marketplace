<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\ServiceMode;
use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SpecialistAvailability;
use App\Models\SpecialistAvailabilityBlock;
use App\Models\SpecialistBooking;
use App\Models\SpecialistService;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The booking engine.
 *
 * The tests that matter are about CORRECTNESS UNDER CONTENTION and about not
 * inventing availability:
 *
 *   - slots come from real working hours, real blocks and real bookings;
 *   - a specialist with no configured hours offers nothing, rather than a
 *     default nine-to-five that would take appointments they never agreed to;
 *   - two customers cannot hold the same slot, and the guarantee is in the
 *     DATABASE rather than in a check-then-write;
 *   - cancelling releases the slot with no cleanup job;
 *   - a customer can cancel and nothing else.
 */
class BookingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const TZ = 'Africa/Dar_es_Salaam';

    /** A published specialist with Monday–Friday 09:00–17:00. */
    private function specialist(array $listingState = []): Listing
    {
        $vendor = User::factory()->seller()->create();
        $category = Category::query()->where('slug', 'lawyers')->firstOrFail();

        $listing = Listing::factory()->published()->ownedBy($vendor)->create(array_merge([
            'category_id' => $category->getKey(),
            'title' => 'Neema Mushi — Commercial lawyer',
            'booking_timezone' => self::TZ,
        ], $listingState));

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            SpecialistAvailability::query()->create([
                'listing_id' => $listing->getKey(),
                'weekday' => $weekday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]);
        }

        return $listing;
    }

    /** The next Tuesday, so a weekday window always applies. */
    private function nextTuesday(): Carbon
    {
        return Carbon::now(self::TZ)->addWeek()->startOfWeek()->addDay()->startOfDay();
    }

    private function bookPayload(SpecialistService $service, Carbon $date, string $time): array
    {
        return [
            'service_uuid' => $service->uuid,
            'date' => $date->toDateString(),
            'start_time' => $time,
            'customer_name' => 'Asha Mwinyi',
            'customer_phone' => '+255712345678',
        ];
    }

    // ------------------------------------------------------------ slot logic

    #[Test]
    public function slots_come_from_real_working_hours(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);

        $slots = app(BookingService::class)->slotsFor($service, $this->nextTuesday());

        // 09:00 to 17:00 in 60-minute steps is eight appointments.
        $this->assertCount(8, $slots);
        $this->assertSame('09:00', $slots[0]['start']);
        $this->assertSame('16:00', $slots[7]['start']);
    }

    #[Test]
    public function a_specialist_with_no_configured_hours_offers_nothing(): void
    {
        $vendor = User::factory()->seller()->create();
        $listing = Listing::factory()->published()->ownedBy($vendor)->create();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);

        /*
         * NOT a default nine-to-five. Inventing hours would take bookings the
         * specialist never agreed to and put a stranger in their diary.
         */
        $this->assertSame([], app(BookingService::class)->slotsFor($service, $this->nextTuesday()));
    }

    #[Test]
    public function the_buffer_reserves_diary_time_without_being_sold_to_the_customer(): void
    {
        $listing = $this->specialist();
        // A 45-minute appointment that occupies an hour of the diary.
        $service = SpecialistService::factory()->minutes(45, 15)->create(['listing_id' => $listing->getKey()]);

        $slots = app(BookingService::class)->slotsFor($service, $this->nextTuesday());

        $this->assertSame('09:00', $slots[0]['start']);
        // The customer is quoted their own 45 minutes...
        $this->assertSame('09:45', $slots[0]['end']);
        // ...while the next slot starts a full hour later.
        $this->assertSame('10:00', $slots[1]['start']);
    }

    #[Test]
    public function a_blocked_period_removes_only_the_slots_it_covers(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        SpecialistAvailabilityBlock::query()->create([
            'listing_id' => $listing->getKey(),
            'starts_at' => $day->copy()->setTime(12, 0)->utc(),
            'ends_at' => $day->copy()->setTime(14, 0)->utc(),
            'reason' => 'Court appearance',
        ]);

        $starts = array_column(app(BookingService::class)->slotsFor($service, $day), 'start');

        // "Thursday afternoon" is a real block, so a block must not take the
        // whole day — only the hours it actually covers.
        $this->assertNotContains('12:00', $starts);
        $this->assertNotContains('13:00', $starts);
        $this->assertContains('11:00', $starts);
        $this->assertContains('14:00', $starts);
    }

    #[Test]
    public function a_weekend_has_no_slots_when_no_weekend_window_exists(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);

        $sunday = $this->nextTuesday()->copy()->next(Carbon::SUNDAY)->startOfDay();

        $this->assertSame([], app(BookingService::class)->slotsFor($service, $sunday));
    }

    #[Test]
    public function past_dates_are_never_bookable(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);

        $this->assertSame(
            [],
            app(BookingService::class)->slotsFor($service, Carbon::now(self::TZ)->subWeek()),
        );
    }

    // -------------------------------------------------------------- booking

    #[Test]
    public function a_guest_can_book_and_the_booking_is_pending(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $response = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))
            ->assertCreated();

        /*
         * PENDING, and the API says so. Telling a customer their appointment is
         * confirmed before a human has agreed is the most damaging thing this
         * flow could do.
         */
        $response->assertJsonPath('data.status', BookingStatus::Pending->value);
        $response->assertJsonPath('meta.requires_confirmation', true);
        $response->assertJsonPath('data.timezone', self::TZ);

        $this->assertDatabaseCount('specialist_bookings', 1);
    }

    #[Test]
    public function a_time_the_specialist_does_not_offer_is_refused(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);

        /*
         * Checking only for a CLASH would let a customer post any time they
         * liked — 03:00 on a Tuesday — and get a booking, because nothing else
         * is scheduled then.
         */
        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $this->nextTuesday(), '03:00'))
            ->assertStatus(409);

        $this->assertDatabaseCount('specialist_bookings', 0);
    }

    #[Test]
    public function an_inactive_service_cannot_be_booked(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->inactive()->create(['listing_id' => $listing->getKey()]);

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $this->nextTuesday(), '10:00'))
            ->assertStatus(422);
    }

    #[Test]
    public function an_unpublished_specialist_cannot_be_booked(): void
    {
        $listing = $this->specialist(['status' => 'draft', 'published_at' => null]);
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);

        // Not even by somebody holding a service uuid from before it came down.
        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $this->nextTuesday(), '10:00'))
            ->assertNotFound();
    }

    // --------------------------------------------------- DOUBLE BOOKING

    #[Test]
    public function the_same_slot_cannot_be_booked_twice(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))->assertCreated();
        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))->assertStatus(409);

        $this->assertDatabaseCount('specialist_bookings', 1);
    }

    #[Test]
    public function the_database_itself_refuses_a_duplicate_slot(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '11:00'))->assertCreated();

        /*
         * The real guarantee, tested WITHOUT going through the service.
         *
         * Application-level checking cannot solve double booking: two requests
         * both read "free" and both insert. This asserts the unique index on
         * (listing_id, slot_key) rejects the second write on its own, so the
         * protection survives any future refactor of the service layer.
         */
        $this->expectException(QueryException::class);

        DB::table('specialist_bookings')->insert([
            'uuid' => (string) Str::uuid7(),
            'listing_id' => $listing->getKey(),
            'specialist_service_id' => $service->getKey(),
            'customer_name' => 'Race Condition',
            'customer_phone' => '+255700000000',
            'scheduled_date' => $day->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'timezone' => self::TZ,
            'starts_at_utc' => $day->copy()->setTime(11, 0)->utc(),
            'ends_at_utc' => $day->copy()->setTime(12, 0)->utc(),
            'status' => BookingStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_overlapping_shorter_booking_is_refused(): void
    {
        $listing = $this->specialist();
        $long = SpecialistService::factory()->minutes(120)->create(['listing_id' => $listing->getKey()]);
        $short = SpecialistService::factory()->minutes(30)->create([
            'listing_id' => $listing->getKey(),
            'name' => 'Quick call',
        ]);

        $day = $this->nextTuesday();

        /*
         * 11:00, not 10:00. A 120-minute service steps the 09:00–17:00 window
         * in two-hour blocks, so its offered starts are 09:00 / 11:00 / 13:00 /
         * 15:00 — booking a time the service does not offer is a different
         * refusal from the one under test here.
         */
        $this->postJson('/api/v1/bookings', $this->bookPayload($long, $day, '11:00'))->assertCreated();

        /*
         * 11:30 has a DIFFERENT slot_key from 11:00, so the unique index cannot
         * see this clash — which is exactly why the locked overlap check is a
         * separate layer rather than a convenience.
         */
        $this->postJson('/api/v1/bookings', $this->bookPayload($short, $day, '11:30'))
            ->assertStatus(409);

        $this->assertDatabaseCount('specialist_bookings', 1);
    }

    #[Test]
    public function cancelling_releases_the_slot_immediately(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $first = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '14:00'))
            ->assertCreated()->json('data.uuid');

        $booking = SpecialistBooking::query()->where('uuid', $first)->firstOrFail();
        app(BookingService::class)->transition($booking, BookingStatus::Cancelled, 'customer');

        /*
         * No cleanup job, no tombstone. `slot_key` is generated from `status`,
         * so cancelling nulls it in the same write — and MySQL permits
         * unlimited NULLs in a unique index, so the time is simply free again.
         */
        $this->assertNull(
            DB::table('specialist_bookings')->where('uuid', $first)->value('slot_key'),
        );

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '14:00'))->assertCreated();
    }

    #[Test]
    public function a_declined_booking_frees_the_time_but_a_no_show_does_not(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $uuid = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '09:00'))
            ->assertCreated()->json('data.uuid');

        $booking = SpecialistBooking::query()->where('uuid', $uuid)->firstOrFail();

        // Confirmed then no-show: the specialist's time was still spent, so the
        // diary keeps the slot.
        app(BookingService::class)->transition($booking, BookingStatus::Confirmed, 'specialist');
        app(BookingService::class)->transition($booking->refresh(), BookingStatus::NoShow, 'specialist');

        $this->assertNotNull(
            DB::table('specialist_bookings')->where('uuid', $uuid)->value('slot_key'),
        );

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '09:00'))->assertStatus(409);
    }

    #[Test]
    public function a_booked_slot_disappears_from_the_offered_times(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))->assertCreated();

        $starts = array_column(app(BookingService::class)->slotsFor($service, $day), 'start');

        $this->assertNotContains('10:00', $starts);
        $this->assertContains('11:00', $starts);
    }

    // ---------------------------------------------------------- transitions

    #[Test]
    public function the_transition_table_is_enforced(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $uuid = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))
            ->assertCreated()->json('data.uuid');

        $booking = SpecialistBooking::query()->where('uuid', $uuid)->firstOrFail();

        // Completing something nobody ever agreed to is meaningless.
        $this->expectException(ApiException::class);

        app(BookingService::class)->transition($booking, BookingStatus::Completed, 'specialist');
    }

    #[Test]
    public function the_specialist_confirms_and_completes_through_their_own_endpoint(): void
    {
        $listing = $this->specialist();
        $vendor = $listing->user;
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);
        $day = $this->nextTuesday();

        $uuid = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))
            ->assertCreated()->json('data.uuid');

        $this->actingAs($vendor)
            ->postJson("/api/v1/seller/specialist/bookings/{$uuid}/transition", [
                'status' => BookingStatus::Confirmed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Confirmed->value)
            // The specialist's view carries the customer's number; without it a
            // booking is useless to them.
            ->assertJsonPath('data.customer.phone', '+255712345678');
    }

    #[Test]
    public function a_vendor_cannot_touch_another_specialists_diary(): void
    {
        $mine = $this->specialist();
        $theirs = $this->specialist();

        $service = SpecialistService::factory()->create(['listing_id' => $theirs->getKey()]);
        $day = $this->nextTuesday();

        $uuid = $this->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))
            ->assertCreated()->json('data.uuid');

        $this->actingAs($mine->user)
            ->postJson("/api/v1/seller/specialist/bookings/{$uuid}/transition", [
                'status' => BookingStatus::Confirmed->value,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_can_cancel_their_own_booking_and_nothing_else(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);
        $customer = User::factory()->create();
        $day = $this->nextTuesday();

        $uuid = $this->actingAs($customer)
            ->postJson('/api/v1/bookings', $this->bookPayload($service, $day, '10:00'))
            ->assertCreated()->json('data.uuid');

        // There is no customer-facing confirm endpoint at all — a customer who
        // could confirm would be marking a professional's diary for them.
        $this->actingAs($customer)
            ->postJson("/api/v1/seller/specialist/bookings/{$uuid}/transition", [
                'status' => BookingStatus::Confirmed->value,
            ])
            ->assertNotFound();

        $this->actingAs($customer)
            ->postJson("/api/v1/account/bookings/{$uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Cancelled->value);

        $this->assertSame(
            'customer',
            DB::table('specialist_bookings')->where('uuid', $uuid)->value('cancelled_by'),
        );
    }

    #[Test]
    public function a_customer_cannot_see_another_customers_booking(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $uuid = $this->actingAs($theirs)
            ->postJson('/api/v1/bookings', $this->bookPayload($service, $this->nextTuesday(), '10:00'))
            ->assertCreated()->json('data.uuid');

        $this->actingAs($mine)->getJson("/api/v1/account/bookings/{$uuid}")->assertNotFound();
        $this->actingAs($mine)->getJson('/api/v1/account/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_customers_own_booking_never_carries_anybody_elses_contact_details(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->create(['listing_id' => $listing->getKey()]);
        $customer = User::factory()->create();

        $uuid = $this->actingAs($customer)
            ->postJson('/api/v1/bookings', $this->bookPayload($service, $this->nextTuesday(), '10:00'))
            ->assertCreated()->json('data.uuid');

        $payload = $this->actingAs($customer)
            ->getJson("/api/v1/account/bookings/{$uuid}")->assertOk()->json();

        // The customer block is opened only by forSpecialist(); a scoping bug
        // on the customer surface therefore cannot leak phone numbers.
        $this->assertArrayNotHasKey('customer', $payload['data']);
    }

    // ---------------------------------------------------------- public reads

    #[Test]
    public function the_public_slot_endpoint_reports_real_availability(): void
    {
        $listing = $this->specialist();
        $service = SpecialistService::factory()->minutes(60)->create(['listing_id' => $listing->getKey()]);

        $response = $this->getJson(
            "/api/v1/specialists/{$listing->slug}/services/{$service->uuid}/slots?days=7"
        )->assertOk();

        $response->assertJsonPath('meta.timezone', self::TZ);
        $response->assertJsonPath('meta.has_availability', true);
        $this->assertCount(7, $response->json('data'));
    }

    #[Test]
    public function the_service_menu_filters_by_mode_and_respects_both(): void
    {
        $listing = $this->specialist();

        SpecialistService::factory()->mode(ServiceMode::Online)->create([
            'listing_id' => $listing->getKey(), 'name' => 'Video call',
        ]);
        SpecialistService::factory()->mode(ServiceMode::Both)->create([
            'listing_id' => $listing->getKey(), 'name' => 'Either way',
        ]);
        SpecialistService::factory()->mode(ServiceMode::InPerson)->create([
            'listing_id' => $listing->getKey(), 'name' => 'In chambers',
        ]);

        $names = array_column(
            $this->getJson("/api/v1/specialists/{$listing->slug}/services?mode=online")
                ->assertOk()->json('data'),
            'name',
        );

        // `Both` satisfies either — a plain equality filter would hide it from
        // somebody who asked for online.
        $this->assertContains('Video call', $names);
        $this->assertContains('Either way', $names);
        $this->assertNotContains('In chambers', $names);
    }

    #[Test]
    public function the_public_service_shape_hides_the_specialists_buffer(): void
    {
        $listing = $this->specialist();
        SpecialistService::factory()->minutes(60, 15)->create(['listing_id' => $listing->getKey()]);

        $service = $this->getJson("/api/v1/specialists/{$listing->slug}/services")
            ->assertOk()->json('data.0');

        // Quoting the buffer invites "why am I charged for 75 minutes?".
        $this->assertSame(60, $service['duration_minutes']);
        $this->assertArrayNotHasKey('buffer_minutes', $service);
    }

    #[Test]
    public function a_property_listing_is_not_reachable_through_the_specialist_endpoints(): void
    {
        $vendor = User::factory()->seller()->create();

        /*
         * The category is pinned, not left to the factory.
         *
         * ListingFactory picks a random leaf, and now that Specialists is a
         * seeded vertical that random pick can BE a specialist category — which
         * made this test pass alone and fail in the full suite depending on the
         * draw. Naming the category is what makes it deterministic.
         */
        $apartments = Category::query()->where('slug', 'property-apartments')->firstOrFail();

        $property = Listing::factory()->published()->ownedBy($vendor)->create([
            'category_id' => $apartments->getKey(),
        ]);

        // Scoped to the specialists vertical, so an unrelated slug 404s rather
        // than returning an empty menu that looks like a real specialist.
        $this->getJson("/api/v1/specialists/{$property->slug}/services")->assertNotFound();
    }
}
