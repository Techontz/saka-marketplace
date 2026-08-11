<?php

declare(strict_types=1);

namespace App\Domain\Trust\Enums;

/**
 * Highest verification a seller has attained. Ordered — a seller at `Business`
 * implicitly satisfies `Phone`.
 */
enum VerificationLevel: string
{
    case None = 'none';
    case Phone = 'phone';
    case Id = 'id';
    case Business = 'business';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Phone => 1,
            self::Id => 2,
            self::Business => 3,
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
