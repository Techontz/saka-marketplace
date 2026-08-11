<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\Favorite;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Metrics\CounterService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Backs the heart buttons the frontend renders on listings and businesses.
 *
 * Two properties matter here:
 *
 *  1. IDEMPOTENT AND RACE-SAFE. The unique (user, type, id) key does the work,
 *     so two simultaneous taps cannot create two rows or double-count.
 *
 *  2. REMOVAL IS A STAMP, NOT A DELETE. `removed_at` is what makes favourite
 *     history answerable, and re-saving clears the stamp on the ORIGINAL row —
 *     so the counter only ever moves when the saved/not-saved state actually
 *     flips.
 */
class FavoriteService
{
    public function __construct(private readonly CounterService $counters) {}

    /** @return bool True when this call changed the state. */
    public function add(User $user, Model $target): bool
    {
        return DB::transaction(function () use ($user, $target): bool {
            $favorite = Favorite::query()
                ->where('user_id', $user->getKey())
                ->where('favoritable_type', $target->getMorphClass())
                ->where('favoritable_id', $target->getKey())
                // Locked so two simultaneous taps cannot both read "not saved"
                // and both increment the counter.
                ->lockForUpdate()
                ->first();

            if ($favorite !== null && $favorite->removed_at === null) {
                return false; // already saved
            }

            if ($favorite !== null) {
                $favorite->forceFill(['removed_at' => null, 'created_at' => now()])->save();
            } else {
                Favorite::create([
                    'user_id' => $user->getKey(),
                    'favoritable_type' => $target->getMorphClass(),
                    'favoritable_id' => $target->getKey(),
                    'created_at' => now(),
                ]);
            }

            $this->countFor($target, +1);

            return true;
        });
    }

    /** @return bool True when this call changed the state. */
    public function remove(User $user, Model $target): bool
    {
        return DB::transaction(function () use ($user, $target): bool {
            $updated = Favorite::query()
                ->where('user_id', $user->getKey())
                ->where('favoritable_type', $target->getMorphClass())
                ->where('favoritable_id', $target->getKey())
                ->whereNull('removed_at')
                ->update(['removed_at' => now()]);

            if ($updated > 0) {
                // The flush clamps at zero, so a double-remove cannot drive the
                // UNSIGNED column negative.
                $this->countFor($target, -1);
            }

            return $updated > 0;
        });
    }

    public function toggle(User $user, Model $target): bool
    {
        return $this->isFavorited($user, $target)
            ? ! $this->remove($user, $target)
            : $this->add($user, $target);
    }

    public function isFavorited(User $user, Model $target): bool
    {
        return Favorite::query()
            ->where('user_id', $user->getKey())
            ->where('favoritable_type', $target->getMorphClass())
            ->where('favoritable_id', $target->getKey())
            ->whereNull('removed_at')
            ->exists();
    }

    /**
     * Only listings carry a denormalised counter.
     *
     * Businesses have no `favorite_count` column, and inventing one here would
     * write to a column no read path knows about.
     */
    private function countFor(Model $target, int $direction): void
    {
        if (! $target instanceof Listing) {
            return;
        }

        $direction > 0
            ? $this->counters->increment('favorite_count', (int) $target->getKey())
            : $this->counters->decrement('favorite_count', (int) $target->getKey());
    }

    /**
     * The morph classes a customer may save, keyed by the API's own word.
     *
     * @return array<string, class-string<Model>>
     */
    public static function targetClasses(): array
    {
        return ['listing' => Listing::class, 'business' => SellerProfile::class];
    }
}
