import 'package:latlong2/latlong.dart';

import 'json.dart';

/// A mapped land parcel.
///
/// The wire format is GeoJSON: `rings` is a list of CLOSED rings, each ring a
/// list of `[longitude, latitude]` pairs with the first point repeated at the
/// end. Longitude first. Getting that order backwards puts every Tanzanian
/// parcel in the Indian Ocean off Somalia, which is exactly why the backend
/// asserts the ranges rather than assuming them — and why this class converts
/// in exactly one place, [_toLatLng], instead of at each call site.
class ListingBoundary {
  const ListingBoundary({
    required this.rings,
    required this.areaSqm,
    required this.areaAcres,
    required this.areaHectares,
    required this.areaDisplay,
    required this.perimeterMetres,
    required this.perimeterDisplay,
    required this.vertexCount,
    this.centroid,
    this.bounds,
    this.surveyReference,
    this.notes,
  });

  /// Outer ring first, in the app's own lat/lng order for rendering.
  final List<List<LatLng>> rings;

  /// Computed by the SERVER, never on the client. A phone that measures its own
  /// acreage and shows a different number from the website is worse than one
  /// that waits for the answer.
  final double areaSqm;
  final double areaAcres;
  final double areaHectares;

  /// The server's own formatting — "0.82 acres", "1,240 m²" — so the app and
  /// the website say the same words about the same parcel.
  final String areaDisplay;

  final double perimeterMetres;
  final String perimeterDisplay;
  final int vertexCount;

  final LatLng? centroid;
  final BoundaryBounds? bounds;
  final String? surveyReference;
  final String? notes;

  List<LatLng> get outerRing => rings.isEmpty ? const <LatLng>[] : rings.first;

  bool get isEmpty => rings.isEmpty || rings.first.length < 3;

  static ListingBoundary? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final dynamic rawRings = json['rings'];
    if (rawRings is! List || rawRings.isEmpty) return null;

    final List<List<LatLng>> rings = <List<LatLng>>[];
    for (final dynamic ring in rawRings) {
      final List<LatLng> points = _toLatLng(ring);
      if (points.length >= 3) rings.add(points);
    }
    if (rings.isEmpty) return null;

    final Map<String, dynamic> area = asMap(json['area']);
    final Map<String, dynamic> centroid = asMap(json['centroid']);
    final Map<String, dynamic> bounds = asMap(json['bounds']);

    return ListingBoundary(
      rings: rings,
      areaSqm: asDoubleOr(area['sqm'], 0),
      areaAcres: asDoubleOr(area['acres'], 0),
      areaHectares: asDoubleOr(area['hectares'], 0),
      areaDisplay: asStringOr(area['display'], '—'),
      perimeterMetres: asDoubleOr(json['perimeter_m'], 0),
      perimeterDisplay: asStringOr(json['perimeter_display'], '—'),
      vertexCount: asIntOr(json['vertex_count'], rings.first.length - 1),
      centroid: centroid['latitude'] == null
          ? null
          : LatLng(
              asDoubleOr(centroid['latitude'], 0),
              asDoubleOr(centroid['longitude'], 0),
            ),
      bounds: bounds['min_latitude'] == null
          ? null
          : BoundaryBounds(
              southWest: LatLng(
                asDoubleOr(bounds['min_latitude'], 0),
                asDoubleOr(bounds['min_longitude'], 0),
              ),
              northEast: LatLng(
                asDoubleOr(bounds['max_latitude'], 0),
                asDoubleOr(bounds['max_longitude'], 0),
              ),
            ),
      surveyReference: asString(json['survey_reference']),
      notes: asString(json['notes']),
    );
  }

  /// GeoJSON `[lng, lat]` → `LatLng(lat, lng)`.
  ///
  /// The single point in this app where the two orders meet. A closing point
  /// identical to the first is dropped: it is required on the wire and would
  /// render as a duplicate draggable handle sitting exactly on top of another.
  static List<LatLng> _toLatLng(dynamic ring) {
    if (ring is! List) return const <LatLng>[];

    final List<LatLng> points = <LatLng>[];
    for (final dynamic pair in ring) {
      if (pair is! List || pair.length < 2) continue;
      final double? lng = asDouble(pair[0]);
      final double? lat = asDouble(pair[1]);
      if (lat == null || lng == null) continue;
      if (lat < -90 || lat > 90 || lng < -180 || lng > 180) continue;
      points.add(LatLng(lat, lng));
    }

    if (points.length > 1) {
      final LatLng first = points.first;
      final LatLng last = points.last;
      if ((first.latitude - last.latitude).abs() < 1e-9 &&
          (first.longitude - last.longitude).abs() < 1e-9) {
        points.removeLast();
      }
    }

    return points;
  }

  /// The wire payload for `PUT /seller/listings/{uuid}/boundary`.
  ///
  /// Re-closes the ring — the backend counts vertices as `length - 1` and its
  /// geometry checks assume a closed ring, so sending an open one is a
  /// silently-off-by-one area.
  static List<List<List<double>>> toRings(List<LatLng> points) {
    if (points.length < 3) return const <List<List<double>>>[];

    final List<List<double>> ring = <List<double>>[
      for (final LatLng point in points)
        <double>[point.longitude, point.latitude],
    ];
    ring.add(<double>[points.first.longitude, points.first.latitude]);

    return <List<List<double>>>[ring];
  }
}

/// The parcel's extent.
///
/// A plain value type rather than flutter_map's `LatLngBounds`, so the data
/// layer carries no dependency on the map package — a repository should be
/// testable without a rendering library, and the map screen converts at the
/// edge.
class BoundaryBounds {
  const BoundaryBounds({required this.southWest, required this.northEast});

  final LatLng southWest;
  final LatLng northEast;

  LatLng get centre => LatLng(
        (southWest.latitude + northEast.latitude) / 2,
        (southWest.longitude + northEast.longitude) / 2,
      );
}
