import 'dart:convert';

import 'package:hive_ce_flutter/hive_flutter.dart';

/// Non-sensitive read cache.
///
/// This is what makes a cold start paint content instead of a spinner: the home
/// payload, the taxonomy and the user's own preferences are read synchronously
/// from an already-open Hive box during the first build, then refreshed in the
/// background.
///
/// Deliberately NOT stored here: session tokens (see SecureStore) and anything
/// derived from an identity document. Hive is unencrypted on disk.
class CacheStore {
  CacheStore._(this._box);

  final Box<String> _box;

  static const String _boxName = 'saka_cache';

  static Future<CacheStore> open() async {
    await Hive.initFlutter();
    final Box<String> box = await Hive.openBox<String>(_boxName);
    return CacheStore._(box);
  }

  // --- keys ----------------------------------------------------------------
  //
  // Namespaced so a `clearContent()` can drop cached API payloads while leaving
  // the user's own choices — location, layout — untouched. Signing out should
  // not forget which district somebody browses.

  static const String kCategories = 'content.categories';
  static const String kHomeFeatured = 'content.home.featured';
  static const String kHomeTrending = 'content.home.trending';
  static const String kRegions = 'content.regions';
  static const String kPublicSettings = 'content.settings';
  static const String kBusinessTypes = 'content.business_types';

  static const String kRecentSearches = 'prefs.recent_searches';
  static const String kListingLayout = 'prefs.listing_layout';
  static const String kLocation = 'prefs.location';
  static const String kLocationPrompted = 'prefs.location_prompted';
  static const String kRecentlyViewed = 'prefs.recently_viewed';

  /// Read a cached JSON payload, with its age.
  ///
  /// The age is returned rather than enforced here because "how stale is too
  /// stale" is a per-feature decision: the taxonomy is good for a day, the home
  /// rails for minutes. A store that silently drops data at a global TTL would
  /// take that judgement away from the caller.
  ({dynamic value, Duration age})? readJson(String key) {
    final String? raw = _box.get(key);
    if (raw == null) return null;

    try {
      final dynamic decoded = jsonDecode(raw);
      if (decoded is! Map) return null;
      final int? at = decoded['at'] as int?;
      if (at == null) return null;
      return (
        value: decoded['v'],
        age: Duration(
          milliseconds: DateTime.now().millisecondsSinceEpoch - at,
        ),
      );
    } on FormatException {
      // A corrupt entry is dropped rather than thrown: a cache must never be
      // able to stop the app from starting.
      _box.delete(key);
      return null;
    }
  }

  /// Read only if younger than [maxAge]; otherwise null.
  dynamic readFresh(String key, Duration maxAge) {
    final ({dynamic value, Duration age})? hit = readJson(key);
    if (hit == null || hit.age > maxAge) return null;
    return hit.value;
  }

  Future<void> writeJson(String key, dynamic value) {
    return _box.put(
      key,
      jsonEncode(<String, dynamic>{
        'at': DateTime.now().millisecondsSinceEpoch,
        'v': value,
      }),
    );
  }

  String? readString(String key) => _box.get(key);

  Future<void> writeString(String key, String value) => _box.put(key, value);

  bool readBool(String key, {bool fallback = false}) {
    final String? raw = _box.get(key);
    if (raw == null) return fallback;
    return raw == 'true';
  }

  Future<void> writeBool(String key, {required bool value}) =>
      _box.put(key, value ? 'true' : 'false');

  Future<void> delete(String key) => _box.delete(key);

  /// Drops cached API payloads, keeping the user's preferences. Called on sign
  /// out so the next account does not briefly see the previous one's content.
  Future<void> clearContent() async {
    final List<String> keys = _box.keys
        .whereType<String>()
        .where((String k) => k.startsWith('content.'))
        .toList(growable: false);
    await _box.deleteAll(keys);
  }
}
