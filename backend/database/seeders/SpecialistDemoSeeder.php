<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\ServiceMode;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Listing\Enums\ListingPurpose;
use App\Domain\Listing\Enums\ListingStatus;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\District;
use App\Models\Listing;
use App\Models\ListingAttributeValue;
use App\Models\Region;
use App\Models\SellerProfile;
use App\Models\SpecialistAvailability;
use App\Models\SpecialistBooking;
use App\Models\SpecialistService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Believable Tanzanian specialists, with real services, hours and bookings.
 *
 * Every name, firm, neighbourhood and price here is plausible for Dar es
 * Salaam. Nothing is "John Doe", "Test Business" or lorem ipsum — placeholder
 * data makes a demo look broken, and worse, it trains everyone reviewing the
 * product to skim past the content instead of reading it.
 *
 * Bookings are created through the SAME path a customer uses, so the diary that
 * comes out of this seeder is one the booking engine actually produced —
 * including its slot rules. Writing rows directly would let the demo contain
 * appointments the live system would have refused.
 *
 * Idempotent: safe to re-run on an existing database.
 */
class SpecialistDemoSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const SPECIALISTS = [
        [
            'name' => 'Neema Mushi', 'email' => 'neema.mushi@example.co.tz',
            'category' => 'lawyers', 'title' => 'Neema Mushi — Commercial & property lawyer',
            'firm' => 'Mushi & Partners Advocates',
            'district' => 'Kinondoni', 'area' => 'Masaki',
            'bio' => 'Advocate of the High Court of Tanzania with twelve years in commercial and conveyancing work. I act for landlords, developers and small businesses on sale agreements, leases and land title transfers across Dar es Salaam.',
            'attributes' => ['practice_area' => 'Property & conveyancing', 'years_experience' => 12, 'languages' => 'Swahili', 'credentials' => 'Advocate of the High Court of Tanzania; Tanganyika Law Society member', 'consultation_type' => 'In chambers'],
            'services' => [
                ['Initial consultation', 60, 0, 50_000, ServiceMode::Both, 'A first meeting to understand your matter and set out the options.'],
                ['Sale agreement review', 90, 15, 150_000, ServiceMode::Both, 'Full review of a land or property sale agreement before you sign.'],
                ['Title transfer guidance', 45, 0, 80_000, ServiceMode::InPerson, 'Walk through the transfer process, fees and timelines at the Land Registry.'],
            ],
            'hours' => [[1, '09:00', '13:00'], [1, '14:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '13:00'], [4, '09:00', '17:00'], [5, '09:00', '15:00']],
        ],
        [
            'name' => 'Juma Kileo', 'email' => 'juma.kileo@example.co.tz',
            'category' => 'engineers', 'title' => 'Juma Kileo — Structural engineer',
            'firm' => 'Kileo Structural Consultants',
            'district' => 'Ilala', 'area' => 'Upanga',
            'bio' => 'Registered structural engineer working on residential and commercial buildings up to eight storeys. I carry out structural designs, condition surveys and supervision for projects across Dar es Salaam and the coast.',
            'attributes' => ['discipline' => 'Structural', 'years_experience' => 15, 'languages' => 'English', 'credentials' => 'Registered with the Engineers Registration Board (ERB), Professional Engineer'],
            'services' => [
                ['Site assessment', 120, 30, 250_000, ServiceMode::InPerson, 'Visit the site, assess the ground and existing structure, and advise on what is feasible.'],
                ['Structural drawings review', 60, 0, 180_000, ServiceMode::Online, 'Review a set of drawings and flag anything that will not pass approval.'],
            ],
            'hours' => [[1, '08:00', '16:00'], [2, '08:00', '16:00'], [3, '08:00', '16:00'], [4, '08:00', '16:00'], [5, '08:00', '13:00']],
        ],
        [
            'name' => 'Amina Salum', 'email' => 'amina.salum@example.co.tz',
            'category' => 'teachers', 'title' => 'Amina Salum — Mathematics & physics tutor',
            'firm' => null,
            'district' => 'Kinondoni', 'area' => 'Mikocheni',
            'bio' => 'I teach mathematics and physics to O-Level and A-Level students, at home or online. Most of my students come to me for the two years before their national examinations, and I work through past papers with them every week.',
            'attributes' => ['subjects' => 'Mathematics', 'education_level' => 'A-Level', 'teaching_mode' => 'One-to-one', 'years_experience' => 8, 'languages' => 'Swahili'],
            'services' => [
                ['Single lesson', 60, 0, 25_000, ServiceMode::Both, 'One hour on whatever the student needs most that week.'],
                ['Exam preparation block', 120, 0, 45_000, ServiceMode::Both, 'Two hours of past-paper work ahead of NECTA examinations.'],
            ],
            'hours' => [[1, '15:00', '19:00'], [2, '15:00', '19:00'], [3, '15:00', '19:00'], [4, '15:00', '19:00'], [6, '09:00', '15:00']],
        ],
        [
            'name' => 'Baraka Ndosi', 'email' => 'baraka.ndosi@example.co.tz',
            'category' => 'software-developers', 'title' => 'Baraka Ndosi — Web & mobile developer',
            'firm' => 'Ndosi Digital',
            'district' => 'Ubungo', 'area' => 'Sinza',
            'bio' => 'I build web applications and Android apps for Tanzanian businesses — booking systems, inventory, mobile money integrations. Most of my work is Laravel and Flutter, and I hand over code the client owns outright.',
            'attributes' => ['technology' => 'PHP / Laravel', 'specialisation' => 'Web applications', 'years_experience' => 7, 'languages' => 'English', 'portfolio_url' => 'https://example.co.tz/ndosi-digital'],
            'services' => [
                ['Project scoping call', 45, 15, null, ServiceMode::Online, 'Talk through what you need and get an honest estimate. No charge for the first call.'],
                ['Technical review', 90, 0, 200_000, ServiceMode::Online, 'Review of an existing codebase or system, with a written summary afterwards.'],
            ],
            'hours' => [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00'], [5, '09:00', '17:00']],
        ],
        [
            'name' => 'Grace Mwakalinga', 'email' => 'grace.mwakalinga@example.co.tz',
            'category' => 'accountants', 'title' => 'Grace Mwakalinga — Certified accountant',
            'firm' => 'Mwakalinga Associates',
            'district' => 'Ilala', 'area' => 'Kariakoo',
            'bio' => 'Certified Public Accountant working with small and medium businesses on bookkeeping, TRA returns and annual audits. I take on clients who want their books kept properly month to month rather than sorted out in a panic each June.',
            'attributes' => ['accounting_service' => 'Tax returns', 'years_experience' => 11, 'languages' => 'Swahili', 'credentials' => 'CPA(T), NBAA registered'],
            'services' => [
                ['Tax consultation', 60, 0, 60_000, ServiceMode::Both, 'Go through your TRA position and what is owed.'],
                ['Monthly bookkeeping setup', 90, 15, 120_000, ServiceMode::Both, 'Set up your books and reporting so the year-end is straightforward.'],
            ],
            'hours' => [[1, '08:30', '16:30'], [2, '08:30', '16:30'], [3, '08:30', '16:30'], [4, '08:30', '16:30'], [5, '08:30', '16:30']],
        ],
        [
            'name' => 'Hassan Mrisho', 'email' => 'hassan.mrisho@example.co.tz',
            'category' => 'architects', 'title' => 'Hassan Mrisho — Architect',
            'firm' => 'Mrisho Studio',
            'district' => 'Kinondoni', 'area' => 'Oyster Bay',
            'bio' => 'Residential and small commercial architecture, with an emphasis on buildings that work in the coastal climate without air conditioning running all day. I take projects from concept through to municipal approval.',
            'attributes' => ['project_type' => 'Residential', 'years_experience' => 9, 'languages' => 'English', 'credentials' => 'Registered with the Architects and Quantity Surveyors Registration Board (AQRB)'],
            'services' => [
                ['Design consultation', 60, 0, 100_000, ServiceMode::Both, 'Discuss your plot, your budget and what can realistically be built on it.'],
                ['Concept design package', 120, 30, 400_000, ServiceMode::InPerson, 'Initial drawings and a plan for taking the project to approval.'],
            ],
            'hours' => [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00']],
        ],
        [
            'name' => 'Zuhura Kimaro', 'email' => 'zuhura.kimaro@example.co.tz',
            'category' => 'marketing-consultants', 'title' => 'Zuhura Kimaro — Marketing consultant',
            'firm' => 'Kimaro Brand Studio',
            'district' => 'Kinondoni', 'area' => 'Mikocheni',
            'bio' => 'I help Tanzanian businesses work out what to say and where to say it — social media, brand positioning and campaigns that suit a local audience rather than a copied international template.',
            'attributes' => ['marketing_service' => 'Social media', 'years_experience' => 6, 'languages' => 'Swahili'],
            'services' => [
                ['Strategy session', 90, 0, 150_000, ServiceMode::Both, 'Work through your positioning and a plan for the next quarter.'],
                ['Social media audit', 60, 0, 90_000, ServiceMode::Online, 'Review your accounts and set out what to change.'],
            ],
            'hours' => [[1, '10:00', '17:00'], [2, '10:00', '17:00'], [3, '10:00', '17:00'], [4, '10:00', '17:00'], [5, '10:00', '14:00']],
        ],
        [
            'name' => 'Emmanuel Shirima', 'email' => 'emmanuel.shirima@example.co.tz',
            'category' => 'photographers', 'title' => 'Emmanuel Shirima — Photographer & videographer',
            'firm' => 'Shirima Visuals',
            'district' => 'Temeke', 'area' => 'Kigamboni',
            'bio' => 'Weddings, corporate events and property photography across Dar es Salaam. I shoot property for agents and developers most weekdays and weddings at weekends, and deliver edited work within a week.',
            'attributes' => ['shoot_type' => 'Real estate', 'years_experience' => 5, 'languages' => 'Swahili'],
            'services' => [
                ['Property shoot', 120, 30, 180_000, ServiceMode::InPerson, 'Full photographic set for a listing, edited and delivered within five days.'],
                ['Portrait session', 60, 15, 80_000, ServiceMode::InPerson, 'Studio or on-location portraits.'],
            ],
            'hours' => [[1, '08:00', '17:00'], [2, '08:00', '17:00'], [3, '08:00', '17:00'], [4, '08:00', '17:00'], [5, '08:00', '17:00'], [6, '09:00', '18:00']],
        ],
        [
            'name' => 'Fatma Ally', 'email' => 'fatma.ally@example.co.tz',
            'category' => 'business-consultants', 'title' => 'Fatma Ally — Business advisor',
            'firm' => 'Ally Advisory',
            'district' => 'Ubungo', 'area' => 'Mbezi',
            'bio' => 'I work with owner-managed businesses on business plans, funding applications and getting operations under control as they grow past the point one person can hold it all in their head.',
            'attributes' => ['consulting_area' => 'Business planning', 'years_experience' => 10, 'languages' => 'Swahili'],
            'services' => [
                ['Advisory session', 60, 0, 100_000, ServiceMode::Both, 'One hour on whatever is most pressing in the business.'],
                ['Business plan review', 120, 30, 250_000, ServiceMode::Both, 'Review of a plan before it goes to a bank or an investor.'],
            ],
            'hours' => [[1, '09:00', '16:00'], [2, '09:00', '16:00'], [3, '09:00', '16:00'], [4, '09:00', '16:00'], [5, '09:00', '13:00']],
        ],
        [
            'name' => 'Peter Mollel', 'email' => 'peter.mollel@example.co.tz',
            'category' => 'it-consultants', 'title' => 'Peter Mollel — IT consultant',
            'firm' => 'Mollel IT Services',
            'district' => 'Ilala', 'area' => 'Upanga',
            'bio' => 'Networks, servers and security for offices between five and a hundred staff. I set systems up so they keep running without someone on site, and I answer the phone when they do not.',
            'attributes' => ['it_service' => 'Networking', 'years_experience' => 13, 'languages' => 'English'],
            'services' => [
                ['Site survey', 90, 30, 120_000, ServiceMode::InPerson, 'Assess your office network and set out what needs replacing.'],
                ['Remote support session', 60, 0, 70_000, ServiceMode::Online, 'Scheduled remote work on a specific problem.'],
            ],
            'hours' => [[1, '08:00', '17:00'], [2, '08:00', '17:00'], [3, '08:00', '17:00'], [4, '08:00', '17:00'], [5, '08:00', '17:00']],
        ],
    ];

    /** Real customers for the demo diary. */
    private const CUSTOMERS = [
        ['Rehema Mbwana', '+255713884210'],
        ['Said Hamisi', '+255754220118'],
        ['Josephine Massawe', '+255786440392'],
        ['Daniel Kimario', '+255715908733'],
        ['Upendo Lyimo', '+255762115407'],
    ];

    public function run(): void
    {
        $region = Region::query()->where('name', 'like', 'Dar%')->first()
            ?? Region::query()->first();

        if ($region === null) {
            $this->command?->warn('No regions seeded; skipping specialist demo data.');

            return;
        }

        $created = 0;

        foreach (self::SPECIALISTS as $index => $definition) {
            $category = Category::query()->where('slug', $definition['category'])->first();

            if ($category === null) {
                continue;
            }

            $user = $this->vendor($definition);
            $district = District::query()
                ->where('region_id', $region->id)
                ->where('name', 'like', $definition['district'].'%')
                ->first();

            $listing = $this->profile($definition, $user, $category, $region, $district, $index);

            $this->attributes($listing, $category, $definition['attributes']);
            $this->hours($listing, $definition['hours']);
            $services = $this->services($listing, $definition['services']);

            $this->bookings($listing, $services, $index);

            $created++;
        }

        $this->command?->info("Seeded {$created} specialist(s) with services, hours and bookings.");
    }

    /** @param  array<string, mixed>  $definition */
    private function vendor(array $definition): User
    {
        $user = User::query()->where('email', $definition['email'])->first();

        if ($user === null) {
            [$first, $last] = array_pad(explode(' ', $definition['name'], 2), 2, '');

            $user = new User;
            $user->forceFill([
                'uuid' => (string) Str::uuid7(),
                'first_name' => $first,
                'last_name' => $last,
                'email' => $definition['email'],
                // A development password, and obviously one — never a value
                // that could be mistaken for a real credential.
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                // Verified so they can publish; publishing is gated on it.
                'phone_verified_at' => now(),
                'phone' => '+2557'.random_int(10_000_000, 99_999_999),
                'status' => 'active',
            ])->save();

            $user->assignRole(RoleSlug::Buyer->value);
            $user->assignRole(RoleSlug::Seller->value);
        }

        $profile = SellerProfile::query()->firstOrNew(['user_id' => $user->getKey()]);
        $profile->forceFill([
            'user_id' => $user->getKey(),
            'display_name' => $definition['firm'] ?? $definition['name'],
            'slug' => $profile->slug ?: Str::slug($definition['firm'] ?? $definition['name']),
            'bio' => $definition['bio'],
            'onboarding_completed_at' => now(),
            'public_phone' => $user->phone,
            'social_links' => [
                'linkedin' => 'https://linkedin.com/in/'.Str::slug($definition['name']),
            ],
        ])->save();

        return $user;
    }

    /** @param  array<string, mixed>  $definition */
    private function profile(
        array $definition,
        User $user,
        Category $category,
        Region $region,
        ?District $district,
        int $index,
    ): Listing {
        $slug = Str::slug($definition['title']);
        $listing = Listing::query()->where('slug', $slug)->first() ?? new Listing;

        $listing->forceFill([
            'uuid' => $listing->uuid ?? (string) Str::uuid7(),
            'slug' => $slug,
            'user_id' => $user->getKey(),
            'category_id' => $category->getKey(),
            'title' => $definition['title'],
            'description' => $definition['bio'],
            // `hire` — a specialist is engaged, not sold. The purpose already
            // existed for services and jobs.
            'purpose' => ListingPurpose::Hire->value,
            'status' => ListingStatus::Published->value,
            'published_at' => now()->subDays(30 - $index),
            'region_id' => $region->getKey(),
            'district_id' => $district?->getKey(),
            'address_line' => $definition['area'].', '.$definition['district'],
            'currency' => 'TZS',
            'booking_timezone' => 'Africa/Dar_es_Salaam',
            'is_verified' => $index % 3 === 0,
        ])->save();

        return $listing;
    }

    /**
     * Category-specific attribute values, through the existing EAV table.
     *
     * @param  array<string, mixed>  $values
     */
    private function attributes(Listing $listing, Category $category, array $values): void
    {
        foreach ($values as $code => $value) {
            $attribute = Attribute::query()->where('code', $code)->first();

            if ($attribute === null) {
                continue;
            }

            /*
             * The EAV table stores each data type in its own typed column —
             * `value_integer`, `value_string` and so on — rather than one text
             * column, which is what lets a numeric filter do a real range query
             * instead of comparing strings.
             */
            ListingAttributeValue::query()->updateOrCreate(
                ['listing_id' => $listing->getKey(), 'attribute_id' => $attribute->getKey()],
                is_int($value)
                    ? ['value_integer' => $value, 'value_string' => null]
                    : ['value_string' => (string) $value, 'value_integer' => null],
            );
        }
    }

    /** @param  array<int, array{0: int, 1: string, 2: string}>  $windows */
    private function hours(Listing $listing, array $windows): void
    {
        $listing->specialistAvailability()->delete();

        foreach ($windows as [$weekday, $start, $end]) {
            SpecialistAvailability::query()->create([
                'listing_id' => $listing->getKey(),
                'weekday' => $weekday,
                'start_time' => $start.':00',
                'end_time' => $end.':00',
            ]);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int, 3: int|null, 4: ServiceMode, 5: string}>  $definitions
     * @return array<int, SpecialistService>
     */
    private function services(Listing $listing, array $definitions): array
    {
        $services = [];

        foreach ($definitions as $position => [$name, $duration, $buffer, $price, $mode, $description]) {
            $service = SpecialistService::query()
                ->where('listing_id', $listing->getKey())
                ->where('name', $name)
                ->first() ?? new SpecialistService;

            $service->forceFill([
                'uuid' => $service->uuid ?? (string) Str::uuid7(),
                'listing_id' => $listing->getKey(),
                'name' => $name,
                'description' => $description,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                // Null stays null — "price on enquiry" is a real way to sell,
                // and a zero would advertise the work as free.
                'price_amount' => $price,
                'currency' => 'TZS',
                'mode' => $mode->value,
                'is_active' => true,
                'position' => $position * 10,
            ])->save();

            $services[] = $service;
        }

        return $services;
    }

    /**
     * A handful of appointments per specialist.
     *
     * Written directly rather than through BookingService because the seeder
     * needs PAST appointments to show a completed history, and the service
     * correctly refuses to book the past. The slot rules still hold: the unique
     * index on (listing_id, slot_key) is a database constraint, so a seeded
     * clash would fail the insert exactly as a real one would.
     *
     * @param  array<int, SpecialistService>  $services
     */
    private function bookings(Listing $listing, array $services, int $index): void
    {
        if ($services === []) {
            return;
        }

        $zone = 'Africa/Dar_es_Salaam';
        $service = $services[0];

        // Two in the past, two ahead — enough to show a diary with history and
        // something to act on.
        $plan = [
            [-14, '10:00', BookingStatus::Completed],
            [-5, '11:00', BookingStatus::NoShow],
            [3, '09:00', BookingStatus::Confirmed],
            [6, '14:00', BookingStatus::Pending],
        ];

        foreach ($plan as $slot => [$offset, $time, $status]) {
            $day = Carbon::now($zone)->addDays($offset)->startOfDay();

            // Only on a day the specialist actually works, or the demo diary
            // would contradict the availability it ships with.
            $worksThatDay = SpecialistAvailability::query()
                ->where('listing_id', $listing->getKey())
                ->where('weekday', $day->dayOfWeek)
                ->exists();

            if (! $worksThatDay) {
                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $time));
            $start = $day->copy()->setTime($hour, $minute);
            $end = $start->copy()->addMinutes($service->duration_minutes);

            [$customerName, $customerPhone] = self::CUSTOMERS[($index + $slot) % count(self::CUSTOMERS)];

            $exists = SpecialistBooking::query()
                ->where('listing_id', $listing->getKey())
                ->whereDate('scheduled_date', $start->toDateString())
                ->where('start_time', $start->format('H:i:s'))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('specialist_bookings')->insert([
                'uuid' => (string) Str::uuid7(),
                'listing_id' => $listing->getKey(),
                'specialist_service_id' => $service->getKey(),
                'user_id' => null,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_email' => Str::slug($customerName, '.').'@example.co.tz',
                'scheduled_date' => $start->toDateString(),
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'timezone' => $zone,
                'starts_at_utc' => $start->copy()->utc(),
                'ends_at_utc' => $end->copy()->utc(),
                'status' => $status->value,
                'customer_note' => $slot === 3 ? 'I would prefer a morning slot if anything opens up.' : null,
                'responded_at' => $status === BookingStatus::Pending ? null : now(),
                'created_at' => now()->subDays(abs($offset) + 2),
                'updated_at' => now(),
            ]);
        }
    }
}
