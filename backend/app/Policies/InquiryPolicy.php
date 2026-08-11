<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Inquiry;
use App\Models\User;

class InquiryPolicy
{
    public function view(User $user, Inquiry $inquiry): bool
    {
        return $user->getKey() === $inquiry->seller_id
            || $user->getKey() === $inquiry->sender_user_id
            || $user->hasPermission(Permission::InquiryViewAny);
    }

    /** Only the seller who received it may reply. */
    public function respond(User $user, Inquiry $inquiry): bool
    {
        return $user->getKey() === $inquiry->seller_id
            && $user->hasPermission(Permission::InquiryRespond);
    }
}
