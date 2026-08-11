<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InquiryResource;
use App\Models\Inquiry;
use App\Services\Engagement\InquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerInquiryController extends Controller
{
    public function __construct(private readonly InquiryService $inquiries) {}

    public function show(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->authorize('view', $inquiry);

        // Opening it marks it read — that is what the seller means by opening.
        $inquiry = $this->inquiries->markRead($inquiry);

        return response()->json([
            'data' => new InquiryResource($inquiry->load('listing:id,uuid,slug,title')),
        ]);
    }

    public function reply(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->authorize('respond', $inquiry);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $inquiry = $this->inquiries->reply($inquiry, $validated['body']);

        return response()->json(['data' => new InquiryResource($inquiry)]);
    }
}
