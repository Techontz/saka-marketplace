<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Enums;

use App\Models\PromotionRequest;

/**
 * The review lifecycle of a vendor's promotion request.
 *
 * THIS IS A REVIEW STATE, NOT A PAYMENT STATE, AND THAT SEPARATION IS THE WHOLE
 * POINT OF THE ENUM.
 *
 * SAKA has no payment infrastructure today. The tempting shortcut is to add
 * `awaiting_payment` and `paid` here now and leave them unused — which is how a
 * request ends up with a status the product cannot actually produce, and how
 * "Paid" appears on a screen when no money has moved.
 *
 * Payment is an ORTHOGONAL AXIS. A request is reviewed (pending → approved) and
 * separately settled (unpaid → paid → refunded). Modelling them as one column
 * forces a cross-product — `approved_unpaid`, `approved_paid`,
 * `approved_refunded` — and every existing query has to learn the new values.
 *
 * So when payments arrive they attach as their own table keyed on the request,
 * and NOTHING here changes: the review states keep their meaning, the existing
 * queries keep working, and a request that is approved-but-unpaid is expressed
 * by the absence of a payment row rather than by a status nobody can interpret.
 *
 * @see PromotionRequest — the docblock there names the columns a
 *      future `promotion_payments` table would carry.
 */
enum PromotionRequestStatus: string
{
    /**
     * Being filled in. Never seen by an administrator.
     *
     * Not ceremony — it falls out of how artwork works. Media is polymorphic
     * and needs an owner ROW to attach to, so the request must exist before the
     * vendor can upload a banner to it. Without a draft state every half-filled
     * wizard would land in the review queue with no artwork, and an
     * administrator would spend their day rejecting things nobody had finished
     * writing.
     */
    case Draft = 'draft';

    /** Submitted, waiting for an administrator. */
    case Pending = 'pending';

    /** Accepted. A draft campaign now exists; an administrator still activates it. */
    case Approved = 'approved';

    /** Declined, with a reason the vendor can read. */
    case Rejected = 'rejected';

    /** Withdrawn by the vendor before review. */
    case Cancelled = 'cancelled';

    /**
     * Its requested window closed before anyone reviewed it.
     *
     * A real state, not a tidy-up: a vendor asking for "next Monday to Friday"
     * and getting no answer until the Saturday must not have that silently
     * approved into a window that has already passed.
     */
    case Expired = 'expired';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            // "Pending review", not "Awaiting payment" — nothing has been
            // charged, and the label must not imply otherwise.
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** Whether an administrator may still act on it. */
    public function isReviewable(): bool
    {
        return $this === self::Pending;
    }

    /** Whether the vendor may still withdraw it. */
    public function isCancellable(): bool
    {
        return $this === self::Draft || $this === self::Pending;
    }

    /** Whether the vendor may still edit it — including replacing artwork. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether it is waiting to be submitted rather than waiting on us. */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the decision has been made and cannot change.
     *
     * NOT `! isReviewable()`. A draft is not reviewable either, and treating it
     * as terminal would lock a vendor out of editing the request they are
     * halfway through writing.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Draft, self::Pending => false,
            self::Approved, self::Rejected, self::Cancelled, self::Expired => true,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'is_reviewable' => $this->isReviewable(),
            'is_cancellable' => $this->isCancellable(),
            'is_editable' => $this->isEditable(),
        ];
    }
}
