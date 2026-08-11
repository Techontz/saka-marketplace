<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\RequestOtpRequest;
use App\Http\Requests\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Identity\PhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone OTP. Verifying a phone is what unlocks publishing
 * (Milestone 4 decision 5) — browsing never requires it.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly PhoneVerificationService $phones) {}

    public function request(RequestOtpRequest $request): JsonResponse
    {
        $this->phones->request(
            $request->user(),
            (string) $request->validated('phone'),
        );

        return response()->json([
            'data' => [
                'message' => 'A verification code has been sent.',
                'expires_in_minutes' => (int) config('saka.otp.ttl_minutes'),
                'resend_after_seconds' => (int) config('saka.otp.resend_cooldown_seconds'),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->phones->verify(
            $request->user(),
            (string) $request->validated('phone'),
            (string) $request->validated('code'),
        );

        return response()->json([
            'data' => [
                'message' => 'Phone number verified.',
                'user' => new UserResource($user->load(['roles', 'sellerProfile'])),
            ],
        ]);
    }
}
