import 'dart:math' show Point;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/pressable.dart';
import 'map_layers.dart';

/// The parcel, drawn on a basemap.
///
/// One widget for both audiences. `isEditable` decides whether taps add corners
/// and handles drag — a customer gets exactly the same geometry with no way to
/// touch it, which is the requirement: the boundary is the seller's claim about
/// their own land, not something a buyer may edit.
class BoundaryMap extends StatefulWidget {
  const BoundaryMap({
    required this.points,
    super.key,
    this.isEditable = false,
    this.onPointsChanged,
    this.onVertexTapped,
    this.height,
    this.initialLayer = SakaMapLayer.satellite,
    this.fallbackCentre,
    this.showLayerSwitch = true,
  });

  /// The open ring — no repeated closing point. [ListingBoundary.toRings]
  /// closes it on the way out to the API.
  final List<LatLng> points;

  final bool isEditable;
  final ValueChanged<List<LatLng>>? onPointsChanged;
  final ValueChanged<int>? onVertexTapped;
  final double? height;

  /// Satellite by default. A land parcel is drawn against what is actually on
  /// the ground — a hedge, a track, a neighbouring roof — none of which appear
  /// on a street map.
  final SakaMapLayer initialLayer;

  final LatLng? fallbackCentre;
  final bool showLayerSwitch;

  @override
  State<BoundaryMap> createState() => _BoundaryMapState();
}

class _BoundaryMapState extends State<BoundaryMap> {
  final MapController _map = MapController();
  late SakaMapLayer _layer = widget.initialLayer;

  /// Index of the corner being dragged, if any.
  int? _dragging;

  bool _ready = false;

  @override
  void dispose() {
    // flutter_map's MapController owns a broadcast stream of camera events.
    // Left undisposed, every visit to a listing with a parcel leaks one — and
    // the boundary preview is embedded in a scrolling detail page, so a user
    // browsing plots accumulates them.
    _map.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(BoundaryMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Re-frame when the shape changes from outside — after a load, or an undo.
    if (_ready &&
        oldWidget.points.length != widget.points.length &&
        widget.points.length >= 3 &&
        oldWidget.points.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) => fitToParcel());
    }
  }

  /// Frame the whole parcel with a margin.
  ///
  /// Public so the editor's "fit" control and the initial load share one
  /// implementation rather than each computing its own padding.
  void fitToParcel() {
    if (widget.points.length < 2 || !_ready) return;
    _map.fitCamera(
      CameraFit.coordinates(
        coordinates: widget.points,
        // Generous, and asymmetric at the bottom: the editor's toolbar sits
        // over the map there, and a corner hidden behind it cannot be dragged.
        padding: EdgeInsets.fromLTRB(
          48,
          48,
          48,
          widget.isEditable ? 120 : 48,
        ),
        maxZoom: 19,
      ),
    );
  }

  LatLng get _centre {
    if (widget.points.isNotEmpty) {
      double lat = 0;
      double lng = 0;
      for (final LatLng p in widget.points) {
        lat += p.latitude;
        lng += p.longitude;
      }
      return LatLng(lat / widget.points.length, lng / widget.points.length);
    }
    return widget.fallbackCentre ??
        const LatLng(kDefaultLatitude, kDefaultLongitude);
  }

  void _addPoint(LatLng point) {
    if (!widget.isEditable) return;
    widget.onPointsChanged?.call(<LatLng>[...widget.points, point]);
  }

  void _movePoint(int index, LatLng point) {
    if (!widget.isEditable) return;
    final List<LatLng> next = List<LatLng>.of(widget.points);
    if (index < 0 || index >= next.length) return;
    next[index] = point;
    widget.onPointsChanged?.call(next);
  }

  @override
  Widget build(BuildContext context) {
    final bool isClosed = widget.points.length >= 3;

    final Widget map = FlutterMap(
      mapController: _map,
      options: MapOptions(
        initialCenter: _centre,
        initialZoom: widget.points.isEmpty ? 15 : 17,
        maxZoom: _layer.maxZoom,
        minZoom: 3,
        onMapReady: () {
          _ready = true;
          if (widget.points.length >= 2) {
            WidgetsBinding.instance.addPostFrameCallback((_) => fitToParcel());
          }
        },
        onTap: widget.isEditable
            ? (TapPosition _, LatLng point) => _addPoint(point)
            : null,
        interactionOptions: InteractionOptions(
          // Rotation off: a rotated parcel is disorienting and there is no
          // compass control to get back to north.
          flags: InteractiveFlag.all & ~InteractiveFlag.rotate,
          // While a corner is being dragged the map must not pan underneath it,
          // or the corner "sticks" and the whole parcel slides away.
          enableMultiFingerGestureRace: _dragging == null,
        ),
      ),
      children: <Widget>[
        TileLayer(
          urlTemplate: _layer.url,
          // Required by the OSM tile usage policy: an anonymous client can be
          // blocked outright.
          userAgentPackageName: 'tz.co.saka.app',
          maxNativeZoom: _layer.maxZoom.toInt(),
          tileProvider: NetworkTileProvider(),
        ),

        // The shaded parcel. Drawn only once it is a real polygon — two points
        // are a line, and filling a line renders as a hairline artefact.
        if (isClosed)
          PolygonLayer<Object>(
            polygons: <Polygon<Object>>[
              Polygon<Object>(
                points: widget.points,
                color: AppColors.primary.withValues(alpha: 0.28),
                borderColor: AppColors.primary,
                borderStrokeWidth: 3,
              ),
            ],
          ),

        // Fewer than three corners: show the line so far, so the vendor can see
        // what they are building.
        if (widget.points.length == 2)
          PolylineLayer<Object>(
            polylines: <Polyline<Object>>[
              Polyline<Object>(
                points: widget.points,
                color: AppColors.primary,
                strokeWidth: 3,
              ),
            ],
          ),

        if (widget.isEditable)
          DragMarkers(
            markers: <DragMarker>[
              for (int i = 0; i < widget.points.length; i++)
                DragMarker(
                  key: ValueKey<int>(i),
                  point: widget.points[i],
                  index: i,
                  isDragging: _dragging == i,
                  onDragStart: () => setState(() => _dragging = i),
                  onDragEnd: () => setState(() => _dragging = null),
                  onDragUpdate: (LatLng point) => _movePoint(i, point),
                  onTap: () => widget.onVertexTapped?.call(i),
                ),
            ],
          )
        else if (isClosed)
          // Read-only: plain dots, no handles, nothing that invites a drag.
          MarkerLayer(
            markers: <Marker>[
              for (final LatLng point in widget.points)
                Marker(
                  point: point,
                  width: 12,
                  height: 12,
                  child: const DecoratedBox(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      border: Border.fromBorderSide(
                        BorderSide(color: AppColors.primary, width: 2.5),
                      ),
                    ),
                  ),
                ),
            ],
          ),

        // Attribution is a licence condition of every one of these tile
        // sources, not decoration.
        Align(
          alignment: Alignment.bottomLeft,
          child: Container(
            margin: const EdgeInsets.all(4),
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.78),
              borderRadius: BorderRadius.circular(3),
            ),
            child: Text(
              _layer.attribution,
              style: AppTypography.caption.copyWith(fontSize: 9, height: 1.2),
            ),
          ),
        ),
      ],
    );

    return SizedBox(
      height: widget.height,
      child: Stack(
        children: <Widget>[
          Positioned.fill(child: map),
          if (widget.showLayerSwitch)
            Positioned(
              top: AppSpacing.sm,
              right: AppSpacing.sm,
              child: _LayerSwitch(
                current: _layer,
                onChanged: (SakaMapLayer next) => setState(() {
                  _layer = next;
                  // Terrain caps at z17; staying at z19 would show grey tiles.
                  if (_map.camera.zoom > next.maxZoom) {
                    _map.move(_map.camera.center, next.maxZoom);
                  }
                }),
              ),
            ),
          if (widget.points.length >= 2)
            Positioned(
              bottom: widget.isEditable ? 76 : AppSpacing.sm,
              right: AppSpacing.sm,
              child: _MapButton(
                icon: Icons.center_focus_strong_rounded,
                semanticLabel: 'Fit parcel to screen',
                onTap: fitToParcel,
              ),
            ),
        ],
      ),
    );
  }
}

/// Street / Satellite / Terrain.
class _LayerSwitch extends StatelessWidget {
  const _LayerSwitch({required this.current, required this.onChanged});

  final SakaMapLayer current;
  final ValueChanged<SakaMapLayer> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.94),
        borderRadius: AppRadius.mdAll,
        boxShadow: AppShadows.floating,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          for (final SakaMapLayer layer in SakaMapLayer.values)
            PressableScale(
              onTap: () => onChanged(layer),
              scale: 0.92,
              semanticLabel: layer.label,
              child: Container(
                width: AppSizes.minTouchTarget,
                height: AppSizes.minTouchTarget,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: layer == current
                      ? AppColors.primary.withValues(alpha: 0.12)
                      : Colors.transparent,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(
                  layer.icon,
                  size: 19,
                  color: layer == current
                      ? AppColors.primary
                      : AppColors.mutedForeground,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _MapButton extends StatelessWidget {
  const _MapButton({
    required this.icon,
    required this.onTap,
    this.semanticLabel,
  });

  final IconData icon;
  final VoidCallback onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: semanticLabel,
      child: Container(
        width: AppSizes.minTouchTarget,
        height: AppSizes.minTouchTarget,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.94),
          borderRadius: AppRadius.mdAll,
          boxShadow: AppShadows.floating,
        ),
        child: Icon(icon, size: 19, color: AppColors.navy),
      ),
    );
  }
}

/// A draggable corner handle.
///
/// flutter_map's MarkerLayer has no drag support, so the handles are positioned
/// manually against the current camera and moved by converting screen deltas
/// back to coordinates. That conversion is the whole trick: dragging must move
/// the corner to where the FINGER is, not by a fixed latitude delta, or the
/// handle drifts away from the touch as the map zooms.
class DragMarkers extends StatelessWidget {
  const DragMarkers({required this.markers, super.key});

  final List<DragMarker> markers;

  @override
  Widget build(BuildContext context) {
    final MapCamera camera = MapCamera.of(context);

    return Stack(
      children: <Widget>[
        for (final DragMarker marker in markers)
          _PositionedDragMarker(marker: marker, camera: camera),
      ],
    );
  }
}

class DragMarker {
  const DragMarker({
    required this.key,
    required this.point,
    required this.index,
    required this.isDragging,
    required this.onDragStart,
    required this.onDragEnd,
    required this.onDragUpdate,
    required this.onTap,
  });

  final Key key;
  final LatLng point;
  final int index;
  final bool isDragging;
  final VoidCallback onDragStart;
  final VoidCallback onDragEnd;
  final ValueChanged<LatLng> onDragUpdate;
  final VoidCallback onTap;
}

class _PositionedDragMarker extends StatelessWidget {
  const _PositionedDragMarker({required this.marker, required this.camera});

  final DragMarker marker;
  final MapCamera camera;

  static const double _size = AppSizes.minTouchTarget;

  @override
  Widget build(BuildContext context) {
    // flutter_map 7 exposes the projection as `latLngToScreenPoint`, which
    // returns a math Point rather than an Offset.
    final Point<double> projected = camera.latLngToScreenPoint(marker.point);
    final Offset position = Offset(projected.x, projected.y);

    return Positioned(
      left: position.dx - _size / 2,
      top: position.dy - _size / 2,
      width: _size,
      height: _size,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: marker.onTap,
        onPanStart: (_) => marker.onDragStart(),
        onPanEnd: (_) => marker.onDragEnd(),
        onPanCancel: marker.onDragEnd,
        onPanUpdate: (DragUpdateDetails details) {
          // Screen position → coordinates, every frame. The corner lands under
          // the finger at any zoom, which a latitude-delta approach cannot do.
          final Point<double> current =
              camera.latLngToScreenPoint(marker.point);
          final Offset next =
              Offset(current.x, current.y) + details.delta;
          marker.onDragUpdate(camera.offsetToCrs(next));
        },
        child: Center(
          child: AnimatedContainer(
            duration: AppMotion.instant,
            width: marker.isDragging ? 26 : 20,
            height: marker.isDragging ? 26 : 20,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(
                color: marker.isDragging
                    ? AppColors.orange
                    : AppColors.primary,
                width: 3,
              ),
              boxShadow: AppShadows.floating,
            ),
            alignment: Alignment.center,
            child: Text(
              '${marker.index + 1}',
              style: AppTypography.overline.copyWith(
                color: AppColors.navy,
                fontSize: marker.isDragging ? 10 : 8.5,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
