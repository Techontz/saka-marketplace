import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';
import 'package:saka_app/data/models/boundary.dart';

/// The land boundary is the one place in this app where a coordinate-order
/// mistake is silent and catastrophic: swapping lat and lng puts a Dar es
/// Salaam plot in the Indian Ocean off Somalia. These tests pin the conversion
/// in both directions.
void main() {
  /// A real Masaki-shaped parcel, in the API's GeoJSON order: [lng, lat],
  /// closed ring.
  const List<List<double>> ring = <List<double>>[
    <double>[39.2810, -6.7420],
    <double>[39.2825, -6.7420],
    <double>[39.2825, -6.7432],
    <double>[39.2810, -6.7432],
    <double>[39.2810, -6.7420],
  ];

  Map<String, dynamic> payload() => <String, dynamic>{
        'rings': <dynamic>[ring],
        'area': <String, dynamic>{
          'sqm': 2216.4,
          'acres': 0.5477,
          'hectares': 0.2216,
          'display': '0.55 acres',
        },
        'perimeter_m': 599.2,
        'perimeter_display': '599 m',
        'vertex_count': 4,
        'centroid': <String, dynamic>{
          'latitude': -6.7426,
          'longitude': 39.28175,
        },
        'bounds': <String, dynamic>{
          'min_latitude': -6.7432,
          'max_latitude': -6.7420,
          'min_longitude': 39.2810,
          'max_longitude': 39.2825,
        },
        'survey_reference': 'Plot 214, Block C',
        'notes': null,
      };

  group('parsing', () {
    test('reads [lng, lat] and yields LatLng(lat, lng)', () {
      final ListingBoundary? boundary = ListingBoundary.tryParse(payload());

      expect(boundary, isNotNull);
      final LatLng first = boundary!.outerRing.first;

      // Latitude is the NEGATIVE one here — Dar es Salaam is south of the
      // equator and east of Greenwich. Reversed, this would be +39 lat.
      expect(first.latitude, closeTo(-6.7420, 1e-9));
      expect(first.longitude, closeTo(39.2810, 1e-9));
      expect(first.latitude, lessThan(0));
      expect(first.longitude, greaterThan(0));
    });

    test('drops the repeated closing point', () {
      final ListingBoundary boundary = ListingBoundary.tryParse(payload())!;
      // Five points on the wire, four distinct corners to render and drag.
      expect(boundary.outerRing, hasLength(4));
      expect(boundary.vertexCount, 4);
    });

    test('keeps the server measurement and formatting verbatim', () {
      final ListingBoundary boundary = ListingBoundary.tryParse(payload())!;
      expect(boundary.areaSqm, 2216.4);
      expect(boundary.areaDisplay, '0.55 acres');
      expect(boundary.perimeterDisplay, '599 m');
    });

    test('rejects out-of-range coordinates rather than plotting them', () {
      final ListingBoundary? boundary =
          ListingBoundary.tryParse(<String, dynamic>{
        'rings': <dynamic>[
          <dynamic>[
            <double>[39.28, -6.74],
            <double>[999.0, -6.74], // impossible longitude
            <double>[39.28, -6.75],
          ],
        ],
      });

      // Two valid points is not a polygon, so the whole boundary is refused
      // rather than rendered as a line across the map.
      expect(boundary, isNull);
    });

    test('a ring with fewer than three points is not a boundary', () {
      expect(
        ListingBoundary.tryParse(<String, dynamic>{
          'rings': <dynamic>[
            <dynamic>[
              <double>[39.28, -6.74],
              <double>[39.29, -6.74],
            ],
          ],
        }),
        isNull,
      );
    });

    test('an absent boundary parses to null, not an empty shape', () {
      expect(ListingBoundary.tryParse(null), isNull);
      expect(ListingBoundary.tryParse(<String, dynamic>{}), isNull);
    });
  });

  group('serialising back to the API', () {
    test('emits [lng, lat] and re-closes the ring', () {
      final List<LatLng> corners = <LatLng>[
        const LatLng(-6.7420, 39.2810),
        const LatLng(-6.7420, 39.2825),
        const LatLng(-6.7432, 39.2825),
      ];

      final List<List<List<double>>> rings =
          ListingBoundary.toRings(corners);

      expect(rings, hasLength(1));
      // Three corners in, four points out: the ring is closed.
      expect(rings.first, hasLength(4));

      // Longitude FIRST, as the backend's validator asserts.
      expect(rings.first.first[0], closeTo(39.2810, 1e-9));
      expect(rings.first.first[1], closeTo(-6.7420, 1e-9));

      expect(rings.first.last, equals(rings.first.first));
    });

    test('refuses to serialise fewer than three corners', () {
      expect(
        ListingBoundary.toRings(<LatLng>[const LatLng(-6.74, 39.28)]),
        isEmpty,
      );
    });

    test('round-trips without drifting', () {
      final ListingBoundary parsed = ListingBoundary.tryParse(payload())!;
      final List<List<List<double>>> out =
          ListingBoundary.toRings(parsed.outerRing);

      expect(out.first, hasLength(ring.length));
      for (int i = 0; i < ring.length; i++) {
        expect(out.first[i][0], closeTo(ring[i][0], 1e-9));
        expect(out.first[i][1], closeTo(ring[i][1], 1e-9));
      }
    });
  });
}
