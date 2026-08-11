import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/widgets/pressable.dart';
import '../../../core/widgets/saka_image.dart';
import '../../../data/models/business.dart';
import '../../../data/models/media.dart';
import 'section_header.dart';

/// Businesses on the home feed.
///
/// A deliberately different shape from the listing rails above it: a wide,
/// short card led by a logo rather than a tall one led by a photograph. Two
/// rails of identical cards separated only by a heading read as one long list,
/// and the user stops seeing the headings at all.
///
/// The API has had 27 of these all along and the home screen never asked for
/// them — the directory was reachable only by knowing the Explore tab existed.
class BusinessRail extends StatelessWidget {
  const BusinessRail({
    required this.businesses,
    required this.onTapBusiness,
    this.onSeeAll,
    super.key,
  });

  final List<Business> businesses;
  final void Function(Business business) onTapBusiness;
  final VoidCallback? onSeeAll;

  /// Wide enough for a two-line name beside the logo, narrow enough that the
  /// next card peeks in and the rail reads as scrollable without a hint.
  static const double _cardWidth = 232;
  static const double _cardHeight = 84;

  @override
  Widget build(BuildContext context) {
    // No businesses, no section. A heading over an empty strip tells the user
    // the app is broken; saying nothing tells them there is nothing to say.
    if (businesses.isEmpty) return const SizedBox.shrink();

    final double scale = MediaQuery.textScalerOf(context).scale(1);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        SectionHeader(
          title: 'Businesses on SAKA',
          subtitle: 'Verified shops, agents and service providers',
          onSeeAll: onSeeAll,
        ),
        SizedBox(
          // Scales with the user's text size instead of clipping the second
          // line, which is what a fixed height does at 1.3x.
          height: _cardHeight * (1 + ((scale - 1) * 0.6)),
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screen),
            itemCount: businesses.length,
            // Cheap and correct: the rail is short and never grows.
            physics: const BouncingScrollPhysics(),
            separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final Business business = businesses[index];
              return _BusinessCard(
                business: business,
                width: _cardWidth,
                onTap: () => onTapBusiness(business),
              );
            },
          ),
        ),
      ],
    );
  }
}

class _BusinessCard extends StatelessWidget {
  const _BusinessCard({
    required this.business,
    required this.width,
    required this.onTap,
  });

  final Business business;
  final double width;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: business.displayName,
      child: Container(
        width: width,
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: AppColors.background,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(color: AppColors.border),
        ),
        child: Row(
          children: <Widget>[
            ClipRRect(
              borderRadius: BorderRadius.circular(AppRadius.md),
              child: SakaImage.url(
                url: business.logoUrl,
                // A 56pt box never needs more than the thumb variant.
                size: MediaSize.thumb,
                width: 56,
                height: 56,
                fit: BoxFit.cover,
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Flexible(
                        child: Text(
                          business.displayName,
                          style: AppTypography.cardTitle,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (business.isVerified) ...<Widget>[
                        const SizedBox(width: 3),
                        const Icon(
                          Icons.verified_rounded,
                          size: 14,
                          color: AppColors.primary,
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    business.businessTypeLabel ?? business.location.shortLabel,
                    style: AppTypography.caption,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 3),
                  _Meta(business: business),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Rating when the business has been rated, listing count otherwise.
///
/// Not both, and never "0.0 ★". A new business with no reviews is not a bad
/// business, and showing it an empty rating is how a directory discourages the
/// people it needs most.
class _Meta extends StatelessWidget {
  const _Meta({required this.business});

  final Business business;

  @override
  Widget build(BuildContext context) {
    if (business.ratingCount > 0) {
      return Row(
        children: <Widget>[
          const Icon(Icons.star_rounded, size: 13, color: AppColors.orange),
          const SizedBox(width: 2),
          Text(
            business.ratingAverage.toStringAsFixed(1),
            style: AppTypography.caption.copyWith(
              fontWeight: FontWeight.w700,
              color: AppColors.foreground,
            ),
          ),
          const SizedBox(width: 3),
          Text('(${business.ratingCount})', style: AppTypography.caption),
        ],
      );
    }

    final int count = business.listingCount;
    if (count <= 0) return const SizedBox.shrink();

    return Text(
      '$count ${count == 1 ? 'listing' : 'listings'}',
      style: AppTypography.caption,
    );
  }
}
