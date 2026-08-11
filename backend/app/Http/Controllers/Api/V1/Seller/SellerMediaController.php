<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Domain\Media\Enums\MediaCollection;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MediaResource;
use App\Models\Listing;
use App\Models\Media;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SellerMediaController extends Controller
{
    public function __construct(private readonly MediaUploadService $media) {}

    public function store(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('manageMedia', $listing);

        $request->validate([
            // Deep validation (magic-byte MIME, dimensions, per-listing cap)
            // lives in the service so console and queue paths get it too.
            'image' => ['required', 'file', 'max:'.((int) config('saka.media.max_image_mb') * 1024)],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $media = $this->media->upload(
            $request->file('image'),
            $listing,
            $request->user(),
            MediaCollection::Gallery,
        );

        if ($request->filled('alt_text')) {
            $media->forceFill(['alt_text' => $request->string('alt_text')->toString()])->save();
        }

        return response()->json(['data' => new MediaResource($media->fresh())], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Listing $listing, Media $media): JsonResponse
    {
        $this->authorize('manageMedia', $listing);
        $this->assertBelongsTo($media, $listing);

        $this->media->delete($media);

        return response()->json(['data' => ['message' => 'Image removed.']]);
    }

    public function makePrimary(Request $request, Listing $listing, Media $media): JsonResponse
    {
        $this->authorize('manageMedia', $listing);
        $this->assertBelongsTo($media, $listing);

        return response()->json(['data' => new MediaResource($this->media->makePrimary($media))]);
    }

    public function replace(Request $request, Listing $listing, Media $media): JsonResponse
    {
        $this->authorize('manageMedia', $listing);
        $this->assertBelongsTo($media, $listing);

        $request->validate([
            'image' => ['required', 'file', 'max:'.((int) config('saka.media.max_image_mb') * 1024)],
        ]);

        $replacement = $this->media->replace($media, $request->file('image'), $request->user());

        return response()->json(['data' => new MediaResource($replacement)]);
    }

    public function reorder(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('manageMedia', $listing);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string', 'uuid'],
        ]);

        $this->media->reorder($listing, MediaCollection::Gallery, $validated['order']);

        return response()->json([
            'data' => MediaResource::collection($listing->gallery()->get()),
        ]);
    }

    /**
     * Route-model binding resolves media globally, so ownership must be
     * re-checked — otherwise a seller could pass another listing's media uuid.
     */
    private function assertBelongsTo(Media $media, Listing $listing): void
    {
        if ($media->mediable_type !== $listing->getMorphClass() || $media->mediable_id !== $listing->getKey()) {
            throw ApiException::notFound('Image not found on this listing.');
        }
    }
}
