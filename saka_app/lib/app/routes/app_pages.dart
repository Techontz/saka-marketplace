import 'package:get/get.dart';

import '../../features/account/account_screen.dart';
import '../../features/account/simple_screens.dart';
import '../../features/auth/forgot_password_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/businesses/business_detail_screen.dart';
import '../../features/businesses/businesses_screen.dart';
import '../../features/bookings/my_bookings_screen.dart';
import '../../features/listings/listing_detail_screen.dart';
import '../../features/notifications/notifications_screen.dart';
import '../../features/places/places_screen.dart';
import '../../features/search/search_screen.dart';
import '../../features/shell/shell_screen.dart';
import '../../features/specialists/specialists_screen.dart';
import '../../features/vendor/promotions_screen.dart';
import '../../features/vendor/vendor_dashboard_screen.dart';
import '../../features/vendor/vendor_listings_screen.dart';
import '../../features/vendor/verification_screen.dart';
import 'app_routes.dart';

/// The route table.
///
/// Named routes, mirroring the web's URLs, so a shared SAKA link maps onto a
/// screen with no translation layer — `/listings/:slug` here is the same path
/// the website serves. That is what makes deep linking a configuration change
/// rather than a rewrite.
///
/// Screens reached WITH data in hand — a listing tapped from a card — are
/// pushed with `Get.to` and their model as an argument, so they paint
/// immediately. Only the routes that can be entered cold appear here.
abstract final class AppPages {
  static final List<GetPage<dynamic>> pages = <GetPage<dynamic>>[
    GetPage<void>(
      name: Routes.shell,
      page: () => const ShellScreen(),
    ),

    // --- discovery ---------------------------------------------------------
    GetPage<void>(name: Routes.search, page: () => const SearchScreen()),
    GetPage<void>(
      name: Routes.listingDetail,
      page: () => const ListingDetailScreen(),
    ),
    GetPage<void>(
      name: Routes.businesses,
      page: () => const BusinessesScreen(),
    ),
    GetPage<void>(
      name: Routes.businessDetail,
      page: () => const BusinessDetailScreen(),
    ),
    GetPage<void>(
      name: Routes.specialists,
      page: () => const SpecialistsScreen(),
    ),
    GetPage<void>(name: Routes.places, page: () => const PlacesScreen()),

    // --- auth --------------------------------------------------------------
    GetPage<void>(name: Routes.register, page: () => const RegisterScreen()),
    GetPage<void>(
      name: Routes.forgotPassword,
      page: () => const ForgotPasswordScreen(),
    ),

    // --- account -----------------------------------------------------------
    GetPage<void>(name: Routes.settings, page: () => const AccountScreen()),
    GetPage<void>(
      name: Routes.editProfile,
      page: () => const EditProfileScreen(),
    ),
    GetPage<void>(
      name: Routes.changePassword,
      page: () => const ChangePasswordScreen(),
    ),
    GetPage<void>(
      name: Routes.notifications,
      page: () => const NotificationsScreen(),
    ),
    GetPage<void>(name: Routes.myReviews, page: () => const MyReviewsScreen()),
    GetPage<void>(
      name: Routes.recentlyViewed,
      page: () => const RecentlyViewedScreen(),
    ),
    GetPage<void>(name: Routes.about, page: () => const AboutScreen()),
    GetPage<void>(
      name: Routes.booking,
      page: () => const MyBookingsScreen(),
    ),

    // --- vendor ------------------------------------------------------------
    GetPage<void>(
      name: Routes.vendor,
      page: () => const VendorDashboardScreen(),
    ),
    GetPage<void>(
      name: Routes.vendorListings,
      page: () => const VendorListingsScreen(),
    ),
    GetPage<void>(
      name: Routes.vendorVerification,
      page: () => const VerificationScreen(),
    ),
    GetPage<void>(
      name: Routes.vendorPromotions,
      page: () => const PromotionsScreen(),
    ),
  ];

  const AppPages._();
}
