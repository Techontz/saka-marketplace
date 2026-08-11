<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\HomepageBanner;
use App\Models\HomepageSection;
use App\Services\Audit\AuditLogger;
use App\Support\Cache\CacheKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Homepage banners and section configuration.
 *
 * SCOPE, stated plainly: this is not a page builder. Sections cannot be created
 * or deleted through the API, only retitled, reordered, resized and hidden —
 * because each one is bound to a React component by its `key`, and a section
 * with no component renders nothing while looking like a bug. Banners ARE fully
 * CRUD, since a banner is pure content.
 *
 * That boundary keeps a CMS from being able to break a design four milestones
 * were spent holding still.
 */
class HomepageController extends Controller
{
    /** Where a banner may render. Each value has a slot in the frontend. */
    private const PLACEMENTS = ['hero', 'mid', 'footer', 'listings_top', 'sidebar'];

    public function __construct(private readonly AuditLogger $audit) {}

    // --------------------------------------------------------------- banners

    public function indexBanners(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $banners = HomepageBanner::query()
            ->with('image')
            ->orderBy('placement')
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $banners->map(fn (HomepageBanner $b) => $this->bannerPayload($b))->all(),
            'meta' => ['placements' => self::PLACEMENTS],
        ]);
    }

    public function storeBanner(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $data = $this->validateBanner($request, creating: true);

        $banner = HomepageBanner::create($data);

        $this->audit->record('banner.created', $request->user(), $banner, [], $data);
        CacheKeys::flushContent();

        return response()->json(
            ['data' => $this->bannerPayload($banner->fresh(['image']))],
            Response::HTTP_CREATED,
        );
    }

    public function updateBanner(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeCms($request);

        $banner = HomepageBanner::where('uuid', $uuid)->firstOrFail();
        $banner->fill($this->validateBanner($request, creating: false))->save();

        $this->audit->recordChange('banner.updated', $request->user(), $banner);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->bannerPayload($banner->fresh(['image']))]);
    }

    public function destroyBanner(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeCms($request);

        $banner = HomepageBanner::where('uuid', $uuid)->firstOrFail();

        $this->audit->record('banner.deleted', $request->user(), $banner, [
            'title' => $banner->title,
            'placement' => $banner->placement,
        ]);

        $banner->delete();
        CacheKeys::flushContent();

        return response()->json(['data' => ['message' => 'Banner deleted.']]);
    }

    /**
     * Reorder a placement in one call.
     *
     * Sending each banner's new position as a separate PATCH leaves the list in
     * a visibly wrong order between requests if one fails. This applies the
     * whole arrangement in a transaction: it either takes or it does not.
     */
    public function reorderBanners(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:100'],
            'order.*' => ['string', 'max:64'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['order'] as $position => $uuid) {
                HomepageBanner::where('uuid', $uuid)->update(['position' => $position * 10]);
            }
        });

        $this->audit->record('banner.reordered', $request->user(), null, [], ['order' => $validated['order']]);
        CacheKeys::flushContent();

        return response()->json(['data' => ['message' => 'Order saved.']]);
    }

    // -------------------------------------------------------------- sections

    public function indexSections(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $sections = HomepageSection::query()->orderBy('position')->get();

        return response()->json([
            'data' => $sections->map(fn (HomepageSection $s) => $this->sectionPayload($s))->all(),
        ]);
    }

    public function updateSection(Request $request, string $key): JsonResponse
    {
        $this->authorizeCms($request);

        $section = HomepageSection::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:191'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'item_limit' => ['nullable', 'integer', 'min:1', 'max:48'],
            // `key` is not accepted at all — it is the join to a component.
            'key' => ['prohibited'],
        ]);

        $section->fill($data)->save();

        $this->audit->recordChange('section.updated', $request->user(), $section);
        CacheKeys::flushContent();

        return response()->json(['data' => $this->sectionPayload($section->fresh())]);
    }

    public function reorderSections(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1', 'max:50'],
            'order.*' => ['string', 'max:60'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['order'] as $position => $key) {
                HomepageSection::where('key', $key)->update(['position' => $position * 10]);
            }
        });

        $this->audit->record('section.reordered', $request->user(), null, [], ['order' => $validated['order']]);
        CacheKeys::flushContent();

        return response()->json(['data' => ['message' => 'Order saved.']]);
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function validateBanner(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'min:2', 'max:191'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            // Rendered as an href: constraining the scheme is what stops
            // `javascript:` being stored and executed for every visitor.
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'link_label' => ['nullable', 'string', 'max:60'],
            'image_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'placement' => [$required, Rule::in(self::PLACEMENTS)],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            // A window that ends before it starts would silently never show.
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
    }

    /** @return array<string, mixed> */
    private function bannerPayload(HomepageBanner $banner): array
    {
        return [
            'uuid' => $banner->uuid,
            'title' => $banner->title,
            'subtitle' => $banner->subtitle,
            'link_url' => $banner->link_url,
            'link_label' => $banner->link_label,
            'placement' => $banner->placement,
            'position' => $banner->position,
            'is_active' => $banner->is_active,
            'starts_at' => $banner->starts_at?->toAtomString(),
            'ends_at' => $banner->ends_at?->toAtomString(),
            'image_url' => $banner->image?->url(),
            'image_media_id' => $banner->image_media_id,
            // Distinct from is_active: a banner can be active but outside its
            // window, and the list has to be able to say so.
            'is_live' => $banner->is_active
                && ($banner->starts_at === null || $banner->starts_at->isPast())
                && ($banner->ends_at === null || $banner->ends_at->isFuture()),
        ];
    }

    /** @return array<string, mixed> */
    private function sectionPayload(HomepageSection $section): array
    {
        return [
            'key' => $section->key,
            'title' => $section->title,
            'subtitle' => $section->subtitle,
            'position' => $section->position,
            'is_active' => $section->is_active,
            'item_limit' => $section->item_limit,
        ];
    }

    private function authorizeCms(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::CmsManage)) {
            throw ApiException::forbidden();
        }
    }
}
