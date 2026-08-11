<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Identity\Enums\Permission;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    /** Permission over media follows the model it is attached to. */
    public function delete(User $user, Media $media): bool
    {
        if ($user->hasPermission(Permission::MediaDeleteAny)) {
            return true;
        }

        $owner = $media->mediable;

        if ($owner instanceof Listing) {
            return $user->can('manageMedia', $owner);
        }

        return $media->uploaded_by === $user->getKey();
    }

    public function update(User $user, Media $media): bool
    {
        return $this->delete($user, $media);
    }
}
