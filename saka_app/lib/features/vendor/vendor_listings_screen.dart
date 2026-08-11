import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:latlong2/latlong.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../../data/repositories/vendor_repository.dart';
import '../../shared/widgets/paged_list.dart';
import '../boundary/boundary_editor_screen.dart';

/// The vendor's own inventory.
///
/// Reads `/seller/listings`, which the backend scopes to the authenticated
/// vendor — this screen has no vendor id to pass and no way to ask for another
/// vendor's stock. Isolation is the API's, not the UI's.
class VendorListingsScreen extends StatelessWidget {
  const VendorListingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final VendorRepository repository = Get.find<VendorRepository>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('My listings')),
      body: PagedList<Listing>(
        fetch: (int page) => repository.listings(page: page),
        emptyIcon: Icons.inventory_2_outlined,
        emptyTitle: 'No listings yet',
        emptyMessage: 'Create your first listing on the SAKA vendor portal at '
            'saka.africa, then manage it here.',
        itemBuilder: (BuildContext context, Listing listing, int _) =>
            _VendorListingCard(listing: listing),
      ),
    );
  }
}

class _VendorListingCard extends StatelessWidget {
  const _VendorListingCard({required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      child: Column(
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              ClipRRect(
                borderRadius: AppRadius.mdAll,
                child: SizedBox(
                  width: 64,
                  height: 64,
                  child: SakaImage(
                    image: listing.displayImage,
                    size: MediaSize.thumb,
                    fit: BoxFit.cover,
                  ),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      listing.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.cardTitle,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      Fmt.priceCompact(listing.price),
                      style: AppTypography.price.copyWith(fontSize: 14),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Row(
                      children: <Widget>[
                        if (listing.status != null)
                          SakaTag.status(
                            listing.status!,
                            listing.status!.replaceAll('_', ' '),
                          ),
                        const SizedBox(width: AppSpacing.xs),
                        Text(
                          '${Fmt.compactCount(listing.views)} views',
                          style: AppTypography.caption.copyWith(fontSize: 11),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),

          // The boundary editor is offered ONLY for categories the backend says
          // support one — `supports_boundary` on the resource. Showing it on a
          // phone listing would produce a 422 the vendor cannot act on.
          if (listing.supportsBoundary) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            const Divider(height: 1),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: <Widget>[
                Icon(
                  listing.hasBoundary
                      ? Icons.check_circle_rounded
                      : Icons.crop_free_rounded,
                  size: 16,
                  color: listing.hasBoundary
                      ? AppColors.success
                      : AppColors.mutedForeground,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    listing.hasBoundary
                        ? 'Plot mapped · ${listing.boundary!.areaDisplay}'
                        : 'No plot boundary drawn',
                    style: AppTypography.caption,
                  ),
                ),
                PressableScale(
                  onTap: () => Get.to<void>(
                    () => BoundaryEditorScreen(
                      listingUuid: listing.uuid,
                      listingTitle: listing.title,
                      initialCentre: listing.location.hasCoordinates
                          ? LatLng(
                              listing.location.latitude!,
                              listing.location.longitude!,
                            )
                          : null,
                    ),
                  ),
                  scale: 0.94,
                  semanticLabel: listing.hasBoundary
                      ? 'Edit plot boundary'
                      : 'Draw plot boundary',
                  child: Container(
                    height: AppSizes.minTouchTarget,
                    alignment: Alignment.center,
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.md,
                    ),
                    child: Text(
                      listing.hasBoundary ? 'Edit plot' : 'Draw plot',
                      style: AppTypography.caption.copyWith(
                        color: AppColors.primary,
                        fontWeight: FontWeight.w800,
                        fontSize: 12.5,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ],

          const SizedBox(height: AppSpacing.sm),
          PressableScale(
            onTap: () => Get.toNamed<void>(Routes.listingPath(listing.slug)),
            child: Container(
              height: AppSizes.minTouchTarget,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: AppColors.muted,
                borderRadius: AppRadius.mdAll,
              ),
              child: Text(
                'View as a buyer sees it',
                style: AppTypography.caption.copyWith(
                  color: AppColors.navy,
                  fontWeight: FontWeight.w700,
                  fontSize: 12.5,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
