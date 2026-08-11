import 'package:flutter/material.dart';

import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/widgets/pressable.dart';
import '../../../core/widgets/saka_image.dart';
import '../../../data/models/media.dart';
import '../../../data/models/misc.dart';
import 'section_header.dart';

/// Public places on the home feed.
///
/// A third card shape, after the tall listing cards and the wide business
/// cards: a portrait tile that is almost entirely photograph. A place has no
/// price, no seller and nothing to buy, so the listing card's whole lower half
/// — price row, attribute strip, favourite — would be empty scaffolding.
///
/// The API has served 77 of these from the start and the app only reached them
/// through the Explore tab.
class PlaceRail extends StatelessWidget {
  const PlaceRail({
    required this.places,
    required this.onTapPlace,
    this.onSeeAll,
    super.key,
  });

  final List<PublicPlace> places;
  final void Function(PublicPlace place) onTapPlace;
  final VoidCallback? onSeeAll;

  static const double _cardWidth = 148;
  static const double _imageHeight = 190;

  @override
  Widget build(BuildContext context) {
    if (places.isEmpty) return const SizedBox.shrink();

    final double scale = MediaQuery.textScalerOf(context).scale(1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        SectionHeader(
          title: 'Places worth knowing',
          subtitle: 'Landmarks and neighbourhoods across Tanzania',
          onSeeAll: onSeeAll,
        ),
        SizedBox(
          // The caption sits under the image, so the box grows with the user's
          // text size rather than clipping the second line.
          height: _imageHeight + (34 * scale),
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screen),
            itemCount: places.length,
            physics: const BouncingScrollPhysics(),
            separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final PublicPlace place = places[index];
              return _PlaceCard(
                place: place,
                width: _cardWidth,
                imageHeight: _imageHeight,
                onTap: () => onTapPlace(place),
              );
            },
          ),
        ),
      ],
    );
  }
}

class _PlaceCard extends StatelessWidget {
  const _PlaceCard({
    required this.place,
    required this.width,
    required this.imageHeight,
    required this.onTap,
  });

  final PublicPlace place;
  final double width;
  final double imageHeight;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: place.name,
      child: SizedBox(
        width: width,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.lg),
              child: Stack(
                children: <Widget>[
                  SakaImage.url(
                    url: place.imageUrl,
                    size: MediaSize.card,
                    width: width,
                    height: imageHeight,
                    fit: BoxFit.cover,
                  ),

                  // The category, over the image rather than under it — the
                  // name below needs both its lines on a narrow tile.
                  if (place.categoryName != null)
                    Positioned(
                      left: AppSpacing.xs,
                      bottom: AppSpacing.xs,
                      right: AppSpacing.xs,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 7, vertical: 3,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.black.withValues(alpha: 0.55),
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                        ),
                        child: Text(
                          place.categoryName!,
                          style: AppTypography.caption.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              place.name,
              style: AppTypography.cardTitle,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            if (place.location.shortLabel.isNotEmpty)
              Text(
                place.location.shortLabel,
                style: AppTypography.caption,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
          ],
        ),
      ),
    );
  }
}
