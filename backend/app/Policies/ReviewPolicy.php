<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Identity\Enums\Permission;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function view(?User $user, Review $review): bool
    {
        if ($review->status === ReviewStatus::Approved) {
            return true;
        }

        return $user !== null && (
            $user->getKey() === $review->reviewer_id
            || $user->getKey() === $review->seller_id
            || $user->hasPermission(Permission::ReviewModerate)
        );
    }

    public function update(User $user, Review $review): bool
    {
        /*
         * The author, at any point in the review's life.
         *
         * This used to be restricted to PENDING reviews, to stop someone
         * swapping praise for abuse after approval. That protection is now
         * where it belongs — an edited approved review goes straight back to
         * pending (see Account\ReviewController::update), so a moderator's
         * decision always applies to the text that is actually live.
         *
         * The old rule also made editing impossible in practice: with
         * moderation off, reviews are approved on creation, so nothing was
         * ever in a state the author could correct.
         *
         * A rejected review stays editable on purpose — being told what was
         * wrong and then being unable to fix it is a dead end.
         */
        return $user->getKey() === $review->reviewer_id;
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->getKey() === $review->reviewer_id
            || $user->hasPermission(Permission::ReviewModerate);
    }

    /** The seller being reviewed may post one public response. */
    public function reply(User $user, Review $review): bool
    {
        return $user->getKey() === $review->seller_id && $review->reply_body === null;
    }

    public function moderate(User $user): bool
    {
        return $user->hasPermission(Permission::ReviewModerate);
    }
}
