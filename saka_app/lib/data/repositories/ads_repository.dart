import '../../core/network/api_client.dart';
import '../models/json.dart';
import '../models/misc.dart';

/// SAKA's own advertising, served by the Laravel campaign engine.
///
/// No third-party SDK. The web app loads AdSense only when a publisher id is
/// configured, and no such id exists for mobile — so this app carries no ad
/// network code at all rather than an inert integration.
class AdsRepository {
  AdsRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// Creatives eligible for a placement.
  ///
  /// Returns an EMPTY list far more often than not, and that is the normal
  /// case: the caller must render nothing at all rather than a reserved grey
  /// box. Category context matters — a campaign bought against `property` is
  /// not eligible on a page with no category, which is the backend's rule, not
  /// this app's.
  Future<List<AdCreative>> serve({
    required String placement,
    String? categorySlug,
    String? regionSlug,
  }) async {
    try {
      return await _api.get<List<AdCreative>>(
        '/ads',
        query: <String, dynamic>{
          'placement': placement,
          'category': categorySlug,
          'region': regionSlug,
        },
        parse: (dynamic body) => AdCreative.parseList(asMap(body)['data']),
      );
    } catch (_) {
      // An advertising failure must never break a screen. The slot collapses.
      return const <AdCreative>[];
    }
  }

  /// Fire-and-forget impression beacon.
  ///
  /// Called ONLY when a creative has actually been on screen — see AdSlot,
  /// which waits for a visibility threshold. Counting a render as a view would
  /// bill an advertiser for a slot the user scrolled past in a frame.
  void recordImpression(String creativeUuid, String placement) {
    _api
        .post<void>(
          '/ads/$creativeUuid/impression',
          body: <String, dynamic>{'placement': placement},
          parse: (_) {},
        )
        .catchError((_) {});
  }

  void recordClick(String creativeUuid, String placement) {
    _api
        .post<void>(
          '/ads/$creativeUuid/click',
          body: <String, dynamic>{'placement': placement},
          parse: (_) {},
        )
        .catchError((_) {});
  }
}

/// Placement identifiers, as the backend's `AdPlacement` enum defines them.
abstract final class AdPlacements {
  static const String homepageStrip = 'homepage_strip';
  static const String listingsInline = 'listings_inline';
  static const String businessDirectory = 'business_directory';

  const AdPlacements._();
}
