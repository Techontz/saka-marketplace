<?php

declare(strict_types=1);

namespace App\Services\Advertising;

use App\Domain\Advertising\Enums\AdCampaignStatus;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\PromotionRequestStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Advertiser;
use App\Models\Listing;
use App\Models\PromotionRequest;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules a vendor promotion has to satisfy, in one place.
 *
 * Two moments matter and they are NOT the same:
 *
 *   SUBMISSION — the vendor is told immediately if something is wrong, so they
 *                do not wait two days to learn their listing was a draft.
 *
 *   APPROVAL   — everything is checked AGAIN, because time passed. In between,
 *                the listing can be archived, sold, deleted or transferred; the
 *                artwork can be removed; and the requested window can close.
 *                Approving on submission-time facts is how a campaign gets
 *                minted for a listing that no longer exists.
 *
 * The duplication is deliberate and is the point of this class.
 */
class PromotionRequestService
{
    /**
     * What may be promoted, keyed by the alias the API speaks.
     *
     * Aliases, not class names. There is no morph map in this application, so
     * `promotable_type` holds a fully-qualified class name — fine for storage,
     * unacceptable on the wire: `App\Models\SellerProfile` in a JSON payload
     * tells an attacker the framework, the namespace layout and the model name,
     * and bakes an internal refactor into a public contract.
     *
     * @var array<string, class-string<Model>>
     */
    private const PROMOTABLE = [
        'listing' => Listing::class,
        'business' => SellerProfile::class,
        // 'specialist' => SpecialistProfile::class — added with Phase 8. The
        // only change needed here is this line plus a `resourceUrl` arm.
    ];

    /** @return array<int, string> */
    public function promotableTypes(): array
    {
        return array_keys(self::PROMOTABLE);
    }

    public function classFor(string $alias): string
    {
        return self::PROMOTABLE[$alias]
            ?? throw ApiException::make(ErrorCode::ValidationFailed, 'That cannot be promoted.');
    }

    /** The wire alias for a stored class name. */
    public function aliasFor(string $className): ?string
    {
        $flipped = array_flip(self::PROMOTABLE);

        return $flipped[$className] ?? null;
    }

    /**
     * Resolve what the vendor asked to promote, proving they own it.
     *
     * Per-type rather than one generic uuid lookup, because the two promotables
     * are not identified the same way and pretending otherwise would be a bug:
     *
     *   LISTING  — identified by uuid, never by id. An auto-increment id in a
     *              request body invites enumeration, and this lookup exists
     *              precisely so a vendor cannot walk another vendor's inventory
     *              by guessing numbers. (`seller_profiles` has no uuid column
     *              at all, so a single generic `where('uuid', …)` would have
     *              thrown an SQL error on the business arm.)
     *
     *   BUSINESS — a vendor has exactly ONE, so there is nothing to identify.
     *              Any identifier sent is ignored rather than trusted, which
     *              also means a vendor cannot name someone else's profile.
     *
     * 404, not 403, when a listing belongs to somebody else: a 403 confirms the
     * resource exists, which is the fact being withheld.
     */
    public function resolveOwned(User $vendor, string $alias, ?string $identifier): Model
    {
        return match ($alias) {
            'listing' => $this->ownedListing($vendor, $identifier),
            'business' => SellerProfile::query()
                ->where('user_id', $vendor->getKey())
                ->first() ?? throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'You do not have a business profile yet.',
                ),
            default => throw ApiException::make(ErrorCode::ValidationFailed, 'That cannot be promoted.'),
        };
    }

    private function ownedListing(User $vendor, ?string $uuid): Listing
    {
        if ($uuid === null || $uuid === '') {
            throw ApiException::make(ErrorCode::ValidationFailed, 'Choose a listing to promote.');
        }

        $listing = Listing::query()->where('uuid', $uuid)->first();

        if ($listing === null || ! $this->isOwnedBy($listing, $vendor)) {
            throw ApiException::notFound('That item was not found.');
        }

        return $listing;
    }

    /**
     * Whether this vendor owns the thing.
     *
     * Both current promotable types hang off `user_id`, but the check is
     * explicit per type rather than a blanket `$subject->user_id` read — a
     * future promotable whose ownership works differently would otherwise
     * silently pass by having no `user_id` at all and comparing null to null.
     */
    private function isOwnedBy(Model $subject, User $vendor): bool
    {
        return match (true) {
            $subject instanceof Listing => $subject->user_id === $vendor->getKey(),
            $subject instanceof SellerProfile => $subject->user_id === $vendor->getKey(),
            default => false,
        };
    }

    /**
     * Whether the thing is in a state worth promoting.
     *
     * Paying to advertise a draft listing sends traffic to a 404. Checked at
     * submission AND approval — a listing can be sold in between, and that is
     * precisely the case where continuing to serve the advert wastes the
     * vendor's money and the visitor's time.
     */
    public function assertPromotable(Model $subject): void
    {
        if ($subject instanceof Listing) {
            if ($subject->status !== ListingStatus::Published || $subject->published_at === null) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'Only a published listing can be promoted.',
                );
            }

            return;
        }

        if ($subject instanceof SellerProfile) {
            if ($subject->onboarding_completed_at === null) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'Finish your business profile before promoting it.',
                );
            }

            return;
        }

        throw ApiException::make(ErrorCode::ValidationFailed, 'That cannot be promoted.');
    }

    /**
     * Where a promotion's click should land.
     *
     * ALWAYS a SAKA URL, derived here — never a value the vendor supplied.
     *
     * This is the single most important restriction in the vendor flow. An
     * administrator creating a campaign may point it anywhere, because an
     * administrator is inside the organisation and the destination was agreed
     * commercially. A vendor is not: an arbitrary `click_url` on a paid
     * placement is a phishing page with a media buy behind it, sitting inside a
     * marketplace people trust, and no amount of URL validation prevents that —
     * `https://saka-login.example.com` passes every scheme check there is.
     *
     * Deriving it also means a slug renamed between request and approval
     * resolves correctly, which a stored URL would not.
     */
    public function resourceUrl(Model $subject): string
    {
        $base = rtrim((string) config('saka.frontend_url'), '/');

        return match (true) {
            $subject instanceof Listing => "{$base}/listings/{$subject->slug}",
            $subject instanceof SellerProfile => "{$base}/businesses/{$subject->slug}",
            default => $base,
        };
    }

    /** A short human label for the promoted thing, for the review queue. */
    public function resourceLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Listing => $subject->title,
            $subject instanceof SellerProfile => $subject->display_name,
            default => 'Unknown',
        };
    }

    /**
     * Turn an approved request into servable inventory.
     *
     * Everything is re-verified first — see the class note on why submission
     * checks are not enough.
     *
     * The campaign is minted as a DRAFT, not active. Phase 11A's lifecycle says
     * a campaign goes live by an explicit, audited transition, and approving a
     * request is a different decision from putting it on the site: an
     * administrator may accept a promotion and still want to schedule it, or
     * check the artwork renders, before it starts serving. Silently activating
     * here would also bypass the "must have an active creative" guard that
     * transition enforces.
     */
    public function approve(PromotionRequest $request, User $reviewer): AdCampaign
    {
        if (! $request->status->isReviewable()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'That request has already been reviewed.',
            );
        }

        $subject = $request->promotable;

        if ($subject === null) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'The promoted item no longer exists.',
            );
        }

        // Ownership can change: a listing can be transferred, and an account
        // can be deleted out from under a pending request.
        $vendor = $request->vendor;

        if ($vendor === null || ! $this->isOwnedBy($subject, $vendor)) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'The vendor no longer owns the promoted item.',
            );
        }

        $this->assertPromotable($subject);

        if (! $request->hasArtwork()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'This request has no artwork, so it cannot be turned into a campaign.',
            );
        }

        if ($request->windowHasClosed()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'The requested dates have already passed. Ask the vendor to resubmit.',
            );
        }

        if (! $request->placement->isVendorRequestable()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'That placement is no longer available for vendor promotions.',
            );
        }

        return DB::transaction(function () use ($request, $subject, $vendor, $reviewer): AdCampaign {
            $advertiser = $this->advertiserFor($vendor);

            $campaign = new AdCampaign;
            $campaign->fill([
                'advertiser_id' => $advertiser->getKey(),
                'name' => Str::limit($this->resourceLabel($subject), 150, ''),
                'placement' => $request->placement->value,
                /*
                 * The date-only window is widened to cover both endpoints in
                 * full. A vendor asking for "the 3rd to the 10th" means through
                 * the end of the 10th; a naive midnight-to-midnight window
                 * would silently drop the last day they paid for.
                 */
                'starts_at' => $request->requested_start->copy()->startOfDay(),
                'ends_at' => $request->requested_end->copy()->endOfDay(),
                /*
                 * Priority stays at the default.
                 *
                 * A vendor never proposes their own priority — that is a
                 * commercial term, and a self-service field for it would arrive
                 * at the maximum on every request. An administrator raises it
                 * afterwards if the deal warrants it.
                 */
                'priority' => 0,
            ]);

            $campaign->forceFill([
                'status' => AdCampaignStatus::Draft->value,
                'created_by' => $reviewer->getKey(),
            ])->save();

            /*
             * Targeting: the promoted listing's VERTICAL, not its leaf category.
             *
             * Both are relevant; the difference is reach, and it is large.
             * `AdServingService` matches a campaign when its targeted category
             * is anywhere in the BROWSED category's ancestor chain. Target the
             * leaf "Apartments" and the promotion appears only to somebody who
             * has already narrowed to apartments — not to the far larger number
             * browsing Property. Target the vertical and it reaches both, while
             * still never appearing on used cars.
             *
             * `pathIds()` is the materialised ancestor chain with the root
             * first, so element zero is the vertical.
             *
             * Business promotions are left untargeted: a business spans
             * whatever it happens to list, so there is no honest category to
             * pick.
             */
            if ($subject instanceof Listing && $subject->category !== null) {
                $lineage = $subject->category->pathIds();
                $vertical = $lineage[0] ?? $subject->category_id;

                if ($vertical !== null) {
                    $campaign->categories()->sync([$vertical]);
                }
            }

            $creative = new AdCreative;
            $creative->fill([
                'ad_campaign_id' => $campaign->getKey(),
                'headline' => $request->headline,
                'body' => $request->body,
                'cta_label' => $request->cta_label,
                // Derived, never vendor-supplied. See resourceUrl().
                'click_url' => $this->resourceUrl($subject),
                'alt_text' => Str::limit($request->headline, 250, ''),
                'is_active' => true,
                'position' => 0,
            ]);

            /*
             * The media rows are REFERENCED, not moved.
             *
             * `ad_creatives.image_media_id` is a plain foreign key, so the
             * creative can point at artwork whose polymorphic owner is still
             * the promotion request. That keeps the request renderable in the
             * vendor's history after approval — moving the rows would leave
             * their own screen showing a promotion with no artwork.
             */
            $creative->forceFill([
                'image_media_id' => $request->image_media_id,
                'mobile_media_id' => $request->mobile_media_id,
            ])->save();

            $request->forceFill([
                'status' => PromotionRequestStatus::Approved->value,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => Carbon::now(),
                'ad_campaign_id' => $campaign->getKey(),
                'rejection_reason' => null,
            ])->save();

            return $campaign;
        });
    }

    public function reject(PromotionRequest $request, User $reviewer, string $reason): void
    {
        if (! $request->status->isReviewable()) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'That request has already been reviewed.',
            );
        }

        $request->forceFill([
            'status' => PromotionRequestStatus::Rejected->value,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * The billing record for a vendor's promotions.
     *
     * One advertiser per vendor, created on first APPROVAL rather than on
     * submission — otherwise the advertiser list, which is a list of people
     * SAKA bills, fills up with vendors who only ever had a request declined.
     *
     * Linked through `seller_profile_id` so an administrator can move between
     * the campaign and the storefront, and so a vendor's second approved
     * promotion reuses the same advertiser instead of creating a duplicate.
     */
    private function advertiserFor(User $vendor): Advertiser
    {
        $profile = SellerProfile::query()->where('user_id', $vendor->getKey())->first();

        if ($profile !== null) {
            $existing = Advertiser::query()->where('seller_profile_id', $profile->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $name = $profile?->display_name ?: $vendor->fullName();

        $advertiser = new Advertiser;
        $advertiser->fill([
            'name' => $name,
            'contact_email' => $vendor->email,
            'is_active' => true,
        ]);

        $advertiser->forceFill([
            'slug' => $this->uniqueAdvertiserSlug($name),
            'seller_profile_id' => $profile?->getKey(),
        ])->save();

        return $advertiser;
    }

    private function uniqueAdvertiserSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'vendor';
        $slug = $base;
        $suffix = 2;

        while (Advertiser::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** @return array<int, array<string, mixed>> */
    public function requestablePlacements(): array
    {
        return array_map(
            fn (AdPlacement $placement): array => $placement->toArray(),
            AdPlacement::vendorRequestable(),
        );
    }
}
