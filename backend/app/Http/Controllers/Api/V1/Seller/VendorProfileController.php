<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Identity\Enums\BusinessType;
use App\Domain\Identity\Enums\SocialNetwork;
use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VendorProfileResource;
use App\Models\District;
use App\Models\Media;
use App\Models\Region;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Ward;
use App\Services\Media\MediaUploadService;
use App\Services\Seller\VendorProfileService;
use App\Support\SlugReferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The vendor's own business profile: onboarding and settings.
 *
 * Onboarding and settings are the SAME endpoints deliberately. A wizard that
 * writes through a separate "onboarding" API is a second code path that drifts
 * from the settings screen, and every field ends up validated twice with
 * slightly different rules. Here the wizard is a UI over a partial PATCH, and
 * `progress` tells it where to resume.
 *
 * Every field is optional on write, because a vendor exists from their first
 * listing and fills this in over time.
 */
class VendorProfileController extends Controller
{
    public function __construct(
        private readonly VendorProfileService $profiles,
        private readonly MediaUploadService $media,
    ) {}

    /**
     * The business types, with the rules each one implies.
     *
     * Unauthenticated on purpose: the registration screen needs it before an
     * account exists, and it is static reference data.
     */
    public function businessTypes(): JsonResponse
    {
        return response()->json([
            'data' => $this->profiles->businessTypes(),
            'meta' => [
                // Published so the portal renders the same set of networks the
                // API will accept, rather than carrying its own copy that
                // drifts the moment one is added.
                'social_networks' => array_map(
                    fn (SocialNetwork $network): array => $network->toArray(),
                    SocialNetwork::cases(),
                ),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->profileFor($user);

        return response()->json([
            'data' => new VendorProfileResource(
                $profile->load(['logo', 'cover', 'region', 'district', 'ward']),
            ),
            'meta' => [
                'progress' => $this->profiles->progress($profile, $user),
                // The type's own rules travel with the profile so the portal
                // never carries a second copy of them.
                'business_type' => $profile->business_type?->toArray(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->profileFor($user);

        // The portal picks a location from /locations/*, which publishes slugs
        // and never ids — so accept either form.
        SlugReferenceResolver::resolve($request, [
            'region_id' => Region::class,
            'district_id' => District::class,
            'ward_id' => Ward::class,
        ]);

        $validated = $request->validate([
            // ---- business ------------------------------------------------
            'display_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'business_type' => ['sometimes', Rule::in(BusinessType::values())],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'business_reg_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:40'],

            // ---- location ------------------------------------------------
            'region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'region_slug' => ['sometimes', 'nullable', 'string', 'exists:regions,slug'],
            'district_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
            'district_slug' => ['sometimes', 'nullable', 'string', 'exists:districts,slug'],
            'ward_id' => ['sometimes', 'nullable', 'integer', 'exists:wards,id'],
            'ward_slug' => ['sometimes', 'nullable', 'string', 'exists:wards,slug'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],

            // ---- contact -------------------------------------------------
            'public_email' => ['sometimes', 'nullable', 'email:rfc', 'max:191'],
            'public_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Rendered as a link on the public profile, so the scheme is
            // constrained — `javascript:` in an href is stored XSS.
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],

            // ---- hours & social ------------------------------------------
            'opening_hours' => ['sometimes', 'nullable', 'array'],
            /*
             * Validated loosely HERE and normalised strictly below.
             *
             * `url:http,https` alone was never enough: it accepts a perfectly
             * well-formed link on the WRONG host, so a vendor could store
             * `https://evil.example/phish` under the "instagram" key and the
             * public profile would render the Instagram glyph over it. An icon
             * is a claim about where a link goes.
             *
             * The rule here only bounds the input; SocialNetwork::normaliseAll
             * decides what is actually storable.
             */
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.*' => ['nullable', 'string', 'max:255'],
        ]);

        // Coordinates only make sense as a pair; half a pin is a bad map marker.
        if (array_key_exists('latitude', $validated) !== array_key_exists('longitude', $validated)) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'Latitude and longitude must be sent together.',
            );
        }

        /*
         * Normalise social links before they are stored.
         *
         * Handles are expanded, scheme-less hosts are qualified, credentials
         * and fragments are dropped, unknown networks are discarded, and
         * anything unusable is REMOVED rather than stored blank — an empty
         * string renders as an icon linking nowhere, which is the empty-icon
         * problem the public profile must never have.
         *
         * The key is checked for PRESENCE, not truthiness: sending
         * `social_links: {}` is how a vendor clears every link, and treating
         * that as "no change" would make removal impossible.
         */
        if (array_key_exists('social_links', $validated)) {
            $validated['social_links'] = SocialNetwork::normaliseAll(
                is_array($validated['social_links']) ? $validated['social_links'] : [],
            );
        }

        if (isset($validated['opening_hours']) && is_array($validated['opening_hours'])) {
            $problems = $this->profiles->validateOpeningHours($validated['opening_hours']);

            if ($problems !== []) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'Those opening hours are not valid.',
                    ['opening_hours' => $problems],
                );
            }
        }

        $this->assertLocationHierarchy($validated);

        // Slugs were only ever a way of naming the ids; they are not columns.
        unset($validated['region_slug'], $validated['district_slug'], $validated['ward_slug']);

        DB::transaction(function () use ($profile, $validated): void {
            $profile->fill($validated)->save();

            // The slug is derived once and then frozen: it is the public vendor
            // URL, and renaming a business must not break every link to it.
            if (blank($profile->slug) && filled($profile->display_name)) {
                $profile->forceFill(['slug' => $this->uniqueSlug($profile->display_name)])->save();
            }
        });

        $this->profiles->syncOnboardingState($profile->refresh(), $user);

        return response()->json([
            'data' => new VendorProfileResource(
                $profile->load(['logo', 'cover', 'region', 'district', 'ward']),
            ),
            'meta' => [
                'progress' => $this->profiles->progress($profile, $user),
                'business_type' => $profile->business_type?->toArray(),
            ],
        ]);
    }

    /**
     * Upload a logo or cover image.
     *
     * Separate from the profile PATCH because it is multipart and goes through
     * the media pipeline (validation, EXIF stripping, variant generation),
     * none of which belongs in a JSON field update.
     */
    public function uploadBranding(Request $request, string $kind): JsonResponse
    {
        if (! in_array($kind, ['logo', 'cover'], true)) {
            throw ApiException::notFound();
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.(config('saka.media.max_image_mb', 5) * 1024)],
        ]);

        $user = $request->user();
        $profile = $this->profileFor($user);

        $media = $this->media->upload(
            $request->file('file'),
            $profile,
            $user,
            MediaCollection::Logo,
        );

        $column = $kind === 'logo' ? 'logo_media_id' : 'cover_media_id';

        // Replacing branding leaves the previous file orphaned; delete it so a
        // vendor iterating on a logo does not accumulate dead rows and storage.
        $previousId = $profile->{$column};

        $profile->forceFill([$column => $media->id])->save();

        if ($previousId !== null && $previousId !== $media->id) {
            $previous = Media::find($previousId);

            if ($previous !== null) {
                $this->media->delete($previous);
            }
        }

        $this->profiles->syncOnboardingState($profile->refresh(), $user);

        return response()->json([
            'data' => [
                'kind' => $kind,
                'url' => $media->url(),
                'uuid' => $media->uuid,
            ],
        ]);
    }

    public function deleteBranding(Request $request, string $kind): JsonResponse
    {
        if (! in_array($kind, ['logo', 'cover'], true)) {
            throw ApiException::notFound();
        }

        $profile = $this->profileFor($request->user());
        $column = $kind === 'logo' ? 'logo_media_id' : 'cover_media_id';
        $mediaId = $profile->{$column};

        $profile->forceFill([$column => null])->save();

        if ($mediaId !== null) {
            $media = Media::find($mediaId);

            if ($media !== null) {
                $this->media->delete($media);
            }
        }

        return response()->json(['data' => ['kind' => $kind, 'removed' => true]]);
    }

    // ------------------------------------------------------------- internals

    /**
     * The vendor's profile, created on first access if absent.
     *
     * A user becomes a seller when they publish their first listing, and the
     * profile row may not exist yet. Creating it lazily here means the portal
     * never has to special-case "no profile" — every screen can assume one.
     */
    private function profileFor(User $user): SellerProfile
    {
        // withTrashed: `user_id` is unique, so a soft-deleted profile must be
        // restored rather than inserted alongside — the insert would violate
        // the constraint and 500 on a vendor who had once been removed.
        $profile = SellerProfile::withTrashed()->where('user_id', $user->getKey())->first();

        if ($profile !== null) {
            if ($profile->trashed()) {
                $profile->restore();
            }

            return $profile;
        }

        $profile = new SellerProfile;
        $profile->forceFill([
            'user_id' => $user->getKey(),
            'display_name' => $user->fullName(),
            'slug' => $this->uniqueSlug($user->fullName()),
        ])->save();

        /*
         * Reload before returning.
         *
         * Columns with DATABASE defaults — verification_level, the counters —
         * are not populated on the in-memory model after an insert, so the
         * resource would read null from a non-nullable column and 500 on the
         * very first request a new vendor makes.
         */
        return $profile->refresh();
    }

    /**
     * A district must belong to its region, and a ward to its district.
     *
     * `exists:` proves each id is real but not that they belong together, so
     * without this a vendor could be stored in Ilala, Arusha.
     *
     * @param  array<string, mixed>  $validated
     */
    private function assertLocationHierarchy(array $validated): void
    {
        $districtId = $validated['district_id'] ?? null;
        $regionId = $validated['region_id'] ?? null;

        if ($districtId !== null && $regionId !== null) {
            $belongs = DB::table('districts')
                ->where('id', $districtId)
                ->where('region_id', $regionId)
                ->exists();

            if (! $belongs) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'That district is not in the selected region.',
                );
            }
        }

        $wardId = $validated['ward_id'] ?? null;

        if ($wardId !== null && $districtId !== null) {
            $belongs = DB::table('wards')
                ->where('id', $wardId)
                ->where('district_id', $districtId)
                ->exists();

            if (! $belongs) {
                throw ApiException::make(
                    ErrorCode::ValidationFailed,
                    'That ward is not in the selected district.',
                );
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'vendor';
        $slug = $base;
        $suffix = 2;

        while (SellerProfile::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
