<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Domain\Trust\Enums\VerificationType;

/**
 * The seam for an automated identity check.
 *
 * SAKA HAS NO AUTOMATED NIDA VERIFICATION, and this interface is how that fact
 * is made explicit rather than implied by its absence.
 *
 * NIDA (the National Identification Authority) does not publish an integration
 * a marketplace can call. Every identity check on this platform is a HUMAN
 * reading a document. The alternative — a service that pattern-matches the
 * number and reports "verified" — would be a lie with a green tick on it, and
 * the tick is the entire product: a buyer trusting a verified badge is trusting
 * that somebody checked.
 *
 * So the default implementation says NOT AVAILABLE, loudly and in a shape the
 * UI can render, and the manual review queue does the actual work. When an
 * official provider exists, it implements this interface, gets bound in a
 * service provider, and nothing else changes: the controller already asks, the
 * resource already carries the answer, and the frontend already distinguishes
 * "automated check unavailable" from "checked and failed".
 */
interface IdentityVerificationProvider
{
    /**
     * Whether an automated check can be attempted at all.
     *
     * Read by the API so the vendor and admin surfaces can say which kind of
     * verification is in force, instead of leaving a human queue looking like a
     * broken robot.
     */
    public function isAvailable(): bool;

    /** A short name for the provider, for display and audit. */
    public function name(): string;

    /**
     * Attempt an automated check.
     *
     * Implementations MUST NOT return a positive result they cannot stand
     * behind. `IdentityCheckResult::unavailable()` is always a legitimate
     * answer; a fabricated pass is not.
     */
    public function check(VerificationType $type, string $documentNumber, ?string $fullName = null): IdentityCheckResult;
}
