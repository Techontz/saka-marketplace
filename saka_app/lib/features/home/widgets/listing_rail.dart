import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/widgets/listing_card.dart';
import '../../../core/widgets/pressable.dart';
import '../../../data/models/listing.dart';
import '../home_controller.dart';

/// A horizontal strip of listings, with its own loading and error state.
///
/// The rail DISAPPEARS when it has no content — heading and all. An empty
/// section with a title is worse than no section: it tells the user the app
/// expected something to be there and could not find it.
class ListingRail extends StatelessWidget {
  const ListingRail({
    required this.title,
    required this.state,
    required this.railKey,
    required this.onTapListing,
    super.key,
    this.subtitle,
    this.onSeeAll,
    this.onAuthRequired,
  });

  final String title;
  final String? subtitle;
  final RailState state;

  /// Namespaces the Hero tags in this rail, so the same listing appearing in
  /// two rails on one screen does not produce duplicate tags.
  final String railKey;

  final void Function(Listing listing, String railKey) onTapListing;
  final VoidCallback? onSeeAll;
  final VoidCallback? onAuthRequired;



  @override
  Widget build(BuildContext context) {
    // Empty and not loading: render nothing at all.
    if (state.isEmpty || state.error != null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.screen,
            AppSpacing.xxl,
            AppSpacing.sm,
            AppSpacing.md,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(title, style: AppTypography.section),
                    if (subtitle != null) ...<Widget>[
                      const SizedBox(height: 2),
                      Text(subtitle!, style: AppTypography.caption),
                    ],
                  ],
                ),
              ),
              if (onSeeAll != null && !state.isLoading)
                PressableScale(
                  onTap: onSeeAll,
                  scale: 0.94,
                  child: Container(
                    height: AppSizes.minTouchTarget,
                    alignment: Alignment.center,
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.md,
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        Text(
                          'See all',
                          style: AppTypography.caption.copyWith(
                            color: AppColors.primary,
                            fontWeight: FontWeight.w800,
                            fontSize: 12.5,
                          ),
                        ),
                        const Icon(
                          Icons.chevron_right_rounded,
                          size: 16,
                          color: AppColors.primary,
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
        SizedBox(
          height: ListingCard.railHeight(context),
          child: state.isLoading
              ? const _RailSkeleton()
              : ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.screen,
                  ),
                  physics: const BouncingScrollPhysics(),
                  // Each card is its own repaint layer. Without this the whole
                  // rail repaints as it scrolls, which is what makes a
                  // horizontal strip of photographs stutter on a mid-range
                  // Android device.
                  addRepaintBoundaries: true,
                  itemCount: state.items.length,
                  separatorBuilder: (_, _) =>
                      const SizedBox(width: AppSpacing.md),
                  itemBuilder: (BuildContext context, int index) {
                    final Listing listing = state.items[index];
                    return ListingCard(
                      listing: listing,
                      layout: ListingCardLayout.rail,
                      heroPrefix: railKey,
                      onAuthRequired: onAuthRequired,
                      onTap: () => onTapListing(listing, railKey),
                    );
                  },
                ),
        ),
      ],
    );
  }
}

class _RailSkeleton extends StatelessWidget {
  const _RailSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screen),
      physics: const NeverScrollableScrollPhysics(),
      itemCount: 3,
      separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
      itemBuilder: (_, _) =>
          const ListingCardSkeleton(layout: ListingCardLayout.rail),
    );
  }
}
