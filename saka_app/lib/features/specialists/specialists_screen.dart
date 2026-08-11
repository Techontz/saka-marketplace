import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../../data/repositories/directory_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../../shared/widgets/paged_list.dart';
import '../location/location_controller.dart';

/// The specialist directory.
///
/// Specialists are listings in the `specialists` vertical on this backend, so
/// this is `GET /listings?category=specialists` with a card that leads on the
/// profession rather than on a price. Modelling them as a separate entity in
/// the app would invent a distinction the API does not make — and would lose
/// search, filters, reviews and favourites, which they get for free this way.
class SpecialistsScreen extends StatelessWidget {
  const SpecialistsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final ListingRepository listings = Get.find<ListingRepository>();
    final LocationController location = Get.find<LocationController>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Specialists')),
      body: PagedList<Listing>(
        fetch: (int page) => listings.search(
          ListingQuery(
            categorySlug: DirectoryRepository.specialistsCategory,
            regionSlug: location.regionSlug,
          ),
          page: page,
        ),
        emptyIcon: Icons.workspace_premium_outlined,
        emptyTitle: 'No specialists yet',
        emptyMessage: 'Lawyers, accountants, tutors and engineers will appear '
            'here as they join SAKA.',
        itemBuilder: (BuildContext context, Listing specialist, int _) =>
            SpecialistCard(
          specialist: specialist,
          onTap: () => Get.toNamed<void>(
            Routes.listingPath(specialist.slug),
            arguments: <String, dynamic>{
              'listing': specialist,
              'heroPrefix': 'specialists',
            },
          ),
        ),
      ),
    );
  }
}

class SpecialistCard extends StatelessWidget {
  const SpecialistCard({
    required this.specialist,
    required this.onTap,
    super.key,
  });

  final Listing specialist;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: specialist.title,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            ClipRRect(
              borderRadius: AppRadius.mdAll,
              child: SizedBox(
                width: 68,
                height: 68,
                child: SakaImage(
                  image: specialist.displayImage,
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
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          specialist.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.cardTitle,
                        ),
                      ),
                      if (specialist.isVerified) ...<Widget>[
                        const SizedBox(width: AppSpacing.xs),
                        const VerifiedBadge(compact: true),
                      ],
                    ],
                  ),
                  const SizedBox(height: 4),
                  // The profession is the leaf category the backend assigned —
                  // "Commercial lawyer", "Certified accountant".
                  if (specialist.category != null)
                    Text(
                      specialist.category!.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.caption.copyWith(
                        color: AppColors.primary,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  const SizedBox(height: 5),
                  LocationRow(
                    label: specialist.location.shortLabel,
                    compact: true,
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Row(
                    children: <Widget>[
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.sm,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withValues(alpha: 0.09),
                          borderRadius: AppRadius.brandAll,
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            const Icon(
                              Icons.event_available_rounded,
                              size: 12,
                              color: AppColors.primary,
                            ),
                            const SizedBox(width: 4),
                            Text(
                              'Book a session',
                              style: AppTypography.caption.copyWith(
                                color: AppColors.primary,
                                fontWeight: FontWeight.w700,
                                fontSize: 11.5,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
