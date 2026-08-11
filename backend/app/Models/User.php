<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engagement\Enums\NotificationType;
use App\Domain\Identity\Enums\Permission as PermissionEnum;
use App\Domain\Identity\Enums\RoleSlug;
use App\Domain\Identity\Enums\UserStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property string $first_name
 * @property string|null $last_name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $password
 * @property int|null $avatar_media_id
 * @property string $locale
 * @property UserStatus $status
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_seen_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Media|null $avatar
 * @property-read Collection<int, Listing> $favoriteListings
 * @property-read int|null $favorite_listings_count
 * @property-read Collection<int, Favorite> $favorites
 * @property-read int|null $favorites_count
 * @property-read Collection<int, Listing> $listings
 * @property-read int|null $listings_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, OAuthIdentity> $oauthIdentities
 * @property-read int|null $oauth_identities_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, Inquiry> $receivedInquiries
 * @property-read int|null $received_inquiries_count
 * @property-read Collection<int, Review> $reviewsReceived
 * @property-read int|null $reviews_received_count
 * @property-read Collection<int, Review> $reviewsWritten
 * @property-read int|null $reviews_written_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read SellerProfile|null $sellerProfile
 * @property-read Collection<int, Inquiry> $sentInquiries
 * @property-read int|null $sent_inquiries_count
 * @property-read Collection<int, Permission> $teams
 * @property-read int|null $teams_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read Collection<int, VerificationRequest> $verificationRequests
 * @property-read int|null $verification_requests_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User team($teams, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarMediaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhoneVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTeam($teams)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'password',
        'avatar_media_id', 'locale', 'status', 'notification_preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'notification_preferences' => 'array',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    // ---------------------------------------------------------------- relations
    //
    // `roles()` and `permissions()` come from spatie's HasRoles trait, along
    // with assignRole/hasRole/hasPermissionTo and the Redis-backed permission
    // cache. Nothing here re-implements them.

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Listings this user has saved and not since removed.
     *
     * The pivot is polymorphic, so the type constraint is what stops saved
     * BUSINESSES being returned as listings that happen to share an id.
     */
    public function favoriteListings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'favorites', 'user_id', 'favoritable_id')
            ->wherePivot('favoritable_type', Listing::class)
            ->wherePivotNull('removed_at');
    }

    /** Businesses this user has saved and not since removed. */
    public function favoriteSellers(): BelongsToMany
    {
        return $this->belongsToMany(SellerProfile::class, 'favorites', 'user_id', 'favoritable_id')
            ->wherePivot('favoritable_type', SellerProfile::class)
            ->wherePivotNull('removed_at');
    }

    /**
     * Whether this user wants to hear about something.
     *
     * A preference that has never been set falls back to the platform default,
     * which is why the column is nullable rather than seeded at registration.
     */
    public function wantsNotification(?string $preferenceKey): bool
    {
        if ($preferenceKey === null) {
            return true; // not switchable — see NotificationType::preferenceKey()
        }

        $defaults = NotificationType::preferenceDefaults();
        $chosen = $this->notification_preferences ?? [];

        return (bool) ($chosen[$preferenceKey] ?? $defaults[$preferenceKey] ?? true);
    }

    /** Inquiries this user received as a seller. */
    public function receivedInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'seller_id');
    }

    /** Inquiries this user sent. */
    public function sentInquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'sender_user_id');
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'seller_id');
    }

    public function reviewsWritten(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OAuthIdentity::class);
    }

    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    // ------------------------------------------------------------- authorization
    //
    // Thin, type-safe wrappers over spatie. They exist so application code
    // passes the Permission/RoleSlug ENUM rather than a magic string — a typo
    // then fails at the call site instead of silently denying access.

    /** @return array<int, string> */
    public function permissionSlugs(): array
    {
        return $this->getAllPermissions()->pluck('name')->all();
    }

    public function hasPermission(PermissionEnum|string $permission): bool
    {
        $slug = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return $this->hasPermissionTo($slug);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(array_map(
            fn (RoleSlug $role) => $role->value,
            RoleSlug::staff(),
        ));
    }

    // ------------------------------------------------------------------ helpers

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Publishing gate (Milestone 4 decision 5): browsing stays open to guests,
     * but PUBLISHING a listing requires a verified phone number.
     */
    public function canPublishListings(): bool
    {
        if (! config('saka.listings.require_phone_verification_to_publish')) {
            return true;
        }

        return $this->hasVerifiedPhone();
    }
}
