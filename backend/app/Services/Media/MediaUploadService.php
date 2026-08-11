<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Jobs\GenerateImageVariants;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The ONLY place a file enters the system.
 *
 * No controller touches Storage:: directly, and every row records the disk it
 * was written to — that is what makes moving to S3 a backfill plus an env
 * change rather than a schema migration.
 */
class MediaUploadService
{
    public function upload(
        UploadedFile $file,
        Model $owner,
        User $uploader,
        MediaCollection $collection = MediaCollection::Gallery,
    ): Media {
        $this->assertAcceptable($file, $owner, $collection);

        $disk = $collection->isPrivate() ? 'local' : (string) config('saka.media.disk');
        $checksum = hash_file('sha256', $file->getRealPath());

        $extension = $this->safeExtension($file);
        $directory = $this->directoryFor($owner, $collection);
        $filename = (string) Str::ulid().'.'.$extension;

        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if ($path === false) {
            throw ApiException::make(ErrorCode::ServerError, 'The file could not be stored.');
        }

        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = DB::transaction(function () use (
            $owner, $collection, $disk, $path, $file, $extension,
            $checksum, $uploader, $dimensions
        ): Media {
            $nextPosition = (int) Media::query()
                ->where('mediable_type', $owner->getMorphClass())
                ->where('mediable_id', $owner->getKey())
                ->where('collection', $collection->value)
                ->max('position');

            $isFirst = ! Media::query()
                ->where('mediable_type', $owner->getMorphClass())
                ->where('mediable_id', $owner->getKey())
                ->where('collection', $collection->value)
                ->exists();

            return Media::create([
                'mediable_type' => $owner->getMorphClass(),
                'mediable_id' => $owner->getKey(),
                'collection' => $collection->value,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => (string) $file->getMimeType(),
                'extension' => $extension,
                'size_bytes' => $file->getSize(),
                'width' => $dimensions[0] ?? null,
                'height' => $dimensions[1] ?? null,
                'position' => $nextPosition + 10,
                // The first image of a collection becomes the primary one.
                'is_primary' => $isFirst,
                'processing_status' => 'pending',
                'checksum' => $checksum,
                'uploaded_by' => $uploader->getKey(),
            ]);
        });

        // Variants are generated off the request so a slow resize never blocks
        // the upload response.
        GenerateImageVariants::dispatch($media->id);

        return $media;
    }

    public function delete(Media $media): void
    {
        DB::transaction(function () use ($media): void {
            $wasPrimary = $media->is_primary;
            $ownerType = $media->mediable_type;
            $ownerId = $media->mediable_id;
            $collection = $media->collection->value;

            $this->deleteFiles($media);
            $media->delete();

            // Never leave a gallery without a primary image.
            if ($wasPrimary) {
                Media::query()
                    ->where('mediable_type', $ownerType)
                    ->where('mediable_id', $ownerId)
                    ->where('collection', $collection)
                    ->orderBy('position')
                    ->first()
                    ?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    public function makePrimary(Media $media): Media
    {
        DB::transaction(function () use ($media): void {
            Media::query()
                ->where('mediable_type', $media->mediable_type)
                ->where('mediable_id', $media->mediable_id)
                ->where('collection', $media->collection->value)
                ->update(['is_primary' => false]);

            $media->forceFill(['is_primary' => true])->save();
        });

        return $media->fresh();
    }

    /**
     * Reorder a collection.
     *
     * @param  array<int, string>  $uuidsInOrder
     */
    public function reorder(Model $owner, MediaCollection $collection, array $uuidsInOrder): void
    {
        DB::transaction(function () use ($owner, $collection, $uuidsInOrder): void {
            foreach (array_values($uuidsInOrder) as $index => $uuid) {
                Media::query()
                    ->where('mediable_type', $owner->getMorphClass())
                    ->where('mediable_id', $owner->getKey())
                    ->where('collection', $collection->value)
                    ->where('uuid', $uuid)
                    ->update(['position' => ($index + 1) * 10]);
            }
        });
    }

    /** Replace the binary behind an existing media row, keeping its position. */
    public function replace(Media $media, UploadedFile $file, User $uploader): Media
    {
        $owner = $media->mediable;
        $new = $this->upload($file, $owner, $uploader, $media->collection);

        $new->forceFill([
            'position' => $media->position,
            'is_primary' => $media->is_primary,
        ])->save();

        $this->deleteFiles($media);
        $media->delete();

        return $new->fresh();
    }

    private function deleteFiles(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->delete($media->path);

        foreach ((array) $media->variants as $variant) {
            if (isset($variant['path'])) {
                $disk->delete($variant['path']);
            }
        }
    }

    private function assertAcceptable(UploadedFile $file, Model $owner, MediaCollection $collection): void
    {
        if (! $file->isValid()) {
            throw ApiException::make(ErrorCode::ValidationFailed, 'The upload failed.');
        }

        $maxBytes = (int) config('saka.media.max_image_mb') * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'That image is larger than '.config('saka.media.max_image_mb').'MB.',
            );
        }

        // MIME is derived from the file's magic bytes, never the client-supplied
        // name or Content-Type. SVG is excluded entirely: it can carry script.
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, (array) config('saka.media.accepted_mimes'), true)) {
            throw ApiException::make(
                ErrorCode::ValidationFailed,
                'Only JPEG, PNG and WebP images are accepted.',
                ['detected' => $mime],
            );
        }

        if ($collection === MediaCollection::Gallery) {
            $max = (int) config('saka.media.max_images_per_listing');

            $current = Media::query()
                ->where('mediable_type', $owner->getMorphClass())
                ->where('mediable_id', $owner->getKey())
                ->where('collection', $collection->value)
                ->count();

            if ($current >= $max) {
                throw ApiException::make(
                    ErrorCode::Conflict,
                    "A listing may have at most {$max} images.",
                );
            }
        }
    }

    private function safeExtension(UploadedFile $file): string
    {
        return match ((string) $file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    private function directoryFor(Model $owner, MediaCollection $collection): string
    {
        $type = Str::of($owner->getMorphClass())->afterLast('\\')->snake()->plural()->toString();

        return "{$type}/{$owner->getKey()}/{$collection->value}";
    }
}
