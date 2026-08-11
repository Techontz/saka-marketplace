import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/boundary.dart';
import 'boundary_map.dart';

/// The parcel, for a buyer.
///
/// Strictly read-only. There is no edit affordance anywhere on this screen and
/// the map is constructed with `isEditable: false`, so a tap pans rather than
/// adding a corner. A buyer editing the seller's claimed boundary would be a
/// data-integrity problem, not a feature.
class BoundaryViewerScreen extends StatelessWidget {
  const BoundaryViewerScreen({
    required this.boundary,
    required this.title,
    super.key,
  });

  final ListingBoundary boundary;
  final String title;

  Future<void> _openInMaps() async {
    // Centre the external map on the parcel's own centroid, which the server
    // computed — not on the first corner, which is an arbitrary edge point.
    final double lat = boundary.centroid?.latitude ??
        boundary.outerRing.first.latitude;
    final double lng = boundary.centroid?.longitude ??
        boundary.outerRing.first.longitude;

    final Uri uri = Uri.parse(
      'https://www.google.com/maps/search/?api=1&query=$lat,$lng',
    );
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Land boundary')),
      body: Column(
        children: <Widget>[
          Expanded(
            child: BoundaryMap(points: boundary.outerRing),
          ),
          Container(
            width: double.infinity,
            decoration: const BoxDecoration(
              color: AppColors.background,
              border: Border(top: AppBorders.hairline),
            ),
            padding: EdgeInsets.fromLTRB(
              AppSpacing.screen,
              AppSpacing.lg,
              AppSpacing.screen,
              AppSpacing.lg + MediaQuery.paddingOf(context).bottom,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.label,
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: <Widget>[
                    // The server's own figures and the server's own formatting,
                    // so the app and the website never disagree about a plot.
                    Expanded(
                      child: _Metric(
                        label: 'Area',
                        value: boundary.areaDisplay,
                        detail: '${Fmt.thousands(boundary.areaSqm)} m²',
                      ),
                    ),
                    Expanded(
                      child: _Metric(
                        label: 'Perimeter',
                        value: boundary.perimeterDisplay,
                        detail: '${boundary.vertexCount} corners',
                      ),
                    ),
                  ],
                ),
                if (boundary.surveyReference != null) ...<Widget>[
                  const SizedBox(height: AppSpacing.md),
                  _Metric(
                    label: 'Survey reference',
                    value: boundary.surveyReference!,
                  ),
                ],
                if (boundary.notes != null) ...<Widget>[
                  const SizedBox(height: AppSpacing.sm),
                  Text(boundary.notes!, style: AppTypography.bodySmall),
                ],
                const SizedBox(height: AppSpacing.lg),
                OutlinedButton.icon(
                  onPressed: _openInMaps,
                  icon: const Icon(Icons.directions_outlined, size: 18),
                  label: const Text('Open in Maps'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, this.detail});

  final String label;
  final String value;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(label, style: AppTypography.caption),
        const SizedBox(height: 2),
        Text(value, style: AppTypography.title.copyWith(fontSize: 17)),
        if (detail != null)
          Text(
            detail!,
            style: AppTypography.caption.copyWith(fontSize: 11),
          ),
      ],
    );
  }
}
