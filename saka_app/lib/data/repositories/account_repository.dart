import '../../core/network/api_client.dart';
import '../models/business.dart';
import '../models/json.dart';
import '../models/listing.dart';
import '../models/misc.dart';
import '../models/paginated.dart';
import '../models/user.dart';

/// Everything under `/account` — the signed-in person's own data.
class AccountRepository {
  AccountRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  // --- profile -------------------------------------------------------------

  Future<AppUser> profile() {
    return _api.get<AppUser>(
      '/account/profile',
      parse: (dynamic body) {
        final AppUser? user = AppUser.tryParse(asMap(body)['data']);
        if (user == null) throw StateError('profile not parseable');
        return user;
      },
    );
  }

  Future<AppUser> updateProfile(Map<String, dynamic> changes) {
    return _api.patch<AppUser>(
      '/account/profile',
      body: changes,
      parse: (dynamic body) {
        final AppUser? user = AppUser.tryParse(asMap(body)['data']);
        if (user == null) throw StateError('profile not parseable');
        return user;
      },
    );
  }

  Future<void> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) {
    return _api.patch<void>(
      '/account/password',
      body: <String, dynamic>{
        'current_password': currentPassword,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
      parse: (_) {},
    );
  }

  // --- favourites ----------------------------------------------------------
  //
  // Listings and businesses are separate collections on this API, with
  // separate toggle endpoints. The app keeps them separate too rather than
  // inventing a unified "saved" abstraction the backend does not have.

  Future<Paginated<Listing>> favoriteListings({int page = 1}) {
    return _api.get<Paginated<Listing>>(
      '/account/favorites/listings',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Listing>(body, Listing.tryParse),
    );
  }

  Future<Paginated<Business>> favoriteBusinesses({int page = 1}) {
    return _api.get<Paginated<Business>>(
      '/account/favorites/businesses',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) =>
          Paginated.parse<Business>(body, Business.tryParse),
    );
  }

  Future<void> favoriteListing(String slug) {
    return _api.post<void>('/account/favorites/$slug', parse: (_) {});
  }

  Future<void> unfavoriteListing(String slug) {
    return _api.delete<void>('/account/favorites/$slug', parse: (_) {});
  }

  Future<void> favoriteBusiness(String slug) {
    return _api.post<void>(
      '/account/favorites/businesses/$slug',
      parse: (_) {},
    );
  }

  Future<void> unfavoriteBusiness(String slug) {
    return _api.delete<void>(
      '/account/favorites/businesses/$slug',
      parse: (_) {},
    );
  }

  Future<Paginated<Listing>> recentlyViewed({int page = 1}) {
    return _api.get<Paginated<Listing>>(
      '/account/recently-viewed',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Listing>(body, Listing.tryParse),
    );
  }

  // --- notifications -------------------------------------------------------

  Future<({Paginated<AppNotification> page, int unreadCount})> notifications({
    int page = 1,
    bool unreadOnly = false,
  }) {
    return _api.get<({Paginated<AppNotification> page, int unreadCount})>(
      '/account/notifications',
      query: <String, dynamic>{
        'page': page,
        if (unreadOnly) 'unread': 1,
      },
      parse: (dynamic body) => (
        page: Paginated.parse<AppNotification>(body, AppNotification.tryParse),
        unreadCount: asIntOr(asMap(asMap(body)['meta'])['unread_count'], 0),
      ),
    );
  }

  Future<int> unreadNotificationCount() {
    return _api.get<int>(
      '/account/notifications/unread-count',
      parse: (dynamic body) =>
          asIntOr(asMap(asMap(body)['data'])['unread_count'], 0),
    );
  }

  Future<void> markNotificationRead(String id) {
    return _api.post<void>('/account/notifications/$id/read', parse: (_) {});
  }

  Future<void> markAllNotificationsRead() {
    return _api.post<void>('/account/notifications/read-all', parse: (_) {});
  }

  Future<void> deleteNotification(String id) {
    return _api.delete<void>('/account/notifications/$id', parse: (_) {});
  }

  // --- reviews -------------------------------------------------------------

  Future<Paginated<Review>> myReviews({int page = 1}) {
    return _api.get<Paginated<Review>>(
      '/account/reviews',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Review>(body, Review.tryParse),
    );
  }

  Future<void> writeReview({
    required String listingSlug,
    required int rating,
    String? title,
    String? body,
  }) {
    return _api.post<void>(
      '/account/reviews/$listingSlug',
      body: <String, dynamic>{
        'rating': rating,
        if (title != null && title.isNotEmpty) 'title': title,
        if (body != null && body.isNotEmpty) 'body': body,
      },
      parse: (_) {},
    );
  }

  Future<void> updateReview({
    required String reviewUuid,
    required int rating,
    String? title,
    String? body,
  }) {
    return _api.patch<void>(
      '/account/reviews/$reviewUuid',
      body: <String, dynamic>{
        'rating': rating,
        // Omitted entirely when null: PATCH is a partial update, and sending
        // `"title": null` would blank the stored title rather than leave it.
        'title': ?title,
        'body': ?body,
      },
      parse: (_) {},
    );
  }

  Future<void> deleteReview(String reviewUuid) {
    return _api.delete<void>('/account/reviews/$reviewUuid', parse: (_) {});
  }

  // --- inquiries -----------------------------------------------------------

  Future<Paginated<Map<String, dynamic>>> inquiries({int page = 1}) {
    return _api.get<Paginated<Map<String, dynamic>>>(
      '/account/inquiries',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Map<String, dynamic>>(
        body,
        (dynamic item) => item is Map ? asMap(item) : null,
      ),
    );
  }

  // --- search history ------------------------------------------------------

  Future<List<String>> searchHistory() {
    return _api.get<List<String>>(
      '/account/search-history',
      parse: (dynamic body) => <String>[
        for (final Map<String, dynamic> item in asMapList(asMap(body)['data']))
          if (asString(item['query']) case final String q) q,
      ],
    );
  }

  Future<void> clearSearchHistory() {
    return _api.delete<void>('/account/search-history', parse: (_) {});
  }
}
