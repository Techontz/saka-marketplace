import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../data/models/media.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/directory_repository.dart';
import '../../shared/widgets/paged_list.dart';
import '../location/location_controller.dart';

/// Hospitals, schools, banks, stations — the civic layer.
class PlacesScreen extends StatelessWidget {
  const PlacesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final DirectoryRepository repository = Get.find<DirectoryRepository>();
    final LocationController location = Get.find<LocationController>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Public places')),
      body: PagedList<PublicPlace>(
        fetch: (int page) => repository.places(
          page: page,
          regionSlug: location.regionSlug,
          districtSlug: location.districtSlug,
        ),
        emptyIcon: Icons.place_outlined,
        emptyTitle: 'No places listed here',
        emptyMessage: 'Try a wider location to see hospitals, schools and '
            'stations nearby.',
        itemBuilder: (BuildContext context, PublicPlace place, int _) =>
            _PlaceCard(place: place),
      ),
    );
  }
}

class _PlaceCard extends StatelessWidget {
  const _PlaceCard({required this.place});

  final PublicPlace place;

  Future<void> _directions() async {
    if (!place.location.hasCoordinates) return;
    final Uri uri = Uri.parse(
      'https://www.google.com/maps/search/?api=1&query='
      '${place.location.latitude},${place.location.longitude}',
    );
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (place.imageUrl != null)
            AspectRatio(
              aspectRatio: 16 / 7,
              child: SakaImage.url(
                url: place.imageUrl,
                size: MediaSize.card,
                fit: BoxFit.cover,
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    if (place.categoryIcon != null) ...<Widget>[
                      Text(
                        place.categoryIcon!,
                        style: const TextStyle(fontSize: 16, height: 1),
                      ),
                      const SizedBox(width: 6),
                    ],
                    Expanded(
                      child: Text(
                        place.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.cardTitle,
                      ),
                    ),
                  ],
                ),
                if (place.categoryName != null) ...<Widget>[
                  const SizedBox(height: 3),
                  Text(place.categoryName!, style: AppTypography.caption),
                ],
                const SizedBox(height: AppSpacing.sm),
                LocationRow(
                  label: place.location.addressLine ??
                      place.location.shortLabel,
                  compact: true,
                ),
                if (place.location.hasCoordinates) ...<Widget>[
                  const SizedBox(height: AppSpacing.md),
                  PressableScale(
                    onTap: _directions,
                    child: Container(
                      height: AppSizes.minTouchTarget,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: AppColors.muted,
                        borderRadius: AppRadius.mdAll,
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: <Widget>[
                          const Icon(
                            Icons.directions_outlined,
                            size: 17,
                            color: AppColors.navy,
                          ),
                          const SizedBox(width: AppSpacing.sm),
                          Text(
                            'Directions',
                            style: AppTypography.label.copyWith(fontSize: 13.5),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
