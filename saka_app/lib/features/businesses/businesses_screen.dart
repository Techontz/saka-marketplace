import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../data/models/business.dart';
import '../../data/models/media.dart';
import '../../data/repositories/directory_repository.dart';
import '../../shared/widgets/paged_list.dart';
import '../location/location_controller.dart';

/// The business directory.
class BusinessesScreen extends StatelessWidget {
  const BusinessesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final DirectoryRepository repository = Get.find<DirectoryRepository>();
    final LocationController location = Get.find<LocationController>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Businesses')),
      body: PagedList<Business>(
        // Scoped to the chosen region: a directory that ignores where the user
        // said they are browsing is a worse answer than a shorter list.
        fetch: (int page) => repository.businesses(
          page: page,
          regionSlug: location.regionSlug,
          districtSlug: location.districtSlug,
        ),
        emptyIcon: Icons.storefront_outlined,
        emptyTitle: 'No businesses here yet',
        emptyMessage: location.hasChoice
            ? 'No verified businesses in ${location.label} at the moment. '
                'Try a wider location.'
            : 'The business directory is still filling up.',
        itemBuilder: (BuildContext context, Business business, int _) =>
            BusinessCard(
          business: business,
          onTap: () => Get.toNamed<void>(Routes.businessPath(business.slug)),
        ),
      ),
    );
  }
}

/// A business row: logo, name, trust, location, stock.
class BusinessCard extends StatelessWidget {
  const BusinessCard({required this.business, required this.onTap, super.key});

  final Business business;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: business.displayName,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Row(
          children: <Widget>[
            ClipRRect(
              borderRadius: AppRadius.mdAll,
              child: SizedBox(
                width: 58,
                height: 58,
                child: business.logoUrl != null
                    ? SakaImage.url(
                        url: business.logoUrl,
                        size: MediaSize.thumb,
                        fit: BoxFit.cover,
                      )
                    : ColoredBox(
                        color: AppColors.muted,
                        child: Center(
                          child: Text(
                            business.displayName.characters.first.toUpperCase(),
                            style: AppTypography.title.copyWith(
                              color: AppColors.primary,
                            ),
                          ),
                        ),
                      ),
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
                          business.displayName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.cardTitle,
                        ),
                      ),
                      if (business.isVerified) ...<Widget>[
                        const SizedBox(width: AppSpacing.xs),
                        const VerifiedBadge(compact: true),
                      ],
                    ],
                  ),
                  const SizedBox(height: 3),
                  if (business.businessTypeLabel != null)
                    Text(
                      business.businessTypeLabel!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.caption,
                    ),
                  const SizedBox(height: 5),
                  LocationRow(
                    label: business.location.shortLabel,
                    compact: true,
                  ),
                  const SizedBox(height: 5),
                  Row(
                    children: <Widget>[
                      SakaRating(
                        average: business.ratingAverage,
                        count: business.ratingCount,
                        compact: true,
                      ),
                      if (business.ratingCount > 0)
                        const SizedBox(width: AppSpacing.sm),
                      Text(
                        '${business.listingCount} '
                        '${business.listingCount == 1 ? 'listing' : 'listings'}',
                        style: AppTypography.caption.copyWith(fontSize: 11.5),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const Icon(
              Icons.chevron_right_rounded,
              size: 19,
              color: AppColors.border,
            ),
          ],
        ),
      ),
    );
  }
}
