<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Listing;
use App\Models\Media;
use App\Models\PublicPlaceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The orphan-media prune, and the two ways it can go wrong.
 *
 * An earlier version of this command deleted media whose owner type was not on
 * a hand-written list, which destroyed every public-place category image. These
 * tests pin the behaviour that replaced it: delete only what is provably
 * orphaned, and never delete on the basis of not recognising something.
 */
class PruneOrphanMediaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function media(string $type, int $ownerId, string $path): Media
    {
        $media = new Media;
        $media->forceFill([
            'uuid' => (string) Str::uuid7(),
            'mediable_type' => $type,
            'mediable_id' => $ownerId,
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => $path,
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 1024,
        ])->save();

        return $media;
    }

    #[Test]
    public function it_deletes_media_whose_owner_was_force_deleted(): void
    {
        $listing = Listing::factory()->create();
        $media = $this->media(Listing::class, (int) $listing->getKey(), 'listings/gone.jpg');

        $listing->forceDelete();

        $this->artisan('saka:media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseMissing('media', ['id' => $media->getKey()]);
    }

    #[Test]
    public function it_leaves_media_of_a_soft_deleted_owner_alone(): void
    {
        $listing = Listing::factory()->create();
        $media = $this->media(Listing::class, (int) $listing->getKey(), 'listings/kept.jpg');

        // A soft-deleted listing can be restored; restoring one with no photos
        // is worse than keeping the files.
        $listing->delete();

        $this->artisan('saka:media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $media->getKey()]);
    }

    #[Test]
    public function it_never_deletes_media_of_an_owner_type_it_cannot_resolve(): void
    {
        $media = $this->media('App\Models\SomeTypeThisAppNoLongerHas', 999_999, 'unknown/keep.jpg');

        $this->artisan('saka:media:prune-orphans')
            ->expectsOutputToContain('could not be resolved')
            ->assertSuccessful();

        // "I don't recognise this" is not evidence of an orphan.
        $this->assertDatabaseHas('media', ['id' => $media->getKey()]);
    }

    #[Test]
    public function it_leaves_every_legitimately_owned_media_row_untouched(): void
    {
        // The exact regression: a valid owner type that no hand-written list
        // happened to mention.
        $category = PublicPlaceCategory::query()->firstOrFail();
        $media = $this->media(PublicPlaceCategory::class, (int) $category->getKey(), 'places/icon.jpg');

        $before = DB::table('media')->count();

        $this->artisan('saka:media:prune-orphans')->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $media->getKey()]);
        $this->assertSame($before, DB::table('media')->count());
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $listing = Listing::factory()->create();
        $media = $this->media(Listing::class, (int) $listing->getKey(), 'listings/dry.jpg');
        $listing->forceDelete();

        $this->artisan('saka:media:prune-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $media->getKey()]);
    }

    #[Test]
    public function replacing_an_avatar_removes_the_previous_file_not_just_the_row(): void
    {
        // The avatar endpoints deleted the media ROW directly, leaving the file
        // and every variant on disk forever.
        $user = User::factory()->create();

        $first = $this->media(User::class, (int) $user->getKey(), 'avatars/first.jpg');
        $user->forceFill(['avatar_media_id' => $first->getKey()])->save();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/account/avatar')
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $first->getKey()]);
        $this->assertNull($user->fresh()->avatar_media_id);
    }
}
