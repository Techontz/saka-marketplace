<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resizes an uploaded image into the variants the frontend actually renders.
 *
 * Runs on the `media` queue so a slow resize never delays an auth email — the
 * whole point of separating queues by priority.
 *
 * Sizes are deliberate: the listing card renders a 205px-tall image, so serving
 * the 1200px original there is the exact waste identified in the Milestone 1
 * audit. EXIF is stripped in the process, which also removes the GPS tags that
 * would otherwise leak a seller's home address.
 */
class GenerateImageVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $mediaId)
    {
        // Image resizing is slow; isolating it keeps a large upload from
        // delaying an auth email. See the note in RecordListingView about why
        // this is onQueue() rather than a `queue()` method.
        $this->onQueue('media');
    }

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if ($media === null) {
            return; // deleted before processing — nothing to do
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            $media->forceFill(['processing_status' => 'failed'])->save();

            return;
        }

        try {
            // Intervention Image v4 API: decodeBinary(), not the v2 read().
            $manager = new ImageManager(new Driver);
            $source = $manager->decodeBinary($disk->get($media->path));

            $variants = [];

            foreach ((array) config('saka.media.variants') as $name => $size) {
                $image = clone $source;

                // `scaleDown` never enlarges: upscaling a small original wastes
                // bytes and looks worse than serving it as-is.
                $image->scaleDown(width: $size['width'], height: $size['height']);

                // WebP for every variant: markedly smaller than JPEG at the
                // same perceived quality, and universally supported now.
                $encoded = $image->encode(new WebpEncoder(quality: 82));
                $variantPath = $this->variantPath($media->path, $name);

                $disk->put($variantPath, (string) $encoded);

                $variants[$name] = [
                    'path' => $variantPath,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'bytes' => strlen((string) $encoded),
                ];
            }

            $media->forceFill([
                'variants' => $variants,
                'width' => $source->width(),
                'height' => $source->height(),
                'processing_status' => 'complete',
            ])->save();
        } catch (Throwable $e) {
            // The original is still usable, so a variant failure must not lose
            // the upload — mark it and let the client fall back to the original.
            $media->forceFill(['processing_status' => 'failed'])->save();

            Log::error('media.variants_failed', [
                'media_id' => $media->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function variantPath(string $original, string $variant): string
    {
        $directory = dirname($original);
        $name = pathinfo($original, PATHINFO_FILENAME);

        return "{$directory}/{$name}_{$variant}.webp";
    }
}
