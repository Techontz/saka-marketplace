<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Enums\RoleSlug;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Review;
use App\Models\User;
use App\Policies\InquiryPolicy;
use App\Policies\ListingPolicy;
use App\Policies\MediaPolicy;
use App\Policies\ReviewPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Listing::class => ListingPolicy::class,
        Review::class => ReviewPolicy::class,
        Inquiry::class => InquiryPolicy::class,
        Media::class => MediaPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        /*
         * Super admin bypass.
         *
         * Returns null (not false) for everyone else so the normal policy still
         * runs — returning false here would deny every ability outright.
         */
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole(RoleSlug::SuperAdmin->value) ? true : null;
        });
    }
}
