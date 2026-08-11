<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Every permission in the system, as `resource.action`.
 *
 * Kept as an enum rather than free strings so a typo is a fatal error at the
 * call site instead of a silently-denied check.
 */
enum Permission: string
{
    // Listings
    case ListingViewAny = 'listing.view_any';
    case ListingCreate = 'listing.create';
    case ListingUpdate = 'listing.update';
    case ListingUpdateAny = 'listing.update_any';
    case ListingDelete = 'listing.delete';
    case ListingDeleteAny = 'listing.delete_any';
    case ListingPublish = 'listing.publish';
    case ListingModerate = 'listing.moderate';
    case ListingFeature = 'listing.feature';
    case ListingVerify = 'listing.verify';

    // Taxonomy
    case CategoryManage = 'category.manage';
    case AttributeManage = 'attribute.manage';
    case AmenityManage = 'amenity.manage';
    case LocationManage = 'location.manage';

    // Users
    case UserViewAny = 'user.view_any';
    case UserUpdate = 'user.update';
    case UserSuspend = 'user.suspend';
    case UserBan = 'user.ban';
    case UserAssignRole = 'user.assign_role';

    // Trust
    case SellerVerify = 'seller.verify';
    case VerificationReview = 'verification.review';

    // Engagement
    case InquiryViewAny = 'inquiry.view_any';
    case InquiryRespond = 'inquiry.respond';
    case ReviewModerate = 'review.moderate';

    // Media
    case MediaUpload = 'media.upload';
    case MediaDelete = 'media.delete';
    case MediaDeleteAny = 'media.delete_any';

    /*
     * Advertising.
     *
     * Its own permission rather than folding into `cms.manage`. A CMS editor
     * writes the About page; an advertising operator books inventory that
     * SAKA invoices for and can take a competitor's campaign off the site.
     * Those are different jobs and, in a marketplace selling its own
     * inventory, different people.
     */
    case AdvertisingManage = 'advertising.manage';

    // Platform
    case CmsManage = 'cms.manage';
    case SettingsManage = 'settings.manage';
    case ActivityLogView = 'activity_log.view';
    case AnalyticsView = 'analytics.view';

    public function group(): string
    {
        return str_contains($this->value, '.')
            ? explode('.', $this->value)[0]
            : 'general';
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Default permission set per role. Consumed by RolePermissionSeeder;
     * changing this file and re-running the seeder is the supported way to
     * adjust the baseline.
     *
     * @return array<int, self>
     */
    public static function forRole(RoleSlug $role): array
    {
        // Any registered user may post — there is no separate "seller signup".
        // The seller ROLE is granted automatically on first listing
        // (ListingService::ensureSellerRole), which is what turns a buyer into
        // a seller. Without ListingCreate here that transition is impossible.
        $buyer = [
            self::ListingViewAny,
            self::ListingCreate,
            self::ListingUpdate,
            self::ListingDelete,
            self::MediaUpload,
            self::MediaDelete,
        ];

        $seller = array_merge($buyer, [
            // Publishing is the seller-only capability, and is additionally
            // gated on a verified phone at the route and domain layers.
            self::ListingPublish,
            self::InquiryRespond,
        ]);

        $agent = $seller;

        $moderator = array_merge($buyer, [
            self::ListingModerate,
            self::ListingUpdateAny,
            self::ListingVerify,
            self::InquiryViewAny,
            self::ReviewModerate,
            self::VerificationReview,
            self::MediaDeleteAny,
            self::UserViewAny,
        ]);

        $admin = array_merge($moderator, $seller, [
            self::ListingDeleteAny,
            self::ListingFeature,
            self::CategoryManage,
            self::AttributeManage,
            self::AmenityManage,
            self::LocationManage,
            self::UserUpdate,
            self::UserSuspend,
            self::UserBan,
            self::UserAssignRole,
            self::SellerVerify,
            self::CmsManage,
            // Booking and selling SAKA's own inventory is an administrator's
            // job, not a moderator's.
            self::AdvertisingManage,
            self::AnalyticsView,
            self::ActivityLogView,
        ]);

        return match ($role) {
            RoleSlug::Buyer => $buyer,
            RoleSlug::Seller => $seller,
            RoleSlug::Agent => $agent,
            RoleSlug::Moderator => $moderator,
            RoleSlug::Admin => array_values(array_unique($admin, SORT_REGULAR)),
            RoleSlug::SuperAdmin => self::cases(),
        };
    }
}
