import 'dart:async';

import 'package:get/get.dart';

import '../../core/errors/api_exception.dart';
import '../../core/storage/cache_store.dart';
import '../../data/models/category.dart';
import '../../data/models/business.dart';
import '../../data/models/misc.dart';
import '../../data/models/listing.dart';
import '../../data/models/paginated.dart';
import '../../data/repositories/ads_repository.dart';
import '../../data/repositories/catalog_repository.dart';
import '../../data/repositories/directory_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../location/location_controller.dart';

/// The home screen's data.
///
/// Built around one rule: **the screen must never be blank.** Cached content is
/// published synchronously in `onInit` — before the first frame — and the
/// network refresh happens behind it. A user reopening the app sees their
/// categories and listings immediately, and the rails quietly update in place.
///
/// Each rail owns its own loading and error state. One slow endpoint degrades
/// one strip; it does not spinner the page.
class HomeController extends GetxController {
  HomeController({
    required CatalogRepository catalog,
    required DirectoryRepository directory,
    required ListingRepository listings,
    required AdsRepository ads,
    required LocationController location,
    required CacheStore cache,
  })  : _catalog = catalog,
        _directory = directory,
        _listings = listings,
        _ads = ads,
        _location = location,
        _cache = cache;

  final CatalogRepository _catalog;
  final DirectoryRepository _directory;
  final ListingRepository _listings;
  final AdsRepository _ads;
  final LocationController _location;
  final CacheStore _cache;

  final RxList<Category> categories = <Category>[].obs;
  final Rx<RailState> featured = RailState.idle().obs;
  final Rx<RailState> trending = RailState.idle().obs;
  final Rx<RailState> nearby = RailState.idle().obs;
  final RxList<AdCreative> ads = <AdCreative>[].obs;

  /// Businesses for the home rail.
  ///
  /// A plain list rather than a RailState: this section renders nothing at all
  /// when it is empty or still loading, so it has no skeleton and no error
  /// state to model. The directory is a secondary surface — a spinner for it
  /// above the fold would be noise.
  final RxList<Business> businesses = <Business>[].obs;

  /// Newest listings, and the specialist vertical. Both are ordinary
  /// `/listings` queries — there is no `/specialists` index endpoint, and
  /// `sort=newest` is one of the seven values the API actually allows.
  final Rx<RailState> newest = RailState.idle().obs;
  final Rx<RailState> specialists = RailState.idle().obs;

  /// Public places. Plain list, like businesses: the section disappears when
  /// empty rather than showing a skeleton for a secondary surface.
  final RxList<PublicPlace> places = <PublicPlace>[].obs;

  /// True only when there is genuinely nothing to show — no cache, first ever
  /// launch. This is the ONLY case that earns a skeleton screen.
  final RxBool isColdStart = true.obs;

  final RxBool isRefreshing = false.obs;

  /// The rails that stay cached between launches. `nearby` is excluded on
  /// purpose: it is a function of the chosen location, and serving yesterday's
  /// region under today's heading would be wrong rather than merely stale.
  static const Duration _railTtl = Duration(minutes: 30);

  @override
  void onInit() {
    super.onInit();
    _publishCache();
    unawaited(loadAll());

    // Re-fetch the location-dependent rail when the user changes where they
    // are browsing. The other rails are location-independent and are left
    // alone, so changing region does not blank the whole screen.
    ever<LocationOption?>(_location.regionRx, (_) => unawaited(_loadNearby()));
  }

  /// Paint from disk, synchronously.
  void _publishCache() {
    final List<Category>? cachedCategories = _catalog.cachedCategories();
    if (cachedCategories != null && cachedCategories.isNotEmpty) {
      categories.assignAll(cachedCategories);
      isColdStart.value = false;
    }

    final dynamic cachedFeatured =
        _cache.readFresh(CacheStore.kHomeFeatured, _railTtl);
    if (cachedFeatured != null) {
      final List<Listing> items = Listing.parseList(cachedFeatured);
      if (items.isNotEmpty) {
        featured.value = RailState.loaded(items);
        isColdStart.value = false;
      }
    }

    final dynamic cachedTrending =
        _cache.readFresh(CacheStore.kHomeTrending, _railTtl);
    if (cachedTrending != null) {
      final List<Listing> items = Listing.parseList(cachedTrending);
      if (items.isNotEmpty) trending.value = RailState.loaded(items);
    }
  }

  /// Pull everything, concurrently.
  ///
  /// `Future.wait` rather than sequential awaits: the rails are independent, and
  /// chaining them would make the slowest one the sum of all of them.
  Future<void> loadAll() async {
    isRefreshing.value = true;
    try {
      await Future.wait<void>(<Future<void>>[
        _loadCategories(),
        _loadRail(ListingRailKind.featured, featured, CacheStore.kHomeFeatured),
        _loadRail(ListingRailKind.trending, trending, CacheStore.kHomeTrending),
        _loadNearby(),
        _loadAds(),
        _loadBusinesses(),
        _loadNewest(),
        _loadSpecialists(),
        _loadPlaces(),
      ]);
    } finally {
      isRefreshing.value = false;
      isColdStart.value = false;
    }
  }

  /// The directory's first page, verified businesses first.
  ///
  /// Failure is silent and leaves the list empty, which removes the section.
  /// A home feed must not surface an error for a rail the user did not ask for.
  Future<void> _loadBusinesses() async {
    try {
      final Paginated<Business> page = await _directory.businesses();
      businesses.assignAll(page.items.take(10));
    } on Object {
      // Section stays hidden.
    }
  }

  /// The most recent listings across every vertical.
  Future<void> _loadNewest() async {
    await _loadQueryRail(
      const ListingQuery(sort: 'newest'),
      newest,
    );
  }

  /// The specialist vertical, which is a CATEGORY rather than an endpoint.
  Future<void> _loadSpecialists() async {
    await _loadQueryRail(
      const ListingQuery(
        categorySlug: DirectoryRepository.specialistsCategory,
        sort: 'newest',
      ),
      specialists,
    );
  }

  /// Shared by the two query-backed rails above.
  Future<void> _loadQueryRail(ListingQuery query, Rx<RailState> target) async {
    if (target.value.items.isEmpty) target.value = RailState.loading();
    try {
      final List<Listing> items =
          (await _listings.search(query, perPage: 10)).items;
      target.value = RailState.loaded(items);
    } on Object catch (error) {
      target.value = RailState.failed(ApiException.from(error));
    }
  }

  Future<void> _loadPlaces() async {
    try {
      final Paginated<PublicPlace> page = await _directory.places();
      places.assignAll(page.items.take(10));
    } on Object {
      // Section stays hidden.
    }
  }

  Future<void> _loadCategories() async {
    try {
      final List<Category> fresh = await _catalog.refreshCategories();
      if (fresh.isNotEmpty) categories.assignAll(fresh);
    } on Object {
      // The cached tree stays on screen. A failed taxonomy refresh must not
      // empty the category strip the user is looking at.
    }
  }

  Future<void> _loadRail(
    ListingRailKind rail,
    Rx<RailState> target,
    String cacheKey,
  ) async {
    if (target.value.items.isEmpty) target.value = RailState.loading();
    try {
      final List<Listing> items = await _listings.rail(rail);
      target.value = RailState.loaded(items);
      if (items.isNotEmpty) {
        await _cache.writeJson(cacheKey, _encode(items));
      }
    } on ApiException catch (error) {
      // Keep whatever is already displayed; only an empty rail shows the error.
      target.value = target.value.items.isEmpty
          ? RailState.failed(error)
          : target.value;
    } on Object {
      target.value = target.value.items.isEmpty
          ? RailState.failed(
              const ApiException(
                kind: ApiErrorKind.unknown,
                message: 'Could not load these listings.',
              ),
            )
          : target.value;
    }
  }

  /// Listings in the region the user chose.
  ///
  /// Falls back to the newest listings nationally when no location is set,
  /// rather than hiding the rail — a fresh install with no chosen region should
  /// still see something worth scrolling.
  Future<void> _loadNearby() async {
    if (nearby.value.items.isEmpty) nearby.value = RailState.loading();
    try {
      final ListingQuery query = ListingQuery(
        regionSlug: _location.regionSlug,
        districtSlug: _location.districtSlug,
        sort: 'newest',
      );
      final List<Listing> items =
          (await _listings.search(query, perPage: 10)).items;
      nearby.value = RailState.loaded(items);
    } on ApiException catch (error) {
      nearby.value =
          nearby.value.items.isEmpty ? RailState.failed(error) : nearby.value;
    } on Object {
      nearby.value = RailState.loaded(const <Listing>[]);
    }
  }

  /// SAKA's own campaign engine. Returns nothing far more often than not, and
  /// the strip renders nothing at all in that case — never a reserved grey box.
  Future<void> _loadAds() async {
    final List<AdCreative> creatives = await _ads.serve(
      placement: AdPlacements.homepageStrip,
      regionSlug: _location.regionSlug,
    );
    ads.assignAll(creatives);
  }

  /// Top-level verticals only, and only those with something in them.
  ///
  /// The seeded taxonomy contains an empty `tourism` vertical; sending a user
  /// into a category with zero listings is a dead end, so it is not offered.
  List<Category> get topCategories => categories
      .where((Category c) => c.depth == 0 && c.listingCount > 0)
      .toList(growable: false);

  static List<Map<String, dynamic>> _encode(List<Listing> listings) {
    // Only the fields a card draws are cached. Storing the full detail payload
    // would put descriptions and every image variant into the Hive box for a
    // strip that shows a thumbnail and a price.
    return <Map<String, dynamic>>[
      for (final Listing l in listings)
        <String, dynamic>{
          'uuid': l.uuid,
          'slug': l.slug,
          'title': l.title,
          'price': <String, dynamic>{
            'amount': l.price.amount,
            'currency': l.price.currency,
            'unit': l.price.unit,
            'is_negotiable': l.price.isNegotiable,
          },
          'purpose': l.purpose,
          'is_verified': l.isVerified,
          'is_featured': l.isFeatured,
          'location': <String, dynamic>{
            'region': l.location.region,
            'district': l.location.district,
            'ward': l.location.ward,
          },
          'primary_image': l.displayImage == null
              ? null
              : <String, dynamic>{
                  'url': l.displayImage!.url,
                  'variants': l.displayImage!.variants,
                  'alt_text': l.displayImage!.altText,
                },
          'attributes': <String, dynamic>{
            for (final ListingAttribute a in l.attributes.take(3))
              a.code: a.value,
          },
        },
    ];
  }
}

/// One rail's state.
///
/// A sealed-ish value rather than three separate observables, so a widget
/// cannot render "loading" and "error" at once — a bug that is otherwise very
/// easy to write and very hard to see.
class RailState {
  const RailState._({
    required this.items,
    required this.isLoading,
    this.error,
  });

  factory RailState.idle() =>
      const RailState._(items: <Listing>[], isLoading: false);

  factory RailState.loading() =>
      const RailState._(items: <Listing>[], isLoading: true);

  factory RailState.loaded(List<Listing> items) =>
      RailState._(items: items, isLoading: false);

  factory RailState.failed(ApiException error) =>
      RailState._(items: const <Listing>[], isLoading: false, error: error);

  final List<Listing> items;
  final bool isLoading;
  final ApiException? error;

  bool get isEmpty => !isLoading && error == null && items.isEmpty;
}
