/// Route names.
///
/// Mirroring the web's URL structure exactly (`/listings/:slug`,
/// `/businesses/:slug`) so a shared SAKA link maps onto a screen without a
/// translation table — which is what makes deep linking tractable later.
abstract final class Routes {
  static const String shell = '/';

  static const String search = '/search';
  static const String listings = '/listings';
  static const String listingDetail = '/listings/:slug';
  static const String gallery = '/gallery';

  static const String businesses = '/businesses';
  static const String businessDetail = '/businesses/:slug';

  static const String specialists = '/specialists';

  static const String places = '/public-places';
  static const String placeDetail = '/public-places/:slug';

  static const String booking = '/booking';
  static const String bookingConfirmed = '/booking/confirmed';

  static const String signIn = '/sign-in';
  static const String register = '/register';
  static const String forgotPassword = '/forgot-password';

  static const String settings = '/settings';
  static const String editProfile = '/settings/profile';
  static const String changePassword = '/settings/password';
  static const String notifications = '/notifications';
  static const String myReviews = '/my-reviews';
  static const String recentlyViewed = '/recently-viewed';
  static const String about = '/about';

  static const String vendor = '/vendor';
  static const String vendorListings = '/vendor/listings';
  static const String boundaryEditor = '/vendor/listings/boundary';
  static const String vendorBookings = '/vendor/bookings';
  static const String vendorPromotions = '/vendor/promotions';
  static const String vendorVerification = '/vendor/verification';

  /// Builds a concrete path from a template. Keeps `:slug` substitution in one
  /// place rather than string-interpolating route paths at every call site.
  static String listingPath(String slug) => '/listings/$slug';
  static String businessPath(String slug) => '/businesses/$slug';
  static String placePath(String slug) => '/public-places/$slug';

  const Routes._();
}
