<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InquiryResource;
use App\Models\Inquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The customer's side of their own inquiries.
 *
 * `POST /inquiries` existed from Milestone 7, so a customer could send a
 * message and then never see it again — no history, no way to tell whether it
 * had been read, no way to read the reply. This is the other half.
 *
 * Scoped by `sender_user_id`, which means a message sent while signed OUT is
 * not in this list. That is deliberate: matching on email address would let
 * anyone claim another person's inquiry history by registering their address.
 */
class AccountInquiryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'read', 'replied', 'closed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $inquiries = Inquiry::query()
            ->where('sender_user_id', $request->user()->getKey())
            ->when($validated['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->with(['listing:id,uuid,slug,title,user_id', 'listing.primaryMedia'])
            ->latest('created_at')
            ->paginate(min((int) ($validated['per_page'] ?? 20), 100))
            ->withQueryString();

        return InquiryResource::collection($inquiries);
    }

    /**
     * One inquiry, with its timeline.
     *
     * The timeline is DERIVED from the row's own timestamps rather than stored
     * as events. There is no message-thread table — an inquiry is one message
     * and at most one reply — so building a fake event log would imply a
     * conversation that cannot happen. What it shows is exactly what is known:
     * when it was sent, when the business read it, and when they answered.
     */
    public function show(Request $request, Inquiry $inquiry): JsonResponse
    {
        if ($inquiry->sender_user_id !== $request->user()->getKey()) {
            // 404 not 403 — whether an inquiry exists is not this user's
            // business unless they sent it.
            throw ApiException::notFound('Inquiry not found.');
        }

        $inquiry->load(['listing:id,uuid,slug,title,user_id', 'listing.primaryMedia', 'seller.sellerProfile']);

        $timeline = [
            [
                'event' => 'sent',
                'label' => 'You sent this message',
                'at' => $inquiry->created_at?->toAtomString(),
            ],
        ];

        if ($inquiry->read_at !== null) {
            $timeline[] = [
                'event' => 'read',
                'label' => 'Seen by the business',
                'at' => $inquiry->read_at->toAtomString(),
            ];
        }

        if ($inquiry->replied_at !== null) {
            $timeline[] = [
                'event' => 'replied',
                'label' => 'The business replied',
                'at' => $inquiry->replied_at->toAtomString(),
            ];
        }

        if ($inquiry->status->value === 'closed') {
            $timeline[] = [
                'event' => 'closed',
                'label' => 'Marked resolved by the business',
                'at' => $inquiry->updated_at?->toAtomString(),
            ];
        }

        return response()->json([
            'data' => new InquiryResource($inquiry),
            'meta' => [
                'timeline' => $timeline,
                'business' => $inquiry->seller?->sellerProfile === null ? null : [
                    'slug' => $inquiry->seller->sellerProfile->slug,
                    'display_name' => $inquiry->seller->sellerProfile->display_name,
                ],
            ],
        ]);
    }
}
