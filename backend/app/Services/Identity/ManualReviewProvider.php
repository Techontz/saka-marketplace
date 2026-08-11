<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Domain\Trust\Enums\VerificationType;

/**
 * The provider SAKA actually runs on: a person.
 *
 * NOT IMPLEMENTED — AUTOMATED NIDA VERIFICATION.
 *
 * The National Identification Authority does not expose an integration this
 * platform can call, so there is no automated check to perform and this class
 * does not pretend to perform one. `check()` always returns `unavailable`, which
 * both surfaces render as "Automated verification unavailable — reviewed by our
 * team" rather than as a failure.
 *
 * The temptation this class exists to refuse is a "validator" that checks the
 * number is twenty digits and reports PASSED. That is a format check wearing a
 * verification badge, and the badge is the whole product: a buyer trusting a
 * verified vendor is trusting that somebody looked at a document, not that a
 * regular expression matched.
 *
 * When an official provider becomes available, implement
 * IdentityVerificationProvider against it and bind it in AppServiceProvider.
 * Nothing else needs to change — the controller already asks, the resources
 * already carry the answer, and both UIs already distinguish "unavailable" from
 * "failed".
 */
final class ManualReviewProvider implements IdentityVerificationProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'manual_review';
    }

    public function check(VerificationType $type, string $documentNumber, ?string $fullName = null): IdentityCheckResult
    {
        return IdentityCheckResult::unavailable(
            $this->name(),
            'No automated identity provider is connected. A member of the SAKA team reviews every document by hand.',
        );
    }
}
