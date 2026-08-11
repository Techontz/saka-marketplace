import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../utils/formatters.dart';
import 'badges.dart';
import 'favorite_button.dart';
import 'pressable.dart';
import 'saka_image.dart';

/// The listing card, in the three shapes the app needs.
///
/// One widget rather than three files because the content and its priority are
/// identical — image, price, title, location — and only the arrangement
/// changes. Three separate widgets is how a marketplace ends up showing the
/// verified badge on the grid card and forgetting it on the list card.
enum ListingCardLayout {
  /// Two-up in a grid. The default browse view.
  grid,

  /// Full-width row with the image on the left. More metadata fits, which is
  /// what people switch to when comparing.
  list,

  /// Fixed-width, for a horizontal home rail.
  rail,
}

class ListingCard extends StatelessWidget {
  const ListingCard({
    required this.listing,
    required this.onTap,
    super.key,
    this.layout = ListingCardLayout.grid,
    this.onAuthRequired,
    this.showFavorite = true,
    this.heroPrefix = '',
  });

  final Listing listing;
  final VoidCallback onTap;
  final ListingCardLayout layout;
  final VoidCallback? onAuthRequired;
  final bool showFavorite;

  /// Namespaces the Hero tag.
  ///
  /// The same listing legitimately appears twice on one screen — in "Featured"
  /// and again in "Near you" — and two Heroes with the same tag on one route
  /// throws. The prefix is the rail's identity.
  final String heroPrefix;

  static const double railWidth = 232;

  /// The height a two-column grid must give this card.
  ///
  /// An explicit extent rather than a `childAspectRatio`. A ratio has to be
  /// guessed against content whose height depends on the system font scale, and
  /// guessing produced a 0.113px overflow on a real device — invisible in the
  /// layout but a red-and-yellow stripe in a debug build and a clipped
  /// descender in release. Measuring the parts removes the guess.
  static double gridHeight(BuildContext context, double cardWidth) {
    final double scale = MediaQuery.textScalerOf(context).scale(1);
    final double image = cardWidth / AppSizes.cardImageAspect;
    // price + gap + two title lines + gap + location + gap + attributes
    const double text = 20 + 4 + 42 + 8 + 17 + 8 + 16;
    const double padding = AppSpacing.md * 2;
    return image + (text * scale) + padding;
  }

  /// The height a horizontal rail must give this card.
  ///
  /// Computed, not a constant. A horizontal ListView needs a fixed height, and
  /// a hardcoded one overflows the moment the user raises their system font
  /// size — which is exactly what a real device showed at the default scale on
  /// a 1080×2400 phone. The image is a fixed 4:3 of the card width; only the
  /// text block below it scales.
  static double railHeight(BuildContext context) {
    final double scale = MediaQuery.textScalerOf(context).scale(1);
    const double image = railWidth / AppSizes.cardImageAspect;
    // price + gap + two title lines + gap + location + gap + attributes
    const double text = 20 + 4 + 42 + 8 + 17 + 8 + 16;
    const double padding = AppSpacing.md * 2;
    return image + (text * scale) + padding;
  }

  @override
  Widget build(BuildContext context) {
    return switch (layout) {
      ListingCardLayout.grid => _buildVertical(context, width: null),
      ListingCardLayout.rail => SizedBox(
          width: railWidth,
          child: _buildVertical(context, width: railWidth),
        ),
      ListingCardLayout.list => _buildHorizontal(context),
    };
  }

  String get _heroTag => 'listing-$heroPrefix-${listing.slug}';

  Widget _buildVertical(BuildContext context, {double? width}) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: listing.title,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: ClipRRect(
          borderRadius: AppRadius.lgAll,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              _Cover(
                listing: listing,
                heroTag: _heroTag,
                showFavorite: showFavorite,
                onAuthRequired: onAuthRequired,
                aspectRatio: AppSizes.cardImageAspect,
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.md,
                  AppSpacing.md,
                  AppSpacing.md,
                  AppSpacing.md,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    // Price first, deliberately. It is the most-scanned element
                    // on a marketplace card, and putting the title above it
                    // makes every card start with a wall of text.
                    Text(
                      Fmt.priceCompact(listing.price),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.price.copyWith(fontSize: 15.5),
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    Text(
                      listing.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.cardTitle,
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    LocationRow(label: listing.location.shortLabel, compact: true),
                    if (_summaryAttributes.isNotEmpty) ...<Widget>[
                      const SizedBox(height: AppSpacing.sm),
                      _AttributeStrip(attributes: _summaryAttributes),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildHorizontal(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: listing.title,
      child: Container(
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        clipBehavior: Clip.antiAlias,
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              SizedBox(
                width: 132,
                child: _Cover(
                  listing: listing,
                  heroTag: _heroTag,
                  showFavorite: false,
                  onAuthRequired: onAuthRequired,
                  aspectRatio: null,
                  compact: true,
                ),
              ),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: <Widget>[
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Row(
                            children: <Widget>[
                              Expanded(
                                child: Text(
                                  Fmt.priceCompact(listing.price),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style:
                                      AppTypography.price.copyWith(fontSize: 15.5),
                                ),
                              ),
                              if (showFavorite)
                                Transform.translate(
                                  // Pulls the 44pt hit area back into the
                                  // padding so the icon lines up optically with
                                  // the text edge without shrinking the target.
                                  offset: const Offset(10, -10),
                                  child: FavoriteButton(
                                    slug: listing.slug,
                                    onAuthRequired: onAuthRequired,
                                    size: 18,
                                  ),
                                ),
                            ],
                          ),
                          const SizedBox(height: AppSpacing.xxs),
                          Text(
                            listing.title,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: AppTypography.cardTitle,
                          ),
                          const SizedBox(height: AppSpacing.sm),
                          LocationRow(
                            label: listing.location.shortLabel,
                            compact: true,
                          ),
                        ],
                      ),
                      if (_summaryAttributes.isNotEmpty) ...<Widget>[
                        const SizedBox(height: AppSpacing.sm),
                        _AttributeStrip(attributes: _summaryAttributes),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// At most three attributes, in the BACKEND's own order.
  ///
  /// No hardcoded "show beds and bathrooms" rule — the taxonomy already orders
  /// attributes by importance per category, so a vehicle card shows mileage for
  /// the same reason a property card shows bedrooms.
  ///
  /// Long free-text values ARE dropped, though. A services listing carries
  /// attributes like "anytime" and "maintenance and repair"; in a 100pt column
  /// those render as "anyti… · main…", which is noise pretending to be data.
  /// Short tokens and numbers survive because they stay readable.
  List<ListingAttribute> get _summaryAttributes {
    return listing.attributes
        .where((ListingAttribute a) => a.value.isNotEmpty)
        .where((ListingAttribute a) {
          if (double.tryParse(a.value) != null) return true;
          return a.value.length <= 8;
        })
        .take(3)
        .toList(growable: false);
  }
}

/// The photograph and everything laid over it.
class _Cover extends StatelessWidget {
  const _Cover({
    required this.listing,
    required this.heroTag,
    required this.showFavorite,
    required this.aspectRatio,
    this.onAuthRequired,
    this.compact = false,
  });

  final Listing listing;
  final String heroTag;
  final bool showFavorite;
  final double? aspectRatio;
  final VoidCallback? onAuthRequired;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final Widget image = Hero(
      tag: heroTag,
      // The card and the detail hero have different corner radii, so without
      // this the Hero interpolates between two mismatched shapes and the photo
      // visibly "unrounds" mid-flight.
      flightShuttleBuilder: (
        BuildContext flightContext,
        Animation<double> animation,
        HeroFlightDirection direction,
        BuildContext fromContext,
        BuildContext toContext,
      ) {
        return AnimatedBuilder(
          animation: animation,
          builder: (BuildContext context, Widget? child) => ClipRRect(
            borderRadius: BorderRadius.circular(
              Tween<double>(begin: AppRadius.lg, end: 0).evaluate(animation),
            ),
            child: child,
          ),
          child: SakaImage(
            image: listing.displayImage,
            size: MediaSize.card,
            fit: BoxFit.cover,
          ),
        );
      },
      child: SakaImage(
        image: listing.displayImage,
        size: MediaSize.card,
        fit: BoxFit.cover,
        width: double.infinity,
        height: double.infinity,
      ),
    );

    final Widget stack = Stack(
      fit: StackFit.expand,
      children: <Widget>[
        image,
        // A gradient at the top only, and only when something sits there.
        // Overlay text on an unknown photograph is otherwise a contrast lottery.
        if (listing.purpose != null || listing.isVerified)
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.center,
                colors: <Color>[Color(0x40000000), Color(0x00000000)],
              ),
            ),
          ),
        // Bounded so the badges cannot run under the favourite button. The
        // purpose badge is the one that must always be legible, so VERIFIED is
        // the one that yields — clipping it mid-word looked broken.
        Positioned(
          top: AppSpacing.sm,
          left: AppSpacing.sm,
          right: showFavorite ? AppSizes.minTouchTarget : AppSpacing.sm,
          child: Row(
            children: <Widget>[
              if (listing.purpose != null && listing.purpose!.isNotEmpty)
                PurposeBadge(purpose: listing.purpose!),
              if (listing.isVerified) ...<Widget>[
                const SizedBox(width: AppSpacing.xs),
                Flexible(
                  child: ClipRect(
                    child: VerifiedBadge(compact: compact),
                  ),
                ),
              ],
            ],
          ),
        ),
        if (showFavorite)
          Positioned(
            top: 0,
            right: 0,
            child: FavoriteButton(
              slug: listing.slug,
              onAuthRequired: onAuthRequired,
              onSurface: true,
              size: 18,
            ),
          ),
      ],
    );

    if (aspectRatio == null) return stack;
    return AspectRatio(aspectRatio: aspectRatio!, child: stack);
  }
}

/// "3 Bed · 2 Bath · 180 sqft" — separated by dots, never wrapping.
class _AttributeStrip extends StatelessWidget {
  const _AttributeStrip({required this.attributes});

  final List<ListingAttribute> attributes;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        for (int i = 0; i < attributes.length; i++) ...<Widget>[
          if (i > 0)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 5),
              child: Container(
                width: 3,
                height: 3,
                decoration: const BoxDecoration(
                  color: AppColors.border,
                  shape: BoxShape.circle,
                ),
              ),
            ),
          Flexible(
            child: Text(
              // The index resource sends no names, so the code supplies the
              // unit; the detail resource already has both.
              attributes[i].isUnlabelled
                  ? Fmt.attributeLabel(
                      attributes[i].code,
                      attributes[i].value,
                    )
                  : attributes[i].displayValue,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.caption.copyWith(fontSize: 11.5),
            ),
          ),
        ],
      ],
    );
  }
}

/// The card's skeleton, laid out to the SAME dimensions.
///
/// The point of a skeleton is that nothing moves when the real card replaces
/// it. A skeleton of a different height is worse than no skeleton — it
/// guarantees the layout shift it was added to prevent.
class ListingCardSkeleton extends StatelessWidget {
  const ListingCardSkeleton({super.key, this.layout = ListingCardLayout.grid});

  final ListingCardLayout layout;

  @override
  Widget build(BuildContext context) {
    if (layout == ListingCardLayout.list) {
      return Container(
        height: 132,
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        clipBehavior: Clip.antiAlias,
        child: Row(
          children: <Widget>[
            const SakaSkeletonBlock(width: 132, height: 132),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const <Widget>[
                    SakaSkeletonBlock(width: 90, height: 15),
                    SizedBox(height: AppSpacing.sm),
                    SakaSkeletonBlock(width: double.infinity, height: 13),
                    SizedBox(height: 6),
                    SakaSkeletonBlock(width: 140, height: 13),
                    SizedBox(height: AppSpacing.md),
                    SakaSkeletonBlock(width: 110, height: 11),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
    }

    final Widget body = DecoratedBox(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      child: ClipRRect(
        borderRadius: AppRadius.lgAll,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const AspectRatio(
              aspectRatio: AppSizes.cardImageAspect,
              child: SakaSkeletonBlock(
                width: double.infinity,
                height: double.infinity,
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: const <Widget>[
                  SakaSkeletonBlock(width: 84, height: 15),
                  SizedBox(height: AppSpacing.sm),
                  SakaSkeletonBlock(width: double.infinity, height: 13),
                  SizedBox(height: 6),
                  SakaSkeletonBlock(width: 120, height: 13),
                  SizedBox(height: AppSpacing.sm),
                  SakaSkeletonBlock(width: 96, height: 11),
                ],
              ),
            ),
          ],
        ),
      ),
    );

    if (layout == ListingCardLayout.rail) {
      return SizedBox(width: ListingCard.railWidth, child: body);
    }
    return body;
  }
}

/// A flat skeleton block.
///
/// Separate from `SakaSkeleton` (which shimmers) so a card skeleton can put ONE
/// shimmer around the whole card instead of running six independent shimmer
/// animations per card — at twelve cards on screen that is seventy-two
/// animation controllers driving the same effect.
class SakaSkeletonBlock extends StatelessWidget {
  const SakaSkeletonBlock({
    required this.width,
    required this.height,
    super.key,
  });

  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: AppColors.shimmerBase,
        borderRadius: BorderRadius.circular(4),
      ),
    );
  }
}
