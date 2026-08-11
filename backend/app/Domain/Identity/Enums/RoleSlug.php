<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Roles are convenience bundles of permissions. Authorization ALWAYS checks a
 * permission, never a role name — that is what keeps a role change from
 * becoming a code change.
 */
enum RoleSlug: string
{
    case Buyer = 'buyer';
    case Seller = 'seller';
    case Agent = 'agent';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function level(): int
    {
        return match ($this) {
            self::Buyer => 10,
            self::Seller => 20,
            self::Agent => 30,
            self::Moderator => 60,
            self::Admin => 80,
            self::SuperAdmin => 100,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Seller => 'Seller',
            self::Agent => 'Agent',
            self::Moderator => 'Moderator',
            self::Admin => 'Administrator',
            self::SuperAdmin => 'Super administrator',
        };
    }

    /**
     * Roles that may access the admin surface at all.
     *
     * @return array<int, self>
     */
    public static function staff(): array
    {
        return [self::Moderator, self::Admin, self::SuperAdmin];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
