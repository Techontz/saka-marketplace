import 'dart:async';
import 'dart:convert';

import 'package:get/get.dart';

import '../../core/storage/cache_store.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/catalog_repository.dart';

/// Where the user is browsing.
///
/// Deliberately NOT a GPS wrapper. This app never asks for the OS location
/// permission — no screen in it currently needs coordinates that a chosen
/// region cannot supply, and prompting for GPS on launch to sort a listing feed
/// is exactly the pattern the SAKA web app avoids. The user picks a place; the
/// choice persists; nothing is asked of the operating system.
///
/// The `latitude`/`longitude` on a chosen region are the REGION's centroid,
/// supplied by the API — not the device's position.
class LocationController extends GetxController {
  LocationController({
    required CatalogRepository catalog,
    required CacheStore cache,
  })  : _catalog = catalog,
        _cache = cache;

  final CatalogRepository _catalog;
  final CacheStore _cache;

  final Rxn<LocationOption> _region = Rxn<LocationOption>();
  final Rxn<LocationOption> _district = Rxn<LocationOption>();
  final RxList<LocationOption> _regions = <LocationOption>[].obs;
  final RxBool _isLoading = false.obs;

  LocationOption? get region => _region.value;
  LocationOption? get district => _district.value;

  /// The observable, for controllers that must react to a location change
  /// rather than read the current value.
  Rxn<LocationOption> get regionRx => _region;
  List<LocationOption> get regions => _regions;
  bool get isLoading => _isLoading.value;

  bool get hasChoice => _region.value != null;

  /// Whether the location sheet has ever been shown. Used so a user who
  /// dismissed it is not asked again on every launch.
  bool get hasBeenPrompted => _cache.readBool(CacheStore.kLocationPrompted);

  /// "Kinondoni, Dar es Salaam", or the invitation to choose.
  String get label {
    final LocationOption? r = _region.value;
    final LocationOption? d = _district.value;
    if (r == null) return 'All Tanzania';
    if (d == null) return r.name;
    return '${d.name}, ${r.name}';
  }

  String? get regionSlug => _region.value?.slug;
  String? get districtSlug => _district.value?.slug;

  @override
  void onInit() {
    super.onInit();
    _restore();
    unawaited(loadRegions());
  }

  void _restore() {
    final String? raw = _cache.readString(CacheStore.kLocation);
    if (raw == null) return;
    try {
      final Map<String, dynamic> json =
          jsonDecode(raw) as Map<String, dynamic>;
      _region.value = LocationOption.tryParse(json['region']);
      _district.value = LocationOption.tryParse(json['district']);
    } on Object {
      // A corrupt preference is dropped rather than thrown — it must never stop
      // the app from starting.
      unawaited(_cache.delete(CacheStore.kLocation));
    }
  }

  /// Regions, from cache first.
  ///
  /// The list is 31 rows that change when Tanzania redraws a boundary. It is
  /// served from disk immediately and refreshed behind the user, so the
  /// location sheet opens instantly rather than spinning.
  Future<void> loadRegions() async {
    final List<LocationOption>? cached = _catalog.cachedRegions();
    if (cached != null) _regions.assignAll(cached);

    if (_regions.isNotEmpty) {
      // Refresh silently; a stale region list is harmless.
      unawaited(_refreshRegions());
      return;
    }

    _isLoading.value = true;
    try {
      await _refreshRegions();
    } finally {
      _isLoading.value = false;
    }
  }

  Future<void> _refreshRegions() async {
    try {
      final List<LocationOption> fresh = await _catalog.regions();
      if (fresh.isNotEmpty) _regions.assignAll(fresh);
    } on Object {
      // Keep whatever is on screen.
    }
  }

  Future<List<LocationOption>> districtsFor(String regionSlug) async {
    try {
      return await _catalog.districts(regionSlug);
    } on Object {
      return const <LocationOption>[];
    }
  }

  Future<List<LocationOption>> search(String query) async {
    if (query.trim().length < 2) return const <LocationOption>[];
    try {
      return await _catalog.searchLocations(query.trim());
    } on Object {
      return const <LocationOption>[];
    }
  }

  Future<void> choose({
    LocationOption? region,
    LocationOption? district,
  }) async {
    _region.value = region;
    // A district only means something inside its region; changing the region
    // must not leave the previous region's district attached to the query.
    _district.value = region == null ? null : district;
    await _persist();
  }

  Future<void> clear() async {
    _region.value = null;
    _district.value = null;
    await _cache.delete(CacheStore.kLocation);
  }

  Future<void> markPrompted() =>
      _cache.writeBool(CacheStore.kLocationPrompted, value: true);

  Future<void> _persist() async {
    await markPrompted();
    final LocationOption? r = _region.value;
    if (r == null) {
      await _cache.delete(CacheStore.kLocation);
      return;
    }
    await _cache.writeString(
      CacheStore.kLocation,
      jsonEncode(<String, dynamic>{
        'region': r.toJson(),
        'district': _district.value?.toJson(),
      }),
    );
  }
}
