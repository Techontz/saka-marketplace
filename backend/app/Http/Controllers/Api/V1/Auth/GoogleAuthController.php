<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\GoogleSignInRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\Identity\AuthService;
use App\Services\Identity\GoogleTokenVerifier;
use App\Services\Identity\TokenService;
use Illuminate\Http\JsonResponse;

/**
 * Google sign-in. Backs the "Continue with Google" button already in the
 * frontend login dialog.
 *
 * One endpoint handles both sign-up and sign-in: whether the account already
 * exists is Google's business to assert and ours to resolve, not something the
 * client should have to decide.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly AuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(GoogleSignInRequest $request): JsonResponse
    {
        $profile = $this->verifier->verify((string) $request->validated('id_token'));

        $user = $this->auth->resolveOAuthUser(OAuthProvider::Google, $profile);
        $this->auth->recordLogin($user);

        $token = $this->tokens->issue($user, (string) $request->input('device_name', 'api'));

        return response()->json([
            'data' => [
                'user' => new UserResource($user->load(['roles', 'sellerProfile'])),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $this->tokens->expiry()->toAtomString(),
            ],
        ]);
    }
}
