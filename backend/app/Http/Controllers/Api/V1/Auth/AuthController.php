<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\RegisterRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Identity\AuthService;
use App\Services\Identity\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email/password registration, login and session lifecycle.
 *
 * Controllers stay thin: validate shape (FormRequest) -> delegate (Service) ->
 * shape output (Resource). No business rules live here.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());
        $token = $this->tokens->issue($user, (string) $request->input('device_name', 'api'));

        return $this->tokenResponse($user, $token, Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attemptLogin(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
        );

        $this->auth->recordLogin($user);
        $token = $this->tokens->issue($user, (string) $request->input('device_name', 'api'));

        return $this->tokenResponse($user, $token);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'sellerProfile', 'avatar']);

        return response()->json(['data' => new UserResource($user)]);
    }

    /** Rotates the current token. */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $this->tokens->refresh($user, (string) $request->input('device_name', 'api'));

        return $this->tokenResponse($user, $token);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revokeCurrent($request->user());

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }

    /** Revokes every token — "sign out everywhere". */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user());

        return response()->json(['data' => ['message' => 'Signed out on all devices.']]);
    }

    private function tokenResponse(User $user, NewAccessToken $token, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($user->load(['roles', 'sellerProfile'])),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $this->tokens->expiry()->toAtomString(),
            ],
        ], $status);
    }
}
