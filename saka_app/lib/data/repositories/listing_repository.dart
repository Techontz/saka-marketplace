import 'package:dio/dio.dart';

import '../../core/network/api_client.dart';
import '../models/boundary.dart';
import '../models/json.dart';
import '../models/listing.dart';
import '../models/misc.dart';
import '../models/paginated.dart';

/// The filters a listing query can carry.
///
/// A value object rather than a bag of named parameters so a filter set can be
/// held in a controller, compared for equality, and turned into a query in one
/// place. `attributes` is the category-specific part — the codes come from
/// `GET /categories/{slug}/attributes`, never from a constant in this app.
class ListingQuery {
  const ListingQuery({
    this.search,
    this.categorySlug,
    this.regionSlug,
    this.districtSlug,
    this.wardSlug,
    this.purpose,
    this.condition,
    this.minPrice,
    this.maxPrice,
    this.sort,
    this.verifiedOnly = false,
    this.latitude,
    this.longitude,
    this.radiusKm,
    this.attributes = const <String, String>{},
  });

  final String? search;
  final String? categorySlug;
  final String? regionSlug;
  final String? districtSlug;
  final String? wardSlug;
  final String? purpose;
  final String? condition;
  final int? minPrice;
  final int? maxPrice;

  /// `newest` | `price_asc` | `price_desc` | `popular` — whatever the backend
  /// accepts; passed straight through.
  final String? sort;

  final bool verifiedOnly;
  final double? latitude;
  final double? longitude;
  final double? radiusKm;

  /// attribute code → value, sent as `attributes[beds]=3`.
  final Map<String, String> attributes;

  /// How many filters the user has actually applied — the number on the filter
  /// button. Search text and sort are excluded: they have their own affordances
  /// and counting them makes the badge lie.
  int get activeCount {
    int count = 0;
    if (categorySlug != null) count++;
    if (regionSlug != null || districtSlug != null || wardSlug != null) count++;
    if (purpose != null) count++;
    if (condition != null) count++;
    if (minPrice != null || maxPrice != null) count++;
    if (verifiedOnly) count++;
    count += attributes.length;
    return count;
  }

  bool get isEmpty => activeCount == 0 && (search == null || search!.isEmpty);

  Map<String, dynamic> toQuery() {
    return <String, dynamic>{
      'q': search,
      'category': categorySlug,
      'region': regionSlug,
      'district': districtSlug,
      'ward': wardSlug,
      'purpose': purpose,
      'condition': condition,
      'min_price': minPrice,
      'max_price': maxPrice,
      'sort': sort,
      if (verifiedOnly) 'verified': 1,
      'lat': latitude,
      'lng': longitude,
      'radius': radiusKm,
      for (final MapEntry<String, String> e in attributes.entries)
        'attributes[${e.key}]': e.value,
    };
  }

  ListingQuery copyWith({
    String? search,
    String? categorySlug,
    String? regionSlug,
    String? districtSlug,
    String? wardSlug,
    String? purpose,
    String? condition,
    int? minPrice,
    int? maxPrice,
    String? sort,
    bool? verifiedOnly,
    Map<String, String>? attributes,
    bool clearCategory = false,
    bool clearLocation = false,
    bool clearPrice = false,
  }) {
    return ListingQuery(
      search: search ?? this.search,
      categorySlug: clearCategory ? null : (categorySlug ?? this.categorySlug),
      regionSlug: clearLocation ? null : (regionSlug ?? this.regionSlug),
      districtSlug: clearLocation ? null : (districtSlug ?? this.districtSlug),
      wardSlug: clearLocation ? null : (wardSlug ?? this.wardSlug),
      purpose: purpose ?? this.purpose,
      condition: condition ?? this.condition,
      minPrice: clearPrice ? null : (minPrice ?? this.minPrice),
      maxPrice: clearPrice ? null : (maxPrice ?? this.maxPrice),
      sort: sort ?? this.sort,
      verifiedOnly: verifiedOnly ?? this.verifiedOnly,
      latitude: latitude,
      longitude: longitude,
      radiusKm: radiusKm,
      attributes: attributes ?? this.attributes,
    );
  }
}

class ListingRepository {
  ListingRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<Paginated<Listing>> search(
    ListingQuery query, {
    int page = 1,
    int perPage = 20,
    CancelToken? cancelToken,
  }) {
    return _api.get<Paginated<Listing>>(
      '/listings',
      query: <String, dynamic>{
        ...query.toQuery(),
        'page': page,
        'per_page': perPage,
      },
      cancelToken: cancelToken,
      parse: (dynamic body) => Paginated.parse<Listing>(body, Listing.tryParse),
    );
  }

  /// A home rail. Returns a plain list because these endpoints are not
  /// paginated — treating them as page 1 of many is what makes an infinite
  /// scroller loop over the same rows.
  Future<List<Listing>> rail(
    ListingRailKind rail, {
    int limit = 12,
    double? latitude,
    double? longitude,
  }) {
    return _api.get<List<Listing>>(
      '/listings/${rail.path}',
      query: <String, dynamic>{
        'limit': limit,
        if (rail == ListingRailKind.recommended) ...<String, dynamic>{
          'lat': latitude,
          'lng': longitude,
        },
      },
      parse: (dynamic body) => Listing.parseList(asMap(body)['data']),
    );
  }

  /// The detail resource.
  ///
  /// `meta.is_favorited` is folded onto the model here rather than left for the
  /// caller, because it arrives in a different part of the envelope from the
  /// rest of the listing and every caller would otherwise have to remember.
  Future<Listing> detail(String slug, {CancelToken? cancelToken}) {
    return _api.get<Listing>(
      '/listings/$slug',
      cancelToken: cancelToken,
      parse: (dynamic body) {
        final Map<String, dynamic> json = asMap(body);
        final bool? favorited = json['meta'] == null
            ? null
            : asBool(asMap(json['meta'])['is_favorited']);
        final Listing? listing =
            Listing.tryParse(json['data'], isFavorited: favorited);
        if (listing == null) throw StateError('listing not parseable');
        return listing;
      },
    );
  }

  Future<List<Listing>> similar(String slug) {
    return _api.get<List<Listing>>(
      '/listings/$slug/similar',
      parse: (dynamic body) => Listing.parseList(asMap(body)['data']),
    );
  }

  Future<Paginated<Review>> reviews(String slug, {int page = 1}) {
    return _api.get<Paginated<Review>>(
      '/listings/$slug/reviews',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Review>(body, Review.tryParse),
    );
  }

  /// The land polygon on a PUBLIC listing.
  ///
  /// Read from the listing detail response rather than from a boundary
  /// endpoint: the only boundary routes on this API are under `/seller`, which
  /// a customer cannot call. The detail resource already embeds it.
  Future<ListingBoundary?> boundary(String slug) {
    return _api.get<ListingBoundary?>(
      '/listings/$slug',
      parse: (dynamic body) => ListingBoundary.tryParse(
        asMap(asMap(body)['data'])['boundary'],
      ),
    );
  }

  Future<void> report({
    required String slug,
    required String reason,
    String? details,
  }) {
    return _api.post<void>(
      '/listings/$slug/report',
      body: <String, dynamic>{
        'reason': reason,
        if (details != null && details.isNotEmpty) 'details': details,
      },
      parse: (_) {},
    );
  }

  /// An inquiry — the honest primitive behind "Message seller". This backend
  /// has no realtime chat, and the app does not pretend otherwise.
  Future<void> inquire({
    required String listingSlug,
    required String name,
    required String phone,
    String? email,
    required String message,
  }) {
    return _api.post<void>(
      '/inquiries',
      body: <String, dynamic>{
        'listing_slug': listingSlug,
        'name': name,
        'phone': phone,
        if (email != null && email.isNotEmpty) 'email': email,
        'message': message,
      },
      parse: (_) {},
    );
  }

  Future<List<SearchSuggestion>> suggestions(
    String query, {
    CancelToken? cancelToken,
  }) {
    return _api.get<List<SearchSuggestion>>(
      '/search/suggestions',
      query: <String, dynamic>{'q': query},
      cancelToken: cancelToken,
      // Never deduplicated: each keystroke is a different question and carries
      // its own cancel token.
      deduplicate: false,
      parse: SearchSuggestion.parseAll,
    );
  }

  Future<List<String>> popularSearches() {
    return _api.get<List<String>>(
      '/search/popular',
      parse: (dynamic body) => <String>[
        for (final Map<String, dynamic> item in asMapList(asMap(body)['data']))
          if (asString(item['query']) case final String q) q,
      ],
    );
  }
}

/// Which curated rail to fetch.
///
/// Named `...Kind` rather than `ListingRail` because the widget that RENDERS a
/// rail owns that name; two public `ListingRail` symbols in one import graph is
/// an ambiguous-import error at every call site.
enum ListingRailKind {
  featured('featured'),
  trending('trending'),
  recommended('recommended');

  const ListingRailKind(this.path);

  final String path;
}
