<?php

declare(strict_types=1);

namespace App\Services\Seller;

use App\Domain\Identity\Enums\BusinessType;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * Vendor profile completeness and onboarding progress.
 *
 * Completion is computed from the profile rather than stored, because a stored
 * percentage is a number that goes stale the moment anything else writes to the
 * row — and it is read far less often than it would be updated.
 *
 * WHAT COUNTS AS COMPLETE DEPENDS ON THE BUSINESS TYPE. That is the whole point
 * of the multi-vertical design: a landlord with no opening hours is finished,
 * and a pharmacy without them is not. Scoring both against one checklist would
 * either nag the landlord forever or let the pharmacy publish a profile missing
 * the single fact customers most want.
 */
class VendorProfileService
{
    /**
     * Onboarding steps, in the order the portal walks through them.
     *
     * `required` marks a step the vendor cannot skip. Branding is genuinely
     * optional — a business with no logo is still a business — so it is
     * counted for completeness but never blocks.
     */
    public const STEPS = ['business', 'location', 'contact', 'branding', 'hours', 'verification'];

    /**
     * @return array<string, mixed>
     */
    public function progress(SellerProfile $profile, User $user): array
    {
        $type = $profile->business_type;

        $steps = [
            'business' => [
                'complete' => filled($profile->business_name) && $type !== null,
                'required' => true,
                'missing' => array_values(array_filter([
                    filled($profile->business_name) ? null : 'business_name',
                    $type !== null ? null : 'business_type',
                    filled($profile->bio) ? null : 'description',
                ])),
            ],

            'location' => [
                // A region is the minimum that makes a vendor findable. A street
                // address is only expected where customers actually come to you.
                'complete' => $profile->region_id !== null
                    && (! ($type?->hasWalkInAddress() ?? true) || filled($profile->street)),
                'required' => true,
                'missing' => array_values(array_filter([
                    $profile->region_id !== null ? null : 'region',
                    ($type?->hasWalkInAddress() ?? true) && blank($profile->street) ? 'street' : null,
                    $profile->latitude !== null ? null : 'map_pin',
                ])),
            ],

            'contact' => [
                // The account phone is the fallback, so this is complete as soon
                // as there is any way to reach the business.
                'complete' => filled($profile->public_phone) || filled($user->phone),
                'required' => true,
                'missing' => array_values(array_filter([
                    filled($profile->public_phone) || filled($user->phone) ? null : 'phone',
                    filled($profile->public_email) || filled($user->email) ? null : 'email',
                ])),
            ],

            'branding' => [
                'complete' => $profile->logo_media_id !== null,
                'required' => false,
                'missing' => array_values(array_filter([
                    $profile->logo_media_id !== null ? null : 'logo',
                    $profile->cover_media_id !== null ? null : 'cover_image',
                ])),
            ],

            'hours' => [
                // Not applicable is COMPLETE, not skipped. A landlord who never
                // sees this step should not be told their profile is 5/6.
                'complete' => ! ($type?->hasOpeningHours() ?? false) || filled($profile->opening_hours),
                'required' => $type?->hasOpeningHours() ?? false,
                'applicable' => $type?->hasOpeningHours() ?? false,
                'missing' => ($type?->hasOpeningHours() ?? false) && blank($profile->opening_hours)
                    ? ['opening_hours']
                    : [],
            ],

            'verification' => [
                // Phone verification is the gate on publishing, so it is what
                // "verified" means at this step — the document review is a
                // separate, higher trust level.
                'complete' => $user->phone_verified_at !== null,
                'required' => true,
                'missing' => $user->phone_verified_at !== null ? [] : ['phone_verification'],
            ],
        ];

        $applicable = array_filter(
            $steps,
            fn (array $step): bool => $step['applicable'] ?? true,
        );

        $completed = count(array_filter($applicable, fn (array $step): bool => $step['complete']));

        return [
            'steps' => $steps,
            'completed_steps' => $completed,
            'total_steps' => count($applicable),
            'percentage' => count($applicable) === 0
                ? 0
                : (int) round(($completed / count($applicable)) * 100),
            // Where the wizard should resume. Null once nothing is outstanding.
            'next_step' => $this->nextStep($steps),
            'is_complete' => $this->requiredComplete($steps),
            'onboarding_completed_at' => $profile->onboarding_completed_at?->toAtomString(),
        ];
    }

    /**
     * Marks onboarding finished once every REQUIRED step is done.
     *
     * Called after each profile write, so a vendor who fills the last gap from
     * the settings screen months later is not pushed back into the wizard.
     */
    public function syncOnboardingState(SellerProfile $profile, User $user): void
    {
        $progress = $this->progress($profile, $user);

        if ($progress['is_complete'] && $profile->onboarding_completed_at === null) {
            $profile->forceFill(['onboarding_completed_at' => now()])->save();

            return;
        }

        /*
         * Deliberately NOT cleared when a step later becomes incomplete.
         *
         * A vendor who changes their business type from Shop to Landlord would
         * otherwise be thrown back into onboarding mid-session over opening
         * hours that no longer apply. Once they have been through it, the
         * settings screen is the right place to fix gaps — and the completion
         * percentage still shows them.
         */
    }

    /**
     * @param  array<string, array<string, mixed>>  $steps
     */
    private function nextStep(array $steps): ?string
    {
        foreach (self::STEPS as $key) {
            $step = $steps[$key] ?? null;

            if ($step !== null && ! $step['complete'] && ($step['applicable'] ?? true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $steps
     */
    private function requiredComplete(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step['required'] && ! $step['complete']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates an opening-hours payload beyond what request rules can express.
     *
     * Shape is `{"mon": [{"open": "09:00", "close": "17:00"}], ...}`. The rules
     * that matter and that a `date_format` rule cannot state: close must be
     * after open, and ranges within a day must not overlap — a schedule saying
     * "09:00–17:00 and 13:00–15:00" renders as nonsense on a public profile.
     *
     * @param  array<string, mixed>  $hours
     * @return array<int, string> human-readable problems, empty when valid
     */
    public function validateOpeningHours(array $hours): array
    {
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $errors = [];

        foreach ($hours as $day => $ranges) {
            if (! in_array($day, $days, true)) {
                $errors[] = "'{$day}' is not a day of the week.";

                continue;
            }

            if (! is_array($ranges)) {
                $errors[] = ucfirst($day).' must be a list of time ranges.';

                continue;
            }

            $previousClose = null;

            foreach ($ranges as $index => $range) {
                $open = is_array($range) ? ($range['open'] ?? null) : null;
                $close = is_array($range) ? ($range['close'] ?? null) : null;

                if (! $this->isTime($open) || ! $this->isTime($close)) {
                    $errors[] = ucfirst($day).' has a time that is not HH:MM.';

                    continue;
                }

                if ($close <= $open) {
                    $errors[] = ucfirst($day).": {$close} is not after {$open}.";

                    continue;
                }

                // Ranges arrive in order, so comparing against the previous
                // close is enough to catch an overlap.
                if ($previousClose !== null && $open < $previousClose) {
                    $errors[] = ucfirst($day).' has overlapping opening times.';
                }

                $previousClose = $close;
                unset($index);
            }
        }

        return $errors;
    }

    private function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Every business type, described for a client.
     *
     * The portal renders its type picker and its conditional fields from this,
     * so the per-vertical rules exist in exactly one place.
     *
     * @return array<int, array<string, mixed>>
     */
    public function businessTypes(): array
    {
        return array_map(
            fn (BusinessType $type): array => $type->toArray(),
            BusinessType::cases(),
        );
    }
}
