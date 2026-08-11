import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:get/get.dart';
import 'package:latlong2/latlong.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/saka_image.dart';
import '../../core/widgets/states.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../../data/repositories/listing_repository.dart';
import '../boundary/map_layers.dart';

/// Listings on a map.
///
/// The app had exactly one map — the land-boundary viewer, reachable only from
/// a plot's detail page. On a marketplace where property and land are the two
/// largest verticals, "where is it" is the question people open the app with,
/// so this makes the map a destination rather than a footnote.
///
/// Everything on screen comes from the API. A listing without coordinates is
/// simply not plotted: there is no fallback pin, no jitter and no centring on
/// Dar "for now". A marker in the wrong place sends someone across a city.
class MapDiscoveryScreen extends StatefulWidget {
  const MapDiscoveryScreen({
    this.initialQuery = const ListingQuery(),
    this.title = 'Map',
    super.key,
  });

  final ListingQuery initialQuery;
  final String title;

  @override
  State<MapDiscoveryScreen> createState() => _MapDiscoveryScreenState();
}

class _MapDiscoveryScreenState extends State<MapDiscoveryScreen> {
  final MapController _map = MapController();
  final ListingRepository _listings = Get.find<ListingRepository>();

  List<Listing> _pins = <Listing>[];
  Listing? _selected;
  ApiException? _error;
  bool _isLoading = true;
  SakaMapLayer _layer = SakaMapLayer.street;

  /// Set once the first frame with a real size has been laid out. `fitCamera`
  /// before that throws, because the camera has no viewport to fit against.
  bool _hasFitted = false;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _map.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      // A larger page than the list view: markers are cheap and a map with
      // eight pins on it looks like the country is empty.
      final List<Listing> items =
          (await _listings.search(widget.initialQuery, perPage: 60)).items;
      if (!mounted) return;
      setState(() {
        _pins = items.where((Listing l) => l.location.hasCoordinates).toList();
        _isLoading = false;
      });
      _fitToPins();
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _isLoading = false;
      });
    }
  }

  /// Frame every marker, rather than centring on a hardcoded city.
  ///
  /// The same screen then works for a Mwanza or Arusha catalogue without anyone
  /// remembering to change a constant.
  void _fitToPins() {
    if (_pins.isEmpty || _hasFitted) return;
    final List<LatLng> points = _pins
        .map((Listing l) => LatLng(l.location.latitude!, l.location.longitude!))
        .toList(growable: false);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _map.fitCamera(
        CameraFit.coordinates(
          coordinates: points,
          padding: const EdgeInsets.all(56),
          maxZoom: 15,
        ),
      );
      _hasFitted = true;
    });
  }

  void _openSelected() {
    final Listing? listing = _selected;
    if (listing == null) return;
    Get.toNamed<void>(
      Routes.listingPath(listing.slug),
      arguments: <String, dynamic>{'listing': listing, 'heroPrefix': 'map'},
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: Text(widget.title)),
      body: Stack(
        children: <Widget>[
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              // A neutral, wide starting frame that is replaced by fitCamera as
              // soon as pins arrive. Not presented as the user's location.
              initialCenter: const LatLng(-6.4, 35.0),
              initialZoom: 5.2,
              onTap: (_, _) => setState(() => _selected = null),
              interactionOptions: const InteractionOptions(
                flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
              ),
            ),
            children: <Widget>[
              TileLayer(
                urlTemplate: _layer.url,
                maxNativeZoom: _layer.maxZoom.round(),
                userAgentPackageName: 'tz.co.saka.app',
              ),
              MarkerLayer(
                markers: <Marker>[
                  for (final Listing listing in _pins)
                    Marker(
                      point: LatLng(listing.location.latitude!, listing.location.longitude!),
                      width: 74,
                      height: 34,
                      child: _PriceMarker(
                        listing: listing,
                        isSelected: _selected?.slug == listing.slug,
                        onTap: () => setState(() => _selected = listing),
                      ),
                    ),
                ],
              ),
              // Attribution is a licence condition of every layer above, not a
              // decoration — OSM and Esri both require it on screen.
              RichAttributionWidget(
                attributions: <SourceAttribution>[
                  TextSourceAttribution(_layer.attribution),
                ],
              ),
            ],
          ),

          Positioned(
            top: AppSpacing.md,
            right: AppSpacing.md,
            child: _LayerSwitcher(
              current: _layer,
              onChanged: (SakaMapLayer next) => setState(() => _layer = next),
            ),
          ),

          if (_isLoading)
            const Positioned.fill(
              child: ColoredBox(
                color: Color(0x66FFFFFF),
                child: Center(child: CircularProgressIndicator()),
              ),
            ),

          if (!_isLoading && _error != null)
            Positioned.fill(
              child: ColoredBox(
                color: AppColors.page,
                child: SakaErrorState(error: _error!, onRetry: _load),
              ),
            ),

          if (!_isLoading && _error == null && _pins.isEmpty)
            Positioned.fill(
              child: ColoredBox(
                color: AppColors.page,
                child: SakaEmptyState(
                  icon: Icons.map_outlined,
                  title: 'Nothing to map here',
                  message:
                      'None of these listings has a location yet. Try a wider '
                      'search, or browse them as a list.',
                  actionLabel: 'Browse as a list',
                  onAction: () => Navigator.of(context).pop(),
                ),
              ),
            ),

          if (_selected != null)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: _SelectedCard(
                listing: _selected!,
                onOpen: _openSelected,
                onClose: () => setState(() => _selected = null),
              ),
            ),
        ],
      ),
    );
  }
}

/// A price bubble, not a generic pin.
///
/// The price is the thing a buyer scans a map for, and a field of identical
/// teardrops makes them tap every one to find out.
class _PriceMarker extends StatelessWidget {
  const _PriceMarker({
    required this.listing,
    required this.isSelected,
    required this.onTap,
  });

  final Listing listing;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        alignment: Alignment.center,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.orange : AppColors.navy,
          borderRadius: BorderRadius.circular(AppRadius.pill),
          border: Border.all(color: Colors.white, width: 1.5),
          boxShadow: const <BoxShadow>[
            BoxShadow(color: Color(0x33000000), blurRadius: 4, offset: Offset(0, 2)),
          ],
        ),
        child: Text(
          Fmt.priceCompact(listing.price),
          style: AppTypography.caption.copyWith(
            color: Colors.white,
            fontWeight: FontWeight.w800,
          ),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    );
  }
}

class _LayerSwitcher extends StatelessWidget {
  const _LayerSwitcher({required this.current, required this.onChanged});

  final SakaMapLayer current;
  final ValueChanged<SakaMapLayer> onChanged;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.background,
      borderRadius: BorderRadius.circular(AppRadius.md),
      elevation: 2,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          for (final SakaMapLayer layer in SakaMapLayer.values)
            IconButton(
              onPressed: () => onChanged(layer),
              tooltip: layer.label,
              icon: Icon(
                layer.icon,
                size: 20,
                color: layer == current
                    ? AppColors.primary
                    : AppColors.mutedForeground,
              ),
            ),
        ],
      ),
    );
  }
}

/// The selected listing, as a card above the map.
class _SelectedCard extends StatelessWidget {
  const _SelectedCard({
    required this.listing,
    required this.onOpen,
    required this.onClose,
  });

  final Listing listing;
  final VoidCallback onOpen;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Material(
          color: AppColors.background,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          elevation: 6,
          child: InkWell(
            onTap: onOpen,
            borderRadius: BorderRadius.circular(AppRadius.lg),
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.sm),
              child: Row(
                children: <Widget>[
                  ClipRRect(
                    borderRadius: BorderRadius.circular(AppRadius.md),
                    child: SakaImage(
                      image: listing.displayImage,
                      size: MediaSize.thumb,
                      width: 72,
                      height: 72,
                      fit: BoxFit.cover,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(
                          Fmt.price(listing.price),
                          style: AppTypography.price,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(
                          listing.title,
                          style: AppTypography.cardTitle,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        if (listing.location.shortLabel.isNotEmpty)
                          Text(
                            listing.location.shortLabel,
                            style: AppTypography.caption,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                      ],
                    ),
                  ),
                  IconButton(
                    onPressed: onClose,
                    tooltip: 'Dismiss',
                    icon: const Icon(Icons.close_rounded, size: 20),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
