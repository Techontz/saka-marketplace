import 'package:flutter/material.dart';

/// The three basemaps, matching `MAP_LAYERS` in the web's `lib/config.ts`
/// exactly — same hosts, same zoom caps, same attribution.
///
/// A vendor drawing a parcel on their phone must see the same imagery they see
/// on saka.africa, or the two will disagree about where a fence is.
enum SakaMapLayer {
  street(
    label: 'Map',
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
    icon: Icons.map_outlined,
  ),

  /// Esri World Imagery. `{z}/{y}/{x}` — note the y/x order, which is Esri's
  /// and not the usual slippy-map order.
  satellite(
    label: 'Satellite',
    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/'
        'World_Imagery/MapServer/tile/{z}/{y}/{x}',
    attribution: 'Imagery © Esri, Maxar, Earthstar Geographics',
    maxZoom: 19,
    icon: Icons.satellite_alt_outlined,
  ),

  /// OpenTopoMap stops serving above z17; the cap is theirs, not ours.
  terrain(
    label: 'Terrain',
    url: 'https://tile.opentopomap.org/{z}/{x}/{y}.png',
    attribution: '© OpenTopoMap (CC-BY-SA), © OpenStreetMap contributors',
    maxZoom: 17,
    icon: Icons.terrain_outlined,
  );

  const SakaMapLayer({
    required this.label,
    required this.url,
    required this.attribution,
    required this.maxZoom,
    required this.icon,
  });

  final String label;
  final String url;
  final String attribution;
  final double maxZoom;
  final IconData icon;
}

/// Dar es Salaam — the fallback centre, same as the web's `DEFAULT_CENTER`.
const double kDefaultLatitude = -6.7924;
const double kDefaultLongitude = 39.2083;
