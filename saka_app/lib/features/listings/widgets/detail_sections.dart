import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/badges.dart';
import '../../../core/widgets/pressable.dart';
import '../../../data/models/boundary.dart';
import '../../../data/models/listing.dart';
import '../../boundary/boundary_map.dart';
import '../../boundary/boundary_viewer_screen.dart';

/// The blocks that make up a listing detail page.
///
/// Each is a card on `AppColors.page`, separated by a consistent gap, so the
/// screen reads as a stack of discrete facts rather than one long document.

/// A titled white card. Every block below uses it, so the padding, radius and
/// shadow are defined once.
class DetailCard extends StatelessWidget {
  const DetailCard({required this.child, super.key, this.title, this.trailing});

  final Widget child;
  final String? title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.md,
        AppSpacing.screen,
        0,
      ),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (title != null) ...<Widget>[
              Row(
                children: <Widget>[
                  Expanded(child: Text(title!, style: AppTypography.section)),
                  ?trailing,
                ],
              ),
              const SizedBox(height: AppSpacing.md),
            ],
            child,
          ],
        ),
      ),
    );
  }
}

/// Title, price, location, badges — the identity of the listing.
class ListingSummary extends StatelessWidget {
  const ListingSummary({
    required this.listing,
    required this.isLoading,
    super.key,
  });

  final Listing listing;
  final bool isLoading;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.background,
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.xl,
        AppSpacing.screen,
        AppSpacing.xl,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              if (listing.purpose != null && listing.purpose!.isNotEmpty)
                PurposeBadge(purpose: listing.purpose!),
              if (listing.isVerified) ...<Widget>[
                const SizedBox(width: AppSpacing.sm),
                const VerifiedBadge(),
              ],
              const Spacer(),
              if (listing.views > 0)
                Text(
                  '${Fmt.compactCount(listing.views)} views',
                  style: AppTypography.caption,
                ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Text(listing.title, style: AppTypography.headline),
          const SizedBox(height: AppSpacing.sm),
          Text(Fmt.price(listing.price), style: AppTypography.priceLarge),
          if (listing.price.isNegotiable)
            Text('Negotiable', style: AppTypography.caption),
          const SizedBox(height: AppSpacing.md),
          LocationRow(label: listing.location.addressLine ?? listing.location.shortLabel),
          if (listing.category != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: <Widget>[
                const Icon(Icons.sell_outlined, size: 14, color: AppColors.mutedForeground),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    listing.category!.parentName == null
                        ? listing.category!.name
                        : '${listing.category!.parentName} › ${listing.category!.name}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.caption,
                  ),
                ),
              ],
            ),
          ],
          if (listing.publishedAt != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              'Listed ${Fmt.relativeTime(listing.publishedAt)}',
              style: AppTypography.caption,
            ),
          ],
        ],
      ),
    );
  }
}

/// The category-specific facts, two per row.
///
/// The labels are whatever the backend's taxonomy called them, so a vehicle
/// shows Transmission and Mileage for the same reason a property shows
/// Bedrooms — no per-category branch exists in this widget.
class AttributeGrid extends StatelessWidget {
  const AttributeGrid({required this.attributes, super.key});

  final List<ListingAttribute> attributes;

  @override
  Widget build(BuildContext context) {
    return DetailCard(
      title: 'Details',
      child: Column(
        children: <Widget>[
          for (int i = 0; i < attributes.length; i += 2)
            Padding(
              padding: EdgeInsets.only(
                bottom: i + 2 < attributes.length ? AppSpacing.md : 0,
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(child: _Cell(attribute: attributes[i])),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: i + 1 < attributes.length
                        ? _Cell(attribute: attributes[i + 1])
                        : const SizedBox.shrink(),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Cell extends StatelessWidget {
  const _Cell({required this.attribute});

  final ListingAttribute attribute;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(attribute.name, style: AppTypography.caption),
        const SizedBox(height: 2),
        Text(
          attribute.displayValue,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.label,
        ),
      ],
    );
  }
}

/// The description, collapsed past six lines.
///
/// Seeded descriptions run long; showing all of one pushes the seller and the
/// similar listings off the bottom of a phone entirely.
class DescriptionBlock extends StatefulWidget {
  const DescriptionBlock({required this.text, super.key});

  final String text;

  @override
  State<DescriptionBlock> createState() => _DescriptionBlockState();
}

class _DescriptionBlockState extends State<DescriptionBlock> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final bool isLong = widget.text.length > 280;

    return DetailCard(
      title: 'Description',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          AnimatedSize(
            duration: AppMotion.base,
            curve: AppMotion.easeOut,
            alignment: Alignment.topCenter,
            child: Text(
              widget.text,
              maxLines: _expanded || !isLong ? null : 6,
              overflow: _expanded || !isLong
                  ? TextOverflow.visible
                  : TextOverflow.ellipsis,
              style: AppTypography.body,
            ),
          ),
          if (isLong)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm),
              child: PressableScale(
                onTap: () => setState(() => _expanded = !_expanded),
                child: SizedBox(
                  height: AppSizes.minTouchTarget,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      _expanded ? 'Show less' : 'Read more',
                      style: AppTypography.label.copyWith(
                        color: AppColors.primary,
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Amenities and facilities, as plain chips.
class ChipBlock extends StatelessWidget {
  const ChipBlock({required this.title, required this.items, super.key});

  final String title;
  final List<String> items;

  @override
  Widget build(BuildContext context) {
    return DetailCard(
      title: title,
      child: Wrap(
        spacing: AppSpacing.sm,
        runSpacing: AppSpacing.sm,
        children: <Widget>[
          for (final String item in items)
            SakaTag(label: item, icon: Icons.check_rounded),
        ],
      ),
    );
  }
}

/// Where it is, and a way to get there.
///
/// No embedded map. This app ships no map SDK: nothing on a listing screen
/// needs an interactive canvas that a static address and a directions hand-off
/// cannot serve, and initialising a tile renderer here would download map data
/// on every listing the user opens. The land-boundary viewer, which genuinely
/// needs a polygon on satellite imagery, is documented as not implemented
/// rather than faked.
class LocationBlock extends StatelessWidget {
  const LocationBlock({
    required this.location,
    required this.supportsBoundary,
    required this.boundary,
    required this.title,
    super.key,
  });

  final GeoLocation location;
  final bool supportsBoundary;

  /// Present only on land listings the seller has actually mapped.
  final ListingBoundary? boundary;

  final String title;

  bool get hasBoundary => boundary != null && !boundary!.isEmpty;

  Future<void> _openDirections() async {
    // A geo: URI with a query, which Android and iOS both hand to the user's
    // preferred maps app rather than forcing one vendor.
    final Uri uri = Uri.parse(
      'https://www.google.com/maps/search/?api=1&query='
      '${location.latitude},${location.longitude}',
    );
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    return DetailCard(
      title: 'Location',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            location.addressLine ?? location.shortLabel,
            style: AppTypography.body,
          ),
          if (location.region != null) ...<Widget>[
            const SizedBox(height: 2),
            Text(
              <String?>[location.ward, location.district, location.region]
                  .whereType<String>()
                  .join(' · '),
              style: AppTypography.caption,
            ),
          ],
          // The mapped parcel. A live preview rather than a link out: the
          // shaded outline IS the answer to "how big is this plot", and
          // sending a buyer to a website to see it defeats the feature.
          if (hasBoundary) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            ClipRRect(
              borderRadius: AppRadius.mdAll,
              child: SizedBox(
                height: 180,
                // Read-only, and no layer switch on the preview — one tap
                // opens the full viewer where those controls belong.
                child: IgnorePointer(
                  child: BoundaryMap(
                    points: boundary!.outerRing,
                    showLayerSwitch: false,
                  ),
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: <Widget>[
                Expanded(
                  child: _BoundaryMetric(
                    label: 'Plot area',
                    value: boundary!.areaDisplay,
                  ),
                ),
                Expanded(
                  child: _BoundaryMetric(
                    label: 'Perimeter',
                    value: boundary!.perimeterDisplay,
                  ),
                ),
                Expanded(
                  child: _BoundaryMetric(
                    label: 'Corners',
                    value: '${boundary!.vertexCount}',
                  ),
                ),
              ],
            ),
            if (boundary!.surveyReference != null) ...<Widget>[
              const SizedBox(height: AppSpacing.sm),
              Text(
                'Survey reference: ${boundary!.surveyReference}',
                style: AppTypography.caption,
              ),
            ],
            const SizedBox(height: AppSpacing.md),
            OutlinedButton.icon(
              onPressed: () => Get.to<void>(
                () => BoundaryViewerScreen(
                  boundary: boundary!,
                  title: title,
                ),
              ),
              icon: const Icon(Icons.crop_free_rounded, size: 18),
              label: const Text('View plot boundary'),
            ),
          ] else if (supportsBoundary) ...<Widget>[
            // Land listing, no parcel drawn. Said plainly so a buyer knows the
            // outline is absent rather than failing to load.
            const SizedBox(height: AppSpacing.md),
            Text(
              'The seller has not mapped this plot boundary.',
              style: AppTypography.caption,
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          OutlinedButton.icon(
            onPressed: _openDirections,
            icon: const Icon(Icons.directions_outlined, size: 18),
            label: const Text('Get directions'),
          ),
        ],
      ),
    );
  }
}

/// Who is selling.
class SellerBlock extends StatelessWidget {
  const SellerBlock({required this.seller, super.key});

  final SellerRef seller;

  @override
  Widget build(BuildContext context) {
    return DetailCard(
      title: 'Seller',
      child: Row(
        children: <Widget>[
          Container(
            width: 46,
            height: 46,
            decoration: const BoxDecoration(
              color: AppColors.muted,
              shape: BoxShape.circle,
            ),
            alignment: Alignment.center,
            child: Text(
              seller.displayName.characters.first.toUpperCase(),
              style: AppTypography.title.copyWith(color: AppColors.primary),
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    Flexible(
                      child: Text(
                        seller.displayName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.label,
                      ),
                    ),
                    if (seller.isVerified) ...<Widget>[
                      const SizedBox(width: AppSpacing.xs),
                      const VerifiedBadge(compact: true),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                if (seller.ratingCount > 0)
                  SakaRating(
                    average: seller.ratingAverage,
                    count: seller.ratingCount,
                    compact: true,
                  )
                else if (seller.memberSince != null)
                  Text(
                    'On SAKA since ${Fmt.date(seller.memberSince!)}',
                    style: AppTypography.caption,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// One figure from the parcel measurement.
class _BoundaryMetric extends StatelessWidget {
  const _BoundaryMetric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(label, style: AppTypography.caption.copyWith(fontSize: 11)),
        const SizedBox(height: 1),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.label.copyWith(fontSize: 14.5),
        ),
      ],
    );
  }
}
