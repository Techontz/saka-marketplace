<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Listing\Enums\ListingStatus;
use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Account\UpdatePasswordRequest;
use App\Http\Requests\V1\Account\UpdateProfileRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Media;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function __construct(private readonly MediaUploadService $media) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'sellerProfile', 'avatar']);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Changing the email invalidates its verified state — otherwise a user
        // could inherit verification for an address they have never proven.
        // `email_verified_at` is guarded, so it is set explicitly rather than
        // smuggled through fill().
        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $user->email;

        /*
         * A NEW PHONE IS AN UNVERIFIED PHONE.
         *
         * This is the reason phone could not simply be added to the validator
         * and left there. `phone_verified_at` is the gate on publishing a
         * listing (see User::canPublishListings), so without this line anyone
         * who had verified one number could PATCH in any other number — a
         * stranger's, a disposable one — and keep the verified state and the
         * ability to publish under it. The verification has to follow the
         * number it was granted for, exactly as email verification does.
         *
         * The customer re-verifies through the existing OTP endpoints; nothing
         * else about the account is disturbed.
         */
        $phoneChanged = array_key_exists('phone', $data) && $data['phone'] !== $user->phone;

        $user->fill($data);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($phoneChanged) {
            $user->phone_verified_at = null;
        }

        $user->save();

        return response()->json([
            'data' => new UserResource($user->fresh()->load(['roles', 'sellerProfile', 'avatar'])),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->password === null) {
            throw ApiException::make(ErrorCode::NoPasswordSet);
        }

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill(['password' => $request->validated('password')])->save();

            // Revoke every OTHER session; the caller keeps working.
            $current = $user->currentAccessToken();
            $user->tokens()->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
        });

        return response()->json([
            'data' => ['message' => 'Password updated. Other sessions have been signed out.'],
        ]);
    }

    /**
     * Upload or replace the account avatar.
     *
     * Separate from the profile PATCH because it is multipart and goes through
     * the media pipeline — validation, EXIF stripping, variant generation —
     * none of which belongs in a JSON field update.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:'.((int) config('saka.media.max_image_mb', 5) * 1024)],
        ]);

        $user = $request->user();
        $previousId = $user->avatar_media_id;

        $media = $this->media->upload($request->file('avatar'), $user, $user, MediaCollection::Avatar);

        $user->forceFill(['avatar_media_id' => $media->getKey()])->save();

        /*
         * Replacing an avatar orphans the old one.
         *
         * Deleted through the media service, not `$model->delete()`: the model
         * has no delete hook, so deleting the ROW leaves the file and every
         * generated variant on disk forever. Every other call site in the app
         * goes through the service for exactly this reason.
         */
        if ($previousId !== null && $previousId !== $media->getKey()) {
            $previous = Media::find($previousId);

            if ($previous !== null) {
                $this->media->delete($previous);
            }
        }

        return response()->json([
            'data' => new UserResource($user->fresh()->load(['roles', 'sellerProfile', 'avatar'])),
        ]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $mediaId = $user->avatar_media_id;

        if ($mediaId === null) {
            throw ApiException::notFound('There is no avatar to remove.');
        }

        $user->forceFill(['avatar_media_id' => null])->save();

        $media = Media::find($mediaId);

        if ($media !== null) {
            $this->media->delete($media);
        }

        return response()->json([
            'data' => new UserResource($user->fresh()->load(['roles', 'sellerProfile', 'avatar'])),
        ]);
    }

    /**
     * Close the account.
     *
     * A SOFT delete, and deliberately so. Hard-deleting the row would cascade
     * through listings, reviews and inquiries — taking with it reviews other
     * people rely on when judging a seller, and inquiries a business still has
     * open. What this does instead:
     *
     *   - releases the email and phone, so the person can register again;
     *   - revokes every token, so the account is immediately unusable;
     *   - archives live listings, so nothing stays on sale with no one behind
     *     it.
     *
     * The password is re-checked here even though the caller is authenticated:
     * this is irreversible from the customer's side, and a stolen session must
     * not be enough to destroy an account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->password !== null) {
            $validated = $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['password'], $user->password)) {
                throw ApiException::make(ErrorCode::InvalidCredentials, 'That password is not correct.');
            }
        }

        DB::transaction(function () use ($user): void {
            $user->listings()
                ->whereIn('status', [
                    ListingStatus::Published->value,
                    ListingStatus::PendingReview->value,
                    ListingStatus::Paused->value,
                ])
                ->update(['status' => ListingStatus::Archived->value]);

            // Freed so the address can be used again, and suffixed rather than
            // nulled because the column is unique and NOT NULL.
            $user->forceFill([
                'email' => 'deleted+'.$user->uuid.'@saka.invalid',
                'phone' => null,
                // Suspended, not a new 'deleted' status: every guard in the
                // app already refuses a suspended account, and adding a status
                // would mean auditing all of them.
                'status' => UserStatus::Suspended,
            ])->save();

            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'data' => ['message' => 'Your account has been closed.'],
        ]);
    }
}
