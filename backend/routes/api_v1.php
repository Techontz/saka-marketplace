<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\AccountInquiryController;
use App\Http\Controllers\Api\V1\Account\ActivityController;
use App\Http\Controllers\Api\V1\Account\BookingController as AccountBookingController;
use App\Http\Controllers\Api\V1\Account\FavoriteController;
use App\Http\Controllers\Api\V1\Account\NotificationController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\ReviewController;
use App\Http\Controllers\Api\V1\Admin\AdminListingController;
use App\Http\Controllers\Api\V1\Admin\AdminPlaceController;
use App\Http\Controllers\Api\V1\Admin\AdvertisingController;
use App\Http\Controllers\Api\V1\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\V1\Admin\ContentController as AdminContentController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\HomepageController;
use App\Http\Controllers\Api\V1\Admin\ModerationController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SystemController;
use App\Http\Controllers\Api\V1\Admin\TaxonomyController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VerificationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\PhoneVerificationController;
use App\Http\Controllers\Api\V1\Public\AdController;
use App\Http\Controllers\Api\V1\Public\BookingController as PublicBookingController;
use App\Http\Controllers\Api\V1\Public\BusinessController;
use App\Http\Controllers\Api\V1\Public\CatalogController;
use App\Http\Controllers\Api\V1\Public\ContentController;
use App\Http\Controllers\Api\V1\Public\InquiryController as PublicInquiryController;
use App\Http\Controllers\Api\V1\Public\ListingController as PublicListingController;
use App\Http\Controllers\Api\V1\Public\ListingReportController;
use App\Http\Controllers\Api\V1\Public\LocationSearchController;
use App\Http\Controllers\Api\V1\Public\SearchController;
use App\Http\Controllers\Api\V1\Public\SpecialistController as PublicSpecialistController;
use App\Http\Controllers\Api\V1\Seller\SellerBoundaryController;
use App\Http\Controllers\Api\V1\Seller\SellerDashboardController;
use App\Http\Controllers\Api\V1\Seller\SellerInquiryController;
use App\Http\Controllers\Api\V1\Seller\SellerListingController;
use App\Http\Controllers\Api\V1\Seller\SellerMediaController;
use App\Http\Controllers\Api\V1\Seller\SpecialistController as SellerSpecialistController;
use App\Http\Controllers\Api\V1\Seller\VendorEngagementController;
use App\Http\Controllers\Api\V1\Seller\VendorProfileController;
use App\Http\Controllers\Api\V1\Seller\VendorPromotionController;
use App\Http\Controllers\Api\V1\Seller\VendorVerificationController;
use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Api\V1\System\MetricsController;
use App\Http\Middleware\RequireMetricsToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Four surfaces, each with its own middleware stack and rate limits:
|
|   Public   — unauthenticated reads and the inquiry write
|   Account  — the signed-in user acting on themselves
|   Seller   — managing own listings (requires a verified phone to publish)
|   Admin    — moderation and platform administration
|
| Keeping them separate is what stops admin capability leaking onto the public
| surface as the app grows.
|
| Milestone 6 implements Public health + Auth + Account.
| Listings, catalog, seller and admin surfaces land in Milestone 7+.
|
*/

// ---------------------------------------------------------------- public

Route::middleware('throttle:api-public')->group(function (): void {
    // Kept for backwards compatibility with anything already probing it.
    Route::get('/health', [HealthController::class, 'live'])->name('health');

    // LIVENESS: is the process up? Touches no dependency, so a database blip
    // cannot make the orchestrator restart every healthy container.
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');

    // READINESS: can this instance serve traffic? Checks its dependencies.
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
});

// Prometheus scrape target — shared-secret gated, never public.
Route::get('/metrics', MetricsController::class)
    ->middleware(RequireMetricsToken::class)
    ->name('metrics');

// ------------------------------------------------------------------ auth

Route::prefix('auth')->as('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register')->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')->name('login');

    Route::post('/oauth/google', GoogleAuthController::class)
        ->middleware('throttle:auth-oauth')->name('oauth.google');

    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:password-reset')->name('password.forgot');

    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')->name('password.reset');

    // Authenticated session management.
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::delete('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::delete('/logout-all', [AuthController::class, 'logoutAll'])->name('logout.all');

        // Phone verification — the gate on publishing.
        Route::post('/phone/request-otp', [PhoneVerificationController::class, 'request'])
            ->middleware('throttle:otp-request')->name('phone.request');

        Route::post('/phone/verify-otp', [PhoneVerificationController::class, 'verify'])
            ->middleware('throttle:otp-verify')->name('phone.verify');
    });
});

// --------------------------------------------------------------- account

Route::prefix('account')
    ->as('account.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api-authenticated'])
    ->group(function (): void {
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar'])
            ->middleware('throttle:media-upload')->name('avatar.store');
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar'])->name('avatar.destroy');
        // Irreversible from the customer's side, so the password is re-checked
        // in the controller even though the caller is authenticated.
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
    });

// ---------------------------------------------------- marketplace (public)

Route::middleware('throttle:api-public')->group(function (): void {
    // Listings — browse, search, filter, paginate. Open to guests.
    Route::get('/listings', [PublicListingController::class, 'index'])
        ->middleware('throttle:search')->name('listings.index');
    Route::get('/listings/trending', [PublicListingController::class, 'trending'])->name('listings.trending');
    Route::get('/listings/featured', [PublicListingController::class, 'featured'])->name('listings.featured');
    Route::get('/listings/recommended', [PublicListingController::class, 'recommended'])->name('listings.recommended');
    Route::get('/listings/{slug}', [PublicListingController::class, 'show'])->name('listings.show');
    Route::get('/listings/{slug}/similar', [PublicListingController::class, 'similar'])->name('listings.similar');

    // Abuse reports. Open to guests — see ListingReportController — and given
    // their own throttle rather than sharing the public read budget.
    Route::get('/listing-report-reasons', [ListingReportController::class, 'reasons'])->name('listings.report.reasons');
    Route::post('/listings/{slug}/report', [ListingReportController::class, 'store'])
        ->middleware('throttle:listing-report')->name('listings.report');
    Route::get('/listings/{slug}/reviews', [ReviewController::class, 'forListing'])->name('listings.reviews');

    // Catalog / reference data.
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('/categories/{slug}', [CatalogController::class, 'category'])->name('categories.show');
    Route::get('/categories/{slug}/attributes', [CatalogController::class, 'categoryAttributes'])->name('categories.attributes');
    /*
     * Location autocomplete: one input that resolves regions, districts,
     * wards, landmarks and businesses to a filter parameter and a coordinate.
     * Throttled with search rather than the generic public budget, because an
     * autocomplete fires per keystroke.
     */
    Route::get('/locations/search', LocationSearchController::class)
        ->middleware('throttle:search')->name('locations.search');

    Route::get('/locations/regions', [CatalogController::class, 'regions'])->name('locations.regions');
    Route::get('/locations/regions/{region}/districts', [CatalogController::class, 'districts'])->name('locations.districts');
    Route::get('/locations/districts/{district}/wards', [CatalogController::class, 'wards'])->name('locations.wards');
    Route::get('/amenities', [CatalogController::class, 'amenities'])->name('amenities.index');
    Route::get('/facilities', [CatalogController::class, 'facilities'])->name('facilities.index');

    // Businesses — the public face of a seller profile. Milestone 13.
    Route::get('/businesses', [BusinessController::class, 'index'])->name('businesses.index');
    Route::get('/businesses/{slug}', [BusinessController::class, 'show'])->name('businesses.show');
    Route::get('/businesses/{slug}/listings', [BusinessController::class, 'listings'])->name('businesses.listings');
    Route::get('/businesses/{slug}/reviews', [BusinessController::class, 'reviews'])->name('businesses.reviews');
    Route::get('/businesses/{slug}/similar', [BusinessController::class, 'similar'])->name('businesses.similar');

    // The search box itself.
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])
        ->middleware('throttle:search')->name('search.suggestions');
    Route::get('/search/popular', [SearchController::class, 'popular'])->name('search.popular');

    // Directory + editorial.
    Route::get('/public-places', [ContentController::class, 'publicPlaces'])->name('places.index');
    Route::get('/public-places/categories', [ContentController::class, 'publicPlaceCategories'])->name('places.categories');
    Route::get('/public-places/{slug}', [ContentController::class, 'publicPlace'])->name('places.show');
    Route::get('/faqs', [ContentController::class, 'faqs'])->name('faqs');
    Route::get('/pages/{slug}', [ContentController::class, 'page'])->name('pages.show');
    Route::get('/settings/public', [ContentController::class, 'settings'])->name('settings.public');

    // SAKA's own advertising inventory. Serving only — the two beacons that
    // count delivery are below, on their own limiter.
    Route::get('/ads', [AdController::class, 'index'])->name('ads.index');

    /*
     * Specialists.
     *
     * Deliberately only two endpoints. Browsing, searching and filtering
     * specialists all go through `/listings?category=specialists` — a
     * specialist IS a listing, so the existing EAV filters already do the work.
     * What lives here is what a listing cannot answer: what they sell, and when
     * they are free.
     */
    Route::get('/specialists/{slug}/services', [PublicSpecialistController::class, 'services'])
        ->name('specialists.services');
    Route::get('/specialists/{slug}/services/{service}/slots', [PublicSpecialistController::class, 'slots'])
        ->name('specialists.slots');
});

/*
 * Booking creation.
 *
 * Open to guests — requiring an account before somebody can ask a lawyer for an
 * hour loses the enquiry. Throttled on its own budget rather than sharing the
 * public read allowance, because a booking is a write and one visitor making
 * several in a session is normal.
 */
Route::post('/bookings', [PublicBookingController::class, 'store'])
    ->middleware('throttle:booking-create')
    ->name('bookings.store');

/*
 * Advertising beacons.
 *
 * Outside the `api-public` group on purpose: a listings page fires one
 * impression per slot, and letting that share the visitor's read budget would
 * throttle them out of the marketplace for scrolling. See `ad-beacon` in
 * RateLimitServiceProvider.
 */
Route::middleware('throttle:ad-beacon')->group(function (): void {
    Route::post('/ads/{uuid}/impression', [AdController::class, 'impression'])->name('ads.impression');
    Route::post('/ads/{uuid}/click', [AdController::class, 'click'])->name('ads.click');
});

// Public unauthenticated write — heavily throttled and honeypot-guarded.
Route::post('/inquiries', [PublicInquiryController::class, 'store'])
    ->middleware('throttle:inquiry-create')->name('inquiries.store');

// --------------------------------------------- account (authenticated user)

Route::prefix('account')
    ->as('account.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api-authenticated'])
    ->group(function (): void {
        // Saved listings and saved businesses. The target type is in the
        // path, so an unknown type is a 404 at the router rather than a
        // validation error part-way through a mutation.
        Route::get('/favorites', [FavoriteController::class, 'listings'])->name('favorites.index');
        Route::get('/favorites/listings', [FavoriteController::class, 'listings'])->name('favorites.listings');
        Route::get('/favorites/businesses', [FavoriteController::class, 'businesses'])->name('favorites.businesses');
        Route::get('/favorites/history', [FavoriteController::class, 'history'])->name('favorites.history');
        Route::post('/favorites/businesses/{slug}', [FavoriteController::class, 'storeBusiness'])->name('favorites.businesses.store');
        Route::delete('/favorites/businesses/{slug}', [FavoriteController::class, 'destroyBusiness'])->name('favorites.businesses.destroy');
        // Declared last: `{listing:slug}` would otherwise swallow /businesses.
        Route::post('/favorites/{listing:slug}', [FavoriteController::class, 'storeListing'])->name('favorites.store');
        Route::delete('/favorites/{listing:slug}', [FavoriteController::class, 'destroyListing'])->name('favorites.destroy');

        Route::get('/reviews', [ReviewController::class, 'mine'])->name('reviews.mine');
        Route::post('/reviews/{listing:slug}', [ReviewController::class, 'store'])
            ->middleware('throttle:review-create')->name('reviews.store');
        Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
        Route::post('/reviews/{review}/reply', [ReviewController::class, 'reply'])->name('reviews.reply');
        Route::post('/reviews/{review}/report', [ReviewController::class, 'report'])->name('reviews.report');

        // The customer's own inquiries — the other half of POST /inquiries.
        Route::get('/inquiries', [AccountInquiryController::class, 'index'])->name('inquiries.index');
        Route::get('/inquiries/{inquiry}', [AccountInquiryController::class, 'show'])->name('inquiries.show');

        // Notification centre.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::patch('/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        // Appointments the customer has booked. Cancel is the ONLY move they
        // have — confirming and completing are the specialist's calls.
        Route::get('/bookings', [AccountBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{uuid}', [AccountBookingController::class, 'show'])->name('bookings.show');
        Route::post('/bookings/{uuid}/cancel', [AccountBookingController::class, 'cancel'])->name('bookings.cancel');

        // Recently viewed and recent searches.
        Route::get('/recently-viewed', [ActivityController::class, 'recentlyViewed'])->name('activity.recently_viewed');
        Route::get('/search-history', [ActivityController::class, 'searchHistory'])->name('activity.search_history');
        Route::delete('/search-history', [ActivityController::class, 'clearSearchHistory'])->name('activity.search_history.clear');
    });

// Business types and the per-vertical rules they imply. PUBLIC on purpose:
// the vendor registration screen needs this before an account exists, and it
// is static reference data.
Route::get('/business-types', [VendorProfileController::class, 'businessTypes'])
    ->name('business_types');

// ------------------------------------------------------------------ seller
//
// NOTE: {listing:uuid} is explicit. Listing::getRouteKeyName() is `slug`, which
// is right for public URLs but wrong here — a seller's draft has a slug that
// leaks its title, and slugs change while a listing is unpublished.

Route::prefix('seller')
    ->as('seller.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api-authenticated'])
    ->group(function (): void {
        Route::get('/dashboard', [SellerDashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [SellerDashboardController::class, 'profile'])->name('profile.show');
        Route::patch('/profile', [SellerDashboardController::class, 'updateProfile'])->name('profile.update');

        Route::get('/listings', [SellerListingController::class, 'index'])->name('listings.index');
        Route::post('/listings', [SellerListingController::class, 'store'])->name('listings.store');
        Route::get('/listings/{listing:uuid}', [SellerListingController::class, 'show'])->name('listings.show');
        Route::patch('/listings/{listing:uuid}', [SellerListingController::class, 'update'])->name('listings.update');
        Route::delete('/listings/{listing:uuid}', [SellerListingController::class, 'destroy'])->name('listings.destroy');

        // Duplicating produces a DRAFT, so it sits outside the phone-verified
        // group — a vendor can already create drafts without a verified phone,
        // and gating the copy but not the original would be arbitrary.
        Route::post('/listings/{listing:uuid}/duplicate', [SellerListingController::class, 'duplicate'])->name('listings.duplicate');

        // Publishing requires a verified phone (Milestone 4 decision 5).
        Route::middleware('phone.verified')->group(function (): void {
            Route::post('/listings/{listing:uuid}/submit', [SellerListingController::class, 'submit'])->name('listings.submit');
            Route::post('/listings/{listing:uuid}/resume', [SellerListingController::class, 'resume'])->name('listings.resume');
        });

        Route::post('/listings/{listing:uuid}/pause', [SellerListingController::class, 'pause'])->name('listings.pause');
        Route::post('/listings/{listing:uuid}/sold', [SellerListingController::class, 'markSold'])->name('listings.sold');
        Route::post('/listings/{listing:uuid}/archive', [SellerListingController::class, 'archive'])->name('listings.archive');

        /*
         * Land parcel outline.
         *
         * PUT rather than POST: a listing has at most one boundary and saving
         * replaces it wholesale, so the operation is idempotent and re-sending
         * the same rings must not produce a second parcel.
         */
        Route::get('/listings/{listing:uuid}/boundary', [SellerBoundaryController::class, 'show'])->name('boundary.show');
        Route::put('/listings/{listing:uuid}/boundary', [SellerBoundaryController::class, 'update'])->name('boundary.update');
        Route::delete('/listings/{listing:uuid}/boundary', [SellerBoundaryController::class, 'destroy'])->name('boundary.destroy');

        // Media.
        Route::post('/listings/{listing:uuid}/media', [SellerMediaController::class, 'store'])
            ->middleware('throttle:media-upload')->name('media.store');
        Route::patch('/listings/{listing:uuid}/media/reorder', [SellerMediaController::class, 'reorder'])->name('media.reorder');
        Route::delete('/listings/{listing:uuid}/media/{media:uuid}', [SellerMediaController::class, 'destroy'])->name('media.destroy');
        Route::post('/listings/{listing:uuid}/media/{media:uuid}/primary', [SellerMediaController::class, 'makePrimary'])->name('media.primary');
        Route::post('/listings/{listing:uuid}/media/{media:uuid}/replace', [SellerMediaController::class, 'replace'])
            ->middleware('throttle:media-upload')->name('media.replace');

        // ---- vendor business profile (onboarding AND settings) -----------
        Route::get('/vendor-profile', [VendorProfileController::class, 'show'])->name('vendor_profile.show');
        Route::patch('/vendor-profile', [VendorProfileController::class, 'update'])->name('vendor_profile.update');
        Route::post('/vendor-profile/branding/{kind}', [VendorProfileController::class, 'uploadBranding'])
            ->whereIn('kind', ['logo', 'cover'])
            ->middleware('throttle:media-upload')
            ->name('vendor_profile.branding.upload');
        Route::delete('/vendor-profile/branding/{kind}', [VendorProfileController::class, 'deleteBranding'])
            ->whereIn('kind', ['logo', 'cover'])
            ->name('vendor_profile.branding.destroy');

        // ---- verification -------------------------------------------------
        Route::get('/verifications', [VendorVerificationController::class, 'index'])->name('verifications.index');
        Route::post('/verifications', [VendorVerificationController::class, 'store'])
            ->middleware('throttle:media-upload')
            ->name('verifications.store');

        /*
         * ---- promotions ----------------------------------------------------
         *
         * Requests only. Nothing on this surface can put anything on the
         * marketplace: approval and activation are behind `advertising.manage`,
         * which no vendor role carries.
         *
         * The literal segments are declared BEFORE `{uuid}`, or Laravel would
         * match `/promotions/options` as a request whose uuid is "options".
         */
        Route::get('/promotions/options', [VendorPromotionController::class, 'options'])->name('promotions.options');
        Route::get('/promotions/promotable', [VendorPromotionController::class, 'promotable'])->name('promotions.promotable');

        Route::get('/promotions', [VendorPromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promotions', [VendorPromotionController::class, 'store'])->name('promotions.store');
        Route::get('/promotions/{uuid}', [VendorPromotionController::class, 'show'])->name('promotions.show');
        Route::post('/promotions/{uuid}/submit', [VendorPromotionController::class, 'submit'])->name('promotions.submit');
        Route::post('/promotions/{uuid}/cancel', [VendorPromotionController::class, 'cancel'])->name('promotions.cancel');
        Route::post('/promotions/{uuid}/artwork/{kind}', [VendorPromotionController::class, 'uploadArtwork'])
            ->whereIn('kind', ['desktop', 'mobile'])
            ->middleware('throttle:media-upload')
            ->name('promotions.artwork');

        /*
         * ---- specialist practice -------------------------------------------
         *
         * Every route resolves the specialist's listing by uuid AND by owner,
         * so a vendor cannot reach another's diary by guessing an identifier.
         * The literal segment is declared before the `{listing}` routes.
         */
        Route::get('/specialist/options', [SellerSpecialistController::class, 'options'])->name('specialist.options');

        Route::get('/specialist/{listing}/services', [SellerSpecialistController::class, 'indexServices'])->name('specialist.services.index');
        Route::post('/specialist/{listing}/services', [SellerSpecialistController::class, 'storeService'])->name('specialist.services.store');
        Route::patch('/specialist/services/{uuid}', [SellerSpecialistController::class, 'updateService'])->name('specialist.services.update');
        Route::delete('/specialist/services/{uuid}', [SellerSpecialistController::class, 'destroyService'])->name('specialist.services.destroy');

        Route::get('/specialist/{listing}/availability', [SellerSpecialistController::class, 'availability'])->name('specialist.availability.show');
        Route::put('/specialist/{listing}/availability', [SellerSpecialistController::class, 'updateAvailability'])->name('specialist.availability.update');
        Route::post('/specialist/{listing}/blocks', [SellerSpecialistController::class, 'storeBlock'])->name('specialist.blocks.store');
        Route::delete('/specialist/{listing}/blocks/{block}', [SellerSpecialistController::class, 'destroyBlock'])
            ->whereNumber('block')->name('specialist.blocks.destroy');

        Route::get('/specialist/{listing}/bookings', [SellerSpecialistController::class, 'indexBookings'])->name('specialist.bookings.index');
        Route::post('/specialist/bookings/{uuid}/transition', [SellerSpecialistController::class, 'transitionBooking'])->name('specialist.bookings.transition');

        // ---- engagement ---------------------------------------------------
        Route::get('/analytics', [VendorEngagementController::class, 'analytics'])->name('analytics');
        Route::get('/reviews', [VendorEngagementController::class, 'reviews'])->name('reviews.index');
        Route::post('/reviews/{review}/reply', [VendorEngagementController::class, 'replyToReview'])->name('reviews.reply');
        Route::post('/reviews/{review}/report', [VendorEngagementController::class, 'reportReview'])->name('reviews.report');

        // Inquiries received.
        Route::get('/inquiries', [SellerDashboardController::class, 'inquiries'])->name('inquiries.index');
        Route::get('/inquiries/{inquiry}', [SellerInquiryController::class, 'show'])->name('inquiries.show');
        Route::post('/inquiries/{inquiry}/reply', [SellerInquiryController::class, 'reply'])->name('inquiries.reply');
        Route::patch('/inquiries/{inquiry}', [VendorEngagementController::class, 'updateInquiry'])->name('inquiries.update');
    });

// ------------------------------------------------------------------- admin

Route::prefix('admin')
    ->as('admin.')
    ->middleware(['auth:sanctum', 'active', 'throttle:admin'])
    ->group(function (): void {
        // ---- dashboard & analytics ---------------------------------------
        Route::get('/stats/overview', [DashboardController::class, 'overview'])->name('stats.overview');
        Route::get('/stats/growth', [DashboardController::class, 'growth'])->name('stats.growth');
        Route::get('/stats/categories', [DashboardController::class, 'categoryPopularity'])->name('stats.categories');
        Route::get('/stats/vendors', [DashboardController::class, 'topVendors'])->name('stats.vendors');
        Route::get('/activity', [DashboardController::class, 'activity'])->name('activity.index');

        // ---- listings ----------------------------------------------------
        //
        // The specific segments are declared BEFORE the {uuid} routes: Laravel
        // matches in definition order, so `/listings/pending` registered after
        // `/listings/{uuid}` would be swallowed by the wildcard and 404.
        Route::get('/listings', [AdminListingController::class, 'index'])->name('listings.index');
        Route::get('/listings/pending', [ModerationController::class, 'pendingListings'])->name('listings.pending');
        Route::get('/listing-reports', [ModerationController::class, 'listingReports'])->name('listings.reports');
        Route::post('/listing-reports/{uuid}/resolve', [ModerationController::class, 'resolveReport'])->name('listings.reports.resolve');
        Route::post('/listings/bulk', [AdminListingController::class, 'bulk'])->name('listings.bulk');
        Route::post('/listings/{listing:uuid}/approve', [ModerationController::class, 'approve'])->name('listings.approve');
        Route::post('/listings/{listing:uuid}/reject', [ModerationController::class, 'reject'])->name('listings.reject');
        Route::post('/listings/{listing:uuid}/verify', [ModerationController::class, 'verify'])->name('listings.verify');
        Route::post('/listings/{listing:uuid}/feature', [ModerationController::class, 'feature'])->name('listings.feature');

        // {uuid} as a plain string, not a bound model: route-model binding
        // applies the SoftDeletes scope and would 404 exactly the deleted
        // listing an administrator is trying to inspect or restore.
        Route::get('/listings/{uuid}', [AdminListingController::class, 'show'])->name('listings.show');
        Route::post('/listings/{uuid}/transition', [AdminListingController::class, 'transition'])->name('listings.transition');
        Route::delete('/listings/{uuid}', [AdminListingController::class, 'destroy'])->name('listings.destroy');
        Route::post('/listings/{uuid}/restore', [AdminListingController::class, 'restore'])->name('listings.restore');
        Route::delete('/listings/{uuid}/force', [AdminListingController::class, 'forceDestroy'])->name('listings.force');

        // ---- bookings ----------------------------------------------------
        Route::get('/bookings', [AdminBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/stats', [AdminBookingController::class, 'stats'])->name('bookings.stats');

        Route::get('/reviews/pending', [ModerationController::class, 'pendingReviews'])->name('reviews.pending');
        Route::post('/reviews/{review}/moderate', [ModerationController::class, 'moderateReview'])->name('reviews.moderate');

        // ---- users -------------------------------------------------------
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user:uuid}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user:uuid}/status', [UserController::class, 'updateStatus'])->name('users.status');
        Route::patch('/users/{user:uuid}/roles', [UserController::class, 'updateRoles'])->name('users.roles');
        Route::post('/users/{user:uuid}/password-reset', [UserController::class, 'sendPasswordReset'])->name('users.password_reset');
        Route::get('/users/{user:uuid}/activity', [UserController::class, 'activity'])->name('users.activity');

        // ---- roles (read-only; the matrix lives in code) ------------------
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/permissions', [RoleController::class, 'permissions'])->name('permissions.index');

        // ---- seller verification ----------------------------------------
        Route::get('/verifications', [VerificationController::class, 'index'])->name('verifications.index');
        Route::post('/verifications/{verification:uuid}/approve', [VerificationController::class, 'approve'])->name('verifications.approve');
        Route::post('/verifications/{verification:uuid}/reject', [VerificationController::class, 'reject'])->name('verifications.reject');
        Route::post('/verifications/{verification:uuid}/request-info', [VerificationController::class, 'requestInformation'])->name('verifications.request_info');

        // ---- taxonomy ----------------------------------------------------
        Route::post('/categories', [TaxonomyController::class, 'storeCategory'])->name('categories.store');
        Route::patch('/categories/{category:slug}', [TaxonomyController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category:slug}', [TaxonomyController::class, 'destroyCategory'])->name('categories.destroy');
        Route::put('/categories/{category:slug}/attributes', [TaxonomyController::class, 'attachAttribute'])->name('categories.attributes');

        Route::get('/attributes', [TaxonomyController::class, 'indexAttributes'])->name('attributes.index');
        Route::post('/attributes', [TaxonomyController::class, 'storeAttribute'])->name('attributes.store');
        Route::patch('/attributes/{attribute:code}', [TaxonomyController::class, 'updateAttribute'])->name('attributes.update');
        Route::delete('/attributes/{attribute:code}', [TaxonomyController::class, 'destroyAttribute'])->name('attributes.destroy');

        // Amenities and facilities share one shape, so one set of routes.
        Route::post('/taxonomy/{type}', [TaxonomyController::class, 'storeTerm'])
            ->whereIn('type', ['amenities', 'facilities'])->name('taxonomy.store');
        Route::patch('/taxonomy/{type}/{slug}', [TaxonomyController::class, 'updateTerm'])
            ->whereIn('type', ['amenities', 'facilities'])->name('taxonomy.update');
        Route::delete('/taxonomy/{type}/{slug}', [TaxonomyController::class, 'destroyTerm'])
            ->whereIn('type', ['amenities', 'facilities'])->name('taxonomy.destroy');

        // ---- CMS ---------------------------------------------------------
        Route::get('/faqs', [AdminContentController::class, 'indexFaqs'])->name('faqs.index');
        Route::post('/faqs', [AdminContentController::class, 'storeFaq'])->name('faqs.store');
        Route::patch('/faqs/{faq}', [AdminContentController::class, 'updateFaq'])->name('faqs.update');
        Route::delete('/faqs/{faq}', [AdminContentController::class, 'destroyFaq'])->name('faqs.destroy');

        Route::get('/pages', [AdminContentController::class, 'indexPages'])->name('pages.index');
        Route::post('/pages', [AdminContentController::class, 'storePage'])->name('pages.store');
        Route::patch('/pages/{page:slug}', [AdminContentController::class, 'updatePage'])->name('pages.update');
        Route::post('/pages/{page:slug}/publish', [AdminContentController::class, 'publishPage'])->name('pages.publish');

        // ---- homepage CMS -------------------------------------------------
        Route::get('/banners', [HomepageController::class, 'indexBanners'])->name('banners.index');
        Route::post('/banners', [HomepageController::class, 'storeBanner'])->name('banners.store');
        Route::post('/banners/reorder', [HomepageController::class, 'reorderBanners'])->name('banners.reorder');
        Route::patch('/banners/{uuid}', [HomepageController::class, 'updateBanner'])->name('banners.update');
        Route::delete('/banners/{uuid}', [HomepageController::class, 'destroyBanner'])->name('banners.destroy');

        Route::get('/sections', [HomepageController::class, 'indexSections'])->name('sections.index');
        Route::post('/sections/reorder', [HomepageController::class, 'reorderSections'])->name('sections.reorder');
        Route::patch('/sections/{key}', [HomepageController::class, 'updateSection'])->name('sections.update');

        // ---- public places -------------------------------------------------
        Route::get('/place-categories', [AdminPlaceController::class, 'indexCategories'])->name('place_categories.index');
        Route::post('/place-categories', [AdminPlaceController::class, 'storeCategory'])->name('place_categories.store');
        Route::patch('/place-categories/{slug}', [AdminPlaceController::class, 'updateCategory'])->name('place_categories.update');
        Route::delete('/place-categories/{slug}', [AdminPlaceController::class, 'destroyCategory'])->name('place_categories.destroy');

        Route::get('/places', [AdminPlaceController::class, 'index'])->name('places.index');
        Route::post('/places', [AdminPlaceController::class, 'store'])->name('places.store');
        Route::patch('/places/{slug}', [AdminPlaceController::class, 'update'])->name('places.update');
        Route::delete('/places/{slug}', [AdminPlaceController::class, 'destroy'])->name('places.destroy');

        /*
         * ---- advertising ---------------------------------------------------
         *
         * Note the ORDER. `/ad-campaigns/options` is declared before the
         * `{uuid}` routes: Laravel matches in definition order, so registered
         * after them the literal segment would be swallowed by the wildcard and
         * resolve as a campaign whose uuid is "options" — a 404 on the endpoint
         * the admin form needs before it can render at all.
         */
        Route::get('/advertising/options', [AdvertisingController::class, 'options'])->name('advertising.options');
        Route::get('/advertising/performance', [AdvertisingController::class, 'performance'])->name('advertising.performance');

        Route::get('/promotion-requests', [AdvertisingController::class, 'indexPromotions'])->name('promotions.index');
        Route::post('/promotion-requests/{uuid}/approve', [AdvertisingController::class, 'approvePromotion'])->name('promotions.approve');
        Route::post('/promotion-requests/{uuid}/reject', [AdvertisingController::class, 'rejectPromotion'])->name('promotions.reject');

        Route::get('/advertisers', [AdvertisingController::class, 'indexAdvertisers'])->name('advertisers.index');
        Route::post('/advertisers', [AdvertisingController::class, 'storeAdvertiser'])->name('advertisers.store');
        Route::patch('/advertisers/{uuid}', [AdvertisingController::class, 'updateAdvertiser'])->name('advertisers.update');

        Route::get('/ad-campaigns', [AdvertisingController::class, 'indexCampaigns'])->name('ad_campaigns.index');
        Route::post('/ad-campaigns', [AdvertisingController::class, 'storeCampaign'])->name('ad_campaigns.store');
        Route::get('/ad-campaigns/{uuid}', [AdvertisingController::class, 'showCampaign'])->name('ad_campaigns.show');
        Route::patch('/ad-campaigns/{uuid}', [AdvertisingController::class, 'updateCampaign'])->name('ad_campaigns.update');
        Route::delete('/ad-campaigns/{uuid}', [AdvertisingController::class, 'destroyCampaign'])->name('ad_campaigns.destroy');
        Route::post('/ad-campaigns/{uuid}/transition', [AdvertisingController::class, 'transitionCampaign'])->name('ad_campaigns.transition');
        Route::post('/ad-campaigns/{uuid}/creatives', [AdvertisingController::class, 'storeCreative'])->name('ad_creatives.store');

        Route::patch('/ad-creatives/{uuid}', [AdvertisingController::class, 'updateCreative'])->name('ad_creatives.update');
        Route::delete('/ad-creatives/{uuid}', [AdvertisingController::class, 'destroyCreative'])->name('ad_creatives.destroy');
        Route::post('/ad-creatives/{uuid}/image/{kind}', [AdvertisingController::class, 'uploadCreativeImage'])
            ->whereIn('kind', ['desktop', 'mobile'])
            ->middleware('throttle:media-upload')
            ->name('ad_creatives.image');

        // ---- settings ----------------------------------------------------
        Route::get('/settings', [AdminContentController::class, 'indexSettings'])->name('settings.index');
        Route::patch('/settings', [AdminContentController::class, 'updateSettings'])->name('settings.update');

        // Operational controls: actions, not stored values.
        Route::get('/system', [SystemController::class, 'info'])->name('system.info');
        Route::post('/system/cache', [SystemController::class, 'clearCache'])->name('system.cache');
        Route::post('/system/maintenance', [SystemController::class, 'maintenance'])->name('system.maintenance');
    });
