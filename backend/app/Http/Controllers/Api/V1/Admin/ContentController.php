<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * CMS and platform settings.
 *
 * Pages are explicitly publish/unpublish rather than a boolean, so Terms and
 * Privacy can exist as drafts until real legal copy lands — which is exactly
 * the state they are seeded in.
 */
class ContentController extends Controller
{
    // ------------------------------------------------------------------ FAQs

    public function indexFaqs(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        return response()->json([
            'data' => Faq::orderBy('group')->orderBy('position')->get()
                ->map(fn (Faq $f) => $this->faqPayload($f))->all(),
        ]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $faq = Faq::create($this->validateFaq($request));

        return response()->json(['data' => $this->faqPayload($faq)], Response::HTTP_CREATED);
    }

    public function updateFaq(Request $request, Faq $faq): JsonResponse
    {
        $this->authorizeCms($request);

        $faq->fill($this->validateFaq($request, partial: true))->save();

        return response()->json(['data' => $this->faqPayload($faq->fresh())]);
    }

    public function destroyFaq(Request $request, Faq $faq): JsonResponse
    {
        $this->authorizeCms($request);
        $faq->delete();

        return response()->json(['data' => ['message' => 'FAQ deleted.']]);
    }

    // ----------------------------------------------------------------- Pages

    public function indexPages(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        return response()->json([
            'data' => Page::orderBy('slug')->get()->map(fn (Page $p) => $this->pagePayload($p))->all(),
        ]);
    }

    public function storePage(Request $request): JsonResponse
    {
        $this->authorizeCms($request);

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'unique:pages,slug', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['required', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:200000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ]);

        $data['slug'] = Str::slug($data['slug']);

        return response()->json(['data' => $this->pagePayload(Page::create($data))], Response::HTTP_CREATED);
    }

    public function updatePage(Request $request, Page $page): JsonResponse
    {
        $this->authorizeCms($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:191'],
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $page->fill($data)->save();

        return response()->json(['data' => $this->pagePayload($page->fresh())]);
    }

    /** Publishing is explicit so drafts cannot go live by accident. */
    public function publishPage(Request $request, Page $page): JsonResponse
    {
        $this->authorizeCms($request);

        $publish = $request->boolean('published', true);

        if ($publish && blank($page->body)) {
            throw ApiException::conflict(
                ErrorCode::Conflict,
                'A page cannot be published without a body.',
            );
        }

        $page->forceFill(['published_at' => $publish ? now() : null])->save();

        return response()->json(['data' => $this->pagePayload($page->fresh())]);
    }

    // -------------------------------------------------------------- Settings

    public function indexSettings(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);

        return response()->json([
            'data' => Setting::orderBy('group')->orderBy('key')->get()->map(fn (Setting $s) => [
                'key' => $s->key,
                'value' => $s->value,
                'group' => $s->group,
                'description' => $s->description,
                'is_public' => (bool) $s->is_public,
            ])->all(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);

        $validated = $request->validate([
            'settings' => ['required', 'array', 'min:1', 'max:100'],
            'settings.*.key' => ['required', 'string', 'max:100', 'exists:settings,key'],
            'settings.*.value' => ['present'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['settings'] as $entry) {
                // Only the VALUE is writable. `is_public` decides whether a
                // setting is world-readable, so exposing it to this endpoint
                // would let an admin leak a private key by accident.
                Setting::where('key', $entry['key'])->update([
                    'value' => json_encode($entry['value']),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->indexSettings($request);
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function validateFaq(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'question' => [$rule, 'string', 'min:5', 'max:500'],
            'answer' => [$rule, 'string', 'min:5', 'max:5000'],
            'group' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function faqPayload(Faq $faq): array
    {
        return [
            'id' => $faq->id,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'group' => $faq->group,
            'position' => $faq->position,
            'is_active' => (bool) $faq->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function pagePayload(Page $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'body' => $page->body,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'is_published' => $page->published_at !== null,
            'published_at' => $page->published_at?->toAtomString(),
        ];
    }

    private function authorizeCms(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::CmsManage)) {
            throw ApiException::forbidden();
        }
    }

    private function authorizeSettings(Request $request): void
    {
        if (! $request->user()?->hasPermission(Permission::SettingsManage)) {
            throw ApiException::forbidden();
        }
    }
}
