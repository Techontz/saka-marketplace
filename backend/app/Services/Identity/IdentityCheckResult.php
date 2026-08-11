<?php

declare(strict_types=1);

namespace App\Services\Identity;

/**
 * The outcome of an automated identity check.
 *
 * THREE outcomes, not two, and the third is the important one.
 *
 * A boolean would force "we could not check" to be reported as "did not pass",
 * which is how an unreachable provider turns into a wave of wrongly-rejected
 * vendors. `Unavailable` is a first-class answer here and today it is the ONLY
 * answer the platform can give — see ManualReviewProvider.
 */
final readonly class IdentityCheckResult
{
    private function __construct(
        public string $outcome,
        public string $provider,
        public ?string $message = null,
    ) {}

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const UNAVAILABLE = 'unavailable';

    public static function passed(string $provider, ?string $message = null): self
    {
        return new self(self::PASSED, $provider, $message);
    }

    public static function failed(string $provider, string $message): self
    {
        return new self(self::FAILED, $provider, $message);
    }

    /**
     * No automated check was possible.
     *
     * Distinct from a failure and MUST be rendered differently: a vendor whose
     * document could not be machine-checked has done nothing wrong, and telling
     * them otherwise is both false and discouraging.
     */
    public static function unavailable(string $provider, ?string $message = null): self
    {
        return new self(self::UNAVAILABLE, $provider, $message);
    }

    public function isConclusive(): bool
    {
        return $this->outcome !== self::UNAVAILABLE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'provider' => $this->provider,
            'message' => $this->message,
        ];
    }
}
