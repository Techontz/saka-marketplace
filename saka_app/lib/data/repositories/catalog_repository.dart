import '../../core/network/api_client.dart';
import '../../core/storage/cache_store.dart';
import '../models/category.dart';
import '../models/json.dart';
import '../models/misc.dart';

/// Reference data: taxonomy, locations, public settings.
///
/// All of it changes when an administrator edits it — which is to say, rarely —
/// and all of it is needed before the first screen can render. It is therefore
/// the one part of the API this app caches aggressively and serves from disk
/// first, refreshing behind the user.
class CatalogRepository {
  CatalogRepository({required ApiClient api, required CacheStore cache})
      : _api = api,
        _cache = cache;

  final ApiClient _api;
  final CacheStore _cache;

  /// A day. The taxonomy is edited through the admin portal and a stale entry
  /// costs the user a missing subcategory, not a wrong price.
  static const Duration _taxonomyTtl = Duration(hours: 24);
  static const Duration _settingsTtl = Duration(hours: 6);

  /// The category tree, from disk if it is there.
  ///
  /// Returns immediately with cached data when possible; [refreshCategories]
  /// is what goes to the network. Splitting them is what lets the home screen
  /// paint chips in the first frame instead of after a round trip.
  List<Category>? cachedCategories() {
    final dynamic raw = _cache.readFresh(CacheStore.kCategories, _taxonomyTtl);
    if (raw == null) return null;
    final List<Category> parsed = Category.parseList(raw);
    return parsed.isEmpty ? null : parsed;
  }

  Future<List<Category>> refreshCategories() async {
    final List<Category> categories = await _api.get<List<Category>>(
      '/categories',
      parse: (dynamic body) => Category.parseList(asMap(body)['data']),
    );

    if (categories.isNotEmpty) {
      await _cache.writeJson(
        CacheStore.kCategories,
        categories.map((Category c) => c.toJson()).toList(growable: false),
      );
    }
    return categories;
  }

  Future<Category?> category(String slug) {
    return _api.get<Category?>(
      '/categories/$slug',
      parse: (dynamic body) => Category.tryParse(asMap(body)['data']),
    );
  }

  /// The filterable attributes for a category.
  ///
  /// This is the whole basis of the category-aware filter sheet — nothing in
  /// this app hardcodes "property has bedrooms".
  Future<List<CategoryAttribute>> attributes(String categorySlug) {
    return _api.get<List<CategoryAttribute>>(
      '/categories/$categorySlug/attributes',
      parse: (dynamic body) => CategoryAttribute.parseList(asMap(body)['data']),
    );
  }

  // --- locations -----------------------------------------------------------

  List<LocationOption>? cachedRegions() {
    final dynamic raw = _cache.readFresh(CacheStore.kRegions, _taxonomyTtl);
    if (raw == null) return null;
    final List<LocationOption> parsed = LocationOption.parseList(raw);
    return parsed.isEmpty ? null : parsed;
  }

  Future<List<LocationOption>> regions() async {
    final List<LocationOption> regions = await _api.get<List<LocationOption>>(
      '/locations/regions',
      parse: (dynamic body) => LocationOption.parseList(asMap(body)['data']),
    );
    if (regions.isNotEmpty) {
      await _cache.writeJson(
        CacheStore.kRegions,
        regions.map((LocationOption r) => r.toJson()).toList(growable: false),
      );
    }
    return regions;
  }

  Future<List<LocationOption>> districts(String regionSlug) {
    return _api.get<List<LocationOption>>(
      '/locations/regions/$regionSlug/districts',
      parse: (dynamic body) =>
          LocationOption.parseList(asMap(body)['data'], type: 'district'),
    );
  }

  Future<List<LocationOption>> wards(String districtSlug) {
    return _api.get<List<LocationOption>>(
      '/locations/districts/$districtSlug/wards',
      parse: (dynamic body) =>
          LocationOption.parseList(asMap(body)['data'], type: 'ward'),
    );
  }

  /// Free-text location lookup, for the "Kin…" → "Kinondoni" experience.
  Future<List<LocationOption>> searchLocations(String query) {
    return _api.get<List<LocationOption>>(
      '/locations/search',
      query: <String, dynamic>{'q': query},
      parse: (dynamic body) => LocationOption.parseList(asMap(body)['data']),
    );
  }

  // --- public settings -----------------------------------------------------

  /// Feature flags and contact details the operator controls.
  ///
  /// `features.reviews_enabled`, `features.messaging_enabled` and
  /// `features.payments_enabled` are read by the UI so a switch flipped in the
  /// admin portal takes effect in the app without a release.
  Future<Map<String, dynamic>> publicSettings() async {
    final dynamic cached =
        _cache.readFresh(CacheStore.kPublicSettings, _settingsTtl);
    if (cached is Map) return cached.cast<String, dynamic>();

    final Map<String, dynamic> settings = await _api.get<Map<String, dynamic>>(
      '/settings/public',
      parse: (dynamic body) => asMap(asMap(body)['data']),
    );
    await _cache.writeJson(CacheStore.kPublicSettings, settings);
    return settings;
  }

  Future<List<AttributeOption>> businessTypes() {
    return _api.get<List<AttributeOption>>(
      '/business-types',
      parse: (dynamic body) => <AttributeOption>[
        for (final Map<String, dynamic> item in asMapList(asMap(body)['data']))
          if (AttributeOption.tryParse(item) case final AttributeOption o) o,
      ],
    );
  }

  Future<List<AttributeOption>> reportReasons() {
    return _api.get<List<AttributeOption>>(
      '/listing-report-reasons',
      parse: (dynamic body) => <AttributeOption>[
        for (final Map<String, dynamic> item in asMapList(asMap(body)['data']))
          if (AttributeOption.tryParse(item) case final AttributeOption o) o,
      ],
    );
  }
}
