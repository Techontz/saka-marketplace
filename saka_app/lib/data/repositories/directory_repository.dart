import 'package:dio/dio.dart';

import '../../core/network/api_client.dart';
import '../models/booking.dart';
import '../models/business.dart';
import '../models/category.dart';
import '../models/json.dart';
import '../models/listing.dart';
import '../models/misc.dart';
import '../models/paginated.dart';

/// Businesses, specialists and public places.
///
/// Specialists are grouped here rather than in their own repository because on
/// this backend a specialist IS a listing in the `specialists` vertical — the
/// directory is `GET /listings?category=specialists`, and only the services and
/// availability endpoints are specialist-specific. Modelling them as a separate
/// entity in the app would invent a distinction the API does not make.
class DirectoryRepository {
  DirectoryRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// The vertical slug specialists live under.
  static const String specialistsCategory = 'specialists';

  // --- businesses ----------------------------------------------------------

  Future<Paginated<Business>> businesses({
    int page = 1,
    String? search,
    String? regionSlug,
    String? districtSlug,
    String? businessType,
    bool verifiedOnly = false,
    CancelToken? cancelToken,
  }) {
    return _api.get<Paginated<Business>>(
      '/businesses',
      query: <String, dynamic>{
        'page': page,
        'q': search,
        'region': regionSlug,
        'district': districtSlug,
        'type': businessType,
        if (verifiedOnly) 'verified': 1,
      },
      cancelToken: cancelToken,
      parse: (dynamic body) =>
          Paginated.parse<Business>(body, Business.tryParse),
    );
  }

  Future<Business> business(String slug) {
    return _api.get<Business>(
      '/businesses/$slug',
      parse: (dynamic body) {
        final Business? business = Business.tryParse(asMap(body)['data']);
        if (business == null) throw StateError('business not parseable');
        return business;
      },
    );
  }

  Future<Paginated<Listing>> businessListings(String slug, {int page = 1}) {
    return _api.get<Paginated<Listing>>(
      '/businesses/$slug/listings',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Listing>(body, Listing.tryParse),
    );
  }

  Future<Paginated<Review>> businessReviews(String slug, {int page = 1}) {
    return _api.get<Paginated<Review>>(
      '/businesses/$slug/reviews',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Review>(body, Review.tryParse),
    );
  }

  Future<List<Business>> similarBusinesses(String slug) {
    return _api.get<List<Business>>(
      '/businesses/$slug/similar',
      parse: (dynamic body) => Business.parseList(asMap(body)['data']),
    );
  }

  // --- specialists ---------------------------------------------------------

  /// A specialist's bookable services.
  Future<List<SpecialistService>> specialistServices(String slug) {
    return _api.get<List<SpecialistService>>(
      '/specialists/$slug/services',
      parse: (dynamic body) =>
          SpecialistService.parseList(asMap(body)['data']),
    );
  }

  /// Real availability, computed by the backend from the specialist's weekly
  /// pattern minus blocks minus existing bookings. Never derived client-side.
  Future<Availability> specialistSlots({
    required String slug,
    required String serviceUuid,
    int days = 14,
    CancelToken? cancelToken,
  }) {
    return _api.get<Availability>(
      '/specialists/$slug/services/$serviceUuid/slots',
      query: <String, dynamic>{'days': days},
      cancelToken: cancelToken,
      // Availability is the one read that must never be shared or cached: two
      // widgets asking a second apart can legitimately get different answers,
      // and showing a stale grid is how a customer picks a taken slot.
      deduplicate: false,
      parse: Availability.parse,
    );
  }

  // --- public places -------------------------------------------------------

  Future<Paginated<PublicPlace>> places({
    int page = 1,
    String? search,
    String? categorySlug,
    String? regionSlug,
    String? districtSlug,
    double? latitude,
    double? longitude,
    double? radiusKm,
    CancelToken? cancelToken,
  }) {
    return _api.get<Paginated<PublicPlace>>(
      '/public-places',
      query: <String, dynamic>{
        'page': page,
        'q': search,
        'category': categorySlug,
        'region': regionSlug,
        'district': districtSlug,
        'lat': latitude,
        'lng': longitude,
        'radius': radiusKm,
      },
      cancelToken: cancelToken,
      parse: (dynamic body) =>
          Paginated.parse<PublicPlace>(body, PublicPlace.tryParse),
    );
  }

  Future<PublicPlace> place(String slug) {
    return _api.get<PublicPlace>(
      '/public-places/$slug',
      parse: (dynamic body) {
        final PublicPlace? place = PublicPlace.tryParse(asMap(body)['data']);
        if (place == null) throw StateError('place not parseable');
        return place;
      },
    );
  }

  Future<List<Category>> placeCategories() {
    return _api.get<List<Category>>(
      '/public-places/categories',
      parse: (dynamic body) => Category.parseList(asMap(body)['data']),
    );
  }
}
