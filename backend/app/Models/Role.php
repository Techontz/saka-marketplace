<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Extends spatie's Role with SAKA's presentation metadata.
 *
 * `name` on this table holds the slug value (`buyer`, `super_admin`, …) because
 * spatie resolves roles by `name`; the human label comes from RoleSlug::label().
 * Keeping one canonical identifier avoids the classic name/slug drift where a
 * renamed role silently stops matching a check.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string|null $description
 * @property int $level
 * @property bool $is_assignable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static Builder<static>|Role assignable()
 * @method static Builder<static>|Role newModelQuery()
 * @method static Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, bool $without = false)
 * @method static Builder<static>|Role query()
 * @method static Builder<static>|Role whereCreatedAt($value)
 * @method static Builder<static>|Role whereDescription($value)
 * @method static Builder<static>|Role whereGuardName($value)
 * @method static Builder<static>|Role whereId($value)
 * @method static Builder<static>|Role whereIsAssignable($value)
 * @method static Builder<static>|Role whereLevel($value)
 * @method static Builder<static>|Role whereName($value)
 * @method static Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 *
 * @mixin \Eloquent
 */
class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'description', 'level', 'is_assignable'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_assignable' => 'boolean',
        ];
    }

    public function slugEnum(): ?RoleSlug
    {
        return RoleSlug::tryFrom($this->name);
    }

    public function label(): string
    {
        return $this->slugEnum()?->label() ?? $this->name;
    }

    /** Roles that may be handed out through the admin UI. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('is_assignable', true);
    }
}
