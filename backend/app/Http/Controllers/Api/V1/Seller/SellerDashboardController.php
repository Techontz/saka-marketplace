<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Seller;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InquiryResource;
use App\Http\Resources\V1\SellerProfileResource;
use App\Models\Inquiry;
use App\Services\Seller\SellerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerDashboardController extends Controller
{
    public function __construct(private readonly SellerDashboardService $dashboard) {}

    /** Everything the dashboard needs in one round trip. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->forSeller($request->user())]);
    }

    public function profile(Request $request): JsonResponse
    {
        $profile = $request->user()->sellerProfile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new SellerProfileResource($profile->load('logo'))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'business_reg_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:40'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website' => ['sometimes', 'nullable', 'url', 'max:191'],
        ]);

        $profile = $request->user()->sellerProfile;

        if ($profile === null) {
            // Was a bare 404 with no envelope — every failure must use the
            // same shape or clients need two error paths.
            throw ApiException::notFound('You do not have a seller profile yet.');
        }

        $profile->fill($validated)->save();
        $this->dashboard->forget($request->user());

        return response()->json(['data' => new SellerProfileResource($profile->fresh()->load('logo'))]);
    }

    public function inquiries(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:new,read,replied,spam,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Inquiry::query()
            ->forSeller($request->user())
            ->with(['listing:id,uuid,slug,title'])
            ->latest();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return InquiryResource::collection(
            $query->paginate($validated['per_page'] ?? 20)->withQueryString(),
        );
    }
}
