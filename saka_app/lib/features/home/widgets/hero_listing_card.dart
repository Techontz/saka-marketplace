import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/favorite_button.dart';
import '../../../core/widgets/pressable.dart';
import '../../../core/widgets/saka_image.dart';
import '../../../data/models/listing.dart';
import '../../../data/models/media.dart';

/// One listing, given the whole width.
///
/// The home screen was five horizontal rails of the same card, and a feed with
/// one rhythm reads as a list of things rather than a place with a point of
/// view. This is the counterweight: a single editorial slot at the top that
/// says "start here", sized so the photograph is actually legible on a 360px
/// phone instead of being a 150px thumbnail.
///
/// It renders the FIRST featured listing and nothing else. Not a carousel —
/// a carousel of heroes is just a rail with bigger cards, and it moves the
/// thing the user was looking at out from under their thumb.
class HeroListingCard extends StatelessWidget {
  const HeroListingCard({
    required this.listing,
    required this.onTap,
    this.onAuthRequired,
    this.heroPrefix = 'hero',
    super.key,
  });

  final Listing listing;
  final VoidCallback onTap;
  final VoidCallback? onAuthRequired;

  /// Must match the prefix passed to the detail route, see ListingCard.
  final String heroPrefix;

  /// Tall enough to be a photograph, short enough that the rails below stay
  /// visible on a small phone — losing the "there is more here" signal is what
  /// makes a full-bleed hero feel like a dead end.
  static const double _aspect = 4 / 3;

  @override
  Widget build(BuildContext context) {
    final double width = MediaQuery.sizeOf(context).width - (AppSpacing.screen * 2);
    // Same scheme as ListingCard._heroTag, so the detail screen's Hero
    // finds a partner. A tag that merely looks similar produces a silent
    // cross-fade instead of a shared-element transition.
    final String heroTag = 'listing-$heroPrefix-${listing.slug}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen, AppSpacing.md, AppSpacing.screen, AppSpacing.lg,
      ),
      child: PressableScale(
        onTap: onTap,
        semanticLabel: '${listing.title}. ${Fmt.price(listing.price)}',
        child: Semantics(
          button: true,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.lg),
            child: AspectRatio(
              aspectRatio: _aspect,
              child: Stack(
                fit: StackFit.expand,
                children: <Widget>[
                  Hero(
                    tag: heroTag,
                    child: SakaImage(
                      image: listing.primaryImage,
                      // `detail`, not `card`: this is displayed at full screen
                      // width, and the card variant visibly softens at 360pt.
                      size: MediaSize.detail,
                      width: width,
                      fit: BoxFit.cover,
                    ),
                  ),

                  // A scrim, not a flat overlay. White text over an unknown
                  // photograph is a contrast gamble; this makes the bottom
                  // third reliably dark while leaving the top of the image
                  // untouched.
                  const DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.center,
                        end: Alignment.bottomCenter,
                        colors: <Color>[Color(0x00000000), Color(0xCC07121E)],
                      ),
                    ),
                  ),

                  Positioned(
                    top: AppSpacing.md,
                    left: AppSpacing.md,
                    child: _HeroBadge(listing: listing),
                  ),

                  Positioned(
                    top: AppSpacing.sm,
                    right: AppSpacing.sm,
                    child: FavoriteButton(
                      slug: listing.slug,
                      onAuthRequired: onAuthRequired,
                    ),
                  ),

                  Positioned(
                    left: AppSpacing.md,
                    right: AppSpacing.md,
                    bottom: AppSpacing.md,
                    child: _HeroCaption(listing: listing),
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

/// "Featured", or the vertical when the listing is not featured.
class _HeroBadge extends StatelessWidget {
  const _HeroBadge({required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    final String label = listing.isFeatured
        ? 'Featured'
        : (listing.category?.name ?? 'On SAKA');

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm, vertical: 5,
      ),
      decoration: BoxDecoration(
        // Orange, the accent — the teal would disappear into half the
        // photographs on a property marketplace.
        color: AppColors.orange,
        borderRadius: BorderRadius.circular(AppRadius.pill),
      ),
      child: Text(
        label,
        style: AppTypography.caption.copyWith(
          color: Colors.white,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.2,
        ),
      ),
    );
  }
}

class _HeroCaption extends StatelessWidget {
  const _HeroCaption({required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        // Price first and largest. On a marketplace it is the thing people
        // scan for, and burying it under the title is a habit inherited from
        // blog layouts.
        Text(
          Fmt.price(listing.price),
          style: AppTypography.priceLarge.copyWith(color: Colors.white),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        const SizedBox(height: 2),
        Text(
          listing.title,
          style: AppTypography.body.copyWith(
            color: Colors.white,
            fontWeight: FontWeight.w600,
          ),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        if (listing.location.shortLabel.isNotEmpty) ...<Widget>[
          const SizedBox(height: 4),
          Row(
            children: <Widget>[
              const Icon(
                Icons.location_on_outlined,
                size: 14,
                color: Colors.white70,
              ),
              const SizedBox(width: 3),
              Expanded(
                child: Text(
                  listing.location.shortLabel,
                  style: AppTypography.bodySmall.copyWith(color: Colors.white70),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}
