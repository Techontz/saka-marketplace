<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Identity\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\UpdateUserRolesRequest;
use App\Http\Requests\V1\Admin\UpdateUserStatusRequest;
use App\Http\Resources\V1\Admin\AdminUserResource;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * User administration.
 *
 * Two invariants run through every action here:
 *  - an admin can never act on THEMSELVES (no self-ban, no self-demotion),
 *    because that is how an organisation locks itself out of its own platform;
 *  - the super-admin role is never grantable through the API.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission($request, Permission::UserViewAny);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:'.implode(',', UserStatus::values())],
            'role' => ['nullable', 'string', 'in:'.implode(',', RoleSlug::values())],
            'verified_phone' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles:id,name', 'sellerProfile'])
            ->withCount('listings')
            ->when($validated['q'] ?? null, fn ($q, $term) => $q->where(function ($w) use ($term): void {
                $w->where('email', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['role'] ?? null, fn ($q, $r) => $q->whereHas('roles', fn ($w) => $w->where('name', $r)))
            ->when(isset($validated['verified_phone']), fn ($q) => filter_var($validated['verified_phone'], FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('phone_verified_at')
                : $q->whereNull('phone_verified_at'))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, Permission::UserViewAny);

        return response()->json([
            'data' => new AdminUserResource($user->load(['roles', 'sellerProfile'])->loadCount('listings')),
        ]);
    }

    /** Suspend, ban, or restore. */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $this->assertNotSelf($request, $user);
        $this->assertNotSuperAdmin($user);

        $status = UserStatus::from((string) $request->validated('status'));

        DB::transaction(function () use ($user, $status): void {
            $user->forceFill(['status' => $status])->save();

            // Suspension or a ban must end the session immediately, not when
            // the token happens to expire.
            if (! $status->canAuthenticate()) {
                $user->tokens()->delete();
            }
        });

        $this->audit->record(
            'user.status_changed',
            $request->user(),
            $user,
            ['status' => $user->getOriginal('status')],
            ['status' => $status->value, 'reason' => $request->validated('reason')],
        );

        return response()->json([
            'data' => new AdminUserResource($user->fresh()->load('roles')),
        ]);
    }

    public function updateRoles(UpdateUserRolesRequest $request, User $user): JsonResponse
    {
        $this->assertNotSelf($request, $user);
        $this->assertNotSuperAdmin($user);

        $roles = (array) $request->validated('roles');

        // Only roles flagged assignable may be granted; super_admin is not.
        $assignable = Role::query()->assignable()->whereIn('name', $roles)->pluck('name')->all();

        if (count($assignable) !== count($roles)) {
            throw ApiException::make(
                ErrorCode::Forbidden,
                'One or more of those roles cannot be assigned through the API.',
                ['assignable' => $assignable],
            );
        }

        $previous = $user->getRoleNames()->all();
        $user->syncRoles($assignable);

        $this->audit->record(
            'user.roles_changed',
            $request->user(),
            $user,
            ['roles' => $previous],
            ['roles' => $assignable],
        );

        return response()->json([
            'data' => new AdminUserResource($user->fresh()->load('roles')),
        ]);
    }

    /**
     * Send the user a password-reset link.
     *
     * Note what this does NOT do: set a password, or return one. An
     * administrator who can choose another account's password can sign in as
     * them and act with their identity, and no audit trail can distinguish
     * that from the real user. Sending the standard reset link keeps the
     * credential exclusively between the platform and its owner, and it is the
     * same flow the user would get from "forgot password".
     *
     * The response is identical whether or not the mail is deliverable — the
     * only signal an administrator needs is "sent", and reporting delivery
     * failure here would leak mailbox state.
     */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, Permission::UserUpdate);
        $this->assertNotSuperAdmin($user);

        Password::sendResetLink(['email' => $user->email]);

        $this->audit->record('user.password_reset_sent', $request->user(), $user);

        return response()->json([
            'data' => ['message' => "A password reset link has been sent to {$user->email}."],
        ]);
    }

    /**
     * What this user has done, and what has been done to them.
     *
     * Two directions, because both are what "view activity" means when you are
     * investigating an account: entries where they were the ACTOR, and entries
     * where they were the SUBJECT of an administrative action.
     */
    public function activity(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, Permission::ActivityLogView);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuditEvent::query()
            ->with('actor:id,uuid,first_name,last_name,email')
            ->where(function ($query) use ($user): void {
                $query->where('actor_id', $user->getKey())
                    ->orWhere(function ($subject) use ($user): void {
                        $subject->where('subject_type', User::class)
                            ->where('subject_id', $user->getKey());
                    });
            })
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return response()->json([
            'data' => collect($events->items())->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                // Which side of the entry this user is on — the difference
                // between "they suspended someone" and "they were suspended".
                'direction' => $event->actor_id === $user->getKey() ? 'performed' : 'received',
                'actor_label' => $event->actor_label,
                'changes' => $event->after,
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at?->toAtomString(),
            ])->all(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    private function authorizePermission(Request $request, Permission $permission): void
    {
        if (! $request->user()?->hasPermission($permission)) {
            throw ApiException::forbidden();
        }
    }

    /** An admin must not be able to lock themselves out. */
    private function assertNotSelf(Request $request, User $target): void
    {
        if ($request->user()?->getKey() === $target->getKey()) {
            throw ApiException::make(
                ErrorCode::Forbidden,
                'You cannot change your own status or roles.',
            );
        }
    }

    private function assertNotSuperAdmin(User $target): void
    {
        if ($target->hasRole(RoleSlug::SuperAdmin->value)) {
            throw ApiException::make(
                ErrorCode::Forbidden,
                'Super administrators cannot be modified through the API.',
            );
        }
    }
}
