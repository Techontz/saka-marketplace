import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/listing_card.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../core/widgets/states.dart';
import '../../data/models/business.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../../data/repositories/directory_repository.dart';
import '../listings/widgets/detail_sections.dart';

/// A business shopfront.
class BusinessDetailScreen extends StatefulWidget {
  const BusinessDetailScreen({super.key});

  @override
  State<BusinessDetailScreen> createState() => _BusinessDetailScreenState();
}

class _BusinessDetailScreenState extends State<BusinessDetailScreen> {
  Business? _business;
  List<Listing> _listings = const <Listing>[];
  ApiException? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final String slug = Get.parameters['slug'] ?? '';
    if (slug.isEmpty) {
      setState(() {
        _loading = false;
        _error = const ApiException(
          kind: ApiErrorKind.notFound,
          message: 'This business could not be opened.',
        );
      });
      return;
    }

    final DirectoryRepository repository = Get.find<DirectoryRepository>();
    try {
      final Business business = await repository.business(slug);
      if (!mounted) return;
      setState(() {
        _business = business;
        _loading = false;
      });

      final List<Listing> listings =
          (await repository.businessListings(slug)).items;
      if (mounted) setState(() => _listings = listings);
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _loading = false;
      });
    }
  }

  Future<void> _launch(String url) async {
    final Uri uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return Scaffold(
        appBar: AppBar(),
        body: const Center(child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }
    if (_business == null) {
      return Scaffold(
        appBar: AppBar(),
        body: SakaErrorState(
          error: _error ?? const ApiException.malformed(),
          onRetry: _load,
        ),
      );
    }

    final Business business = _business!;

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: Text(business.displayName)),
      body: ListView(
        physics: const BouncingScrollPhysics(),
        padding: const EdgeInsets.only(bottom: AppSpacing.huge),
        children: <Widget>[
          if (business.coverUrl != null)
            AspectRatio(
              aspectRatio: 16 / 7,
              child: SakaImage.url(
                url: business.coverUrl,
                size: MediaSize.detail,
                fit: BoxFit.cover,
              ),
            ),
          Container(
            color: AppColors.background,
            padding: const EdgeInsets.all(AppSpacing.screen),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                ClipRRect(
                  borderRadius: AppRadius.mdAll,
                  child: SizedBox(
                    width: 62,
                    height: 62,
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
                                business.displayName.characters.first
                                    .toUpperCase(),
                                style: AppTypography.headline.copyWith(
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
                              style: AppTypography.title,
                            ),
                          ),
                          if (business.isVerified) ...<Widget>[
                            const SizedBox(width: AppSpacing.xs),
                            const VerifiedBadge(compact: true),
                          ],
                        ],
                      ),
                      if (business.businessTypeLabel != null) ...<Widget>[
                        const SizedBox(height: 2),
                        Text(
                          business.businessTypeLabel!,
                          style: AppTypography.caption,
                        ),
                      ],
                      const SizedBox(height: AppSpacing.sm),
                      SakaRating(
                        average: business.ratingAverage,
                        count: business.ratingCount,
                      ),
                      const SizedBox(height: 4),
                      LocationRow(label: business.location.shortLabel),
                    ],
                  ),
                ),
              ],
            ),
          ),

          if (business.bio != null) DescriptionBlock(text: business.bio!),

          if (business.contact.hasAny)
            DetailCard(
              title: 'Contact',
              child: Column(
                children: <Widget>[
                  if (business.contact.phone != null)
                    _ContactRow(
                      icon: Icons.phone_rounded,
                      label: business.contact.phone!,
                      onTap: () => _launch('tel:${business.contact.phone}'),
                    ),
                  if (business.contact.whatsapp != null)
                    _ContactRow(
                      icon: Icons.chat_rounded,
                      label: 'WhatsApp',
                      onTap: () => _launch(
                        'https://wa.me/${business.contact.whatsapp!
                            .replaceAll(RegExp(r'[^0-9]'), '')}',
                      ),
                    ),
                  if (business.contact.email != null)
                    _ContactRow(
                      icon: Icons.mail_outline_rounded,
                      label: business.contact.email!,
                      onTap: () => _launch('mailto:${business.contact.email}'),
                    ),
                  if (business.contact.website != null)
                    _ContactRow(
                      icon: Icons.language_rounded,
                      label: business.contact.website!,
                      onTap: () => _launch(business.contact.website!),
                    ),
                ],
              ),
            ),

          // Only the networks the vendor actually configured. No row of grey
          // placeholder icons for platforms they are not on.
          if (business.socialLinks.isNotEmpty)
            DetailCard(
              title: 'Social',
              child: Wrap(
                spacing: AppSpacing.sm,
                runSpacing: AppSpacing.sm,
                children: <Widget>[
                  for (final MapEntry<String, String> link
                      in business.socialLinks.entries)
                    PressableScale(
                      onTap: () => _launch(link.value),
                      scale: 0.95,
                      child: Container(
                        constraints: const BoxConstraints(
                          minHeight: AppSizes.minTouchTarget,
                        ),
                        alignment: Alignment.center,
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.lg,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.muted,
                          borderRadius: AppRadius.pillAll,
                        ),
                        child: Text(
                          link.key[0].toUpperCase() + link.key.substring(1),
                          style: AppTypography.caption.copyWith(
                            color: AppColors.navy,
                            fontWeight: FontWeight.w700,
                            fontSize: 13,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),

          if (business.hasOpeningHours)
            DetailCard(
              title: 'Opening hours',
              child: Column(
                children: <Widget>[
                  // Rendered in weekday order, not the map's alphabetical
                  // insertion order (which arrives fri, mon, sat…).
                  for (final String day in Fmt.weekOrder)
                    if (business.openingHours.containsKey(day))
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Row(
                          children: <Widget>[
                            Expanded(
                              child: Text(
                                Fmt.dayLabel(day),
                                style: AppTypography.bodySmall,
                              ),
                            ),
                            Text(
                              business.openingHours[day]!.isEmpty
                                  ? 'Closed'
                                  : business.openingHours[day]!
                                      .map((OpeningWindow w) => w.label)
                                      .join(', '),
                              style: AppTypography.bodySmall.copyWith(
                                color: business.openingHours[day]!.isEmpty
                                    ? AppColors.mutedForeground
                                    : AppColors.navy,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                ],
              ),
            ),

          if (_listings.isNotEmpty) ...<Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screen,
                AppSpacing.xxl,
                AppSpacing.screen,
                AppSpacing.md,
              ),
              child: Text(
                'Listings from ${business.displayName}',
                style: AppTypography.section,
              ),
            ),
            SizedBox(
              height: ListingCard.railHeight(context),
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding:
                    const EdgeInsets.symmetric(horizontal: AppSpacing.screen),
                physics: const BouncingScrollPhysics(),
                itemCount: _listings.length,
                separatorBuilder: (_, _) =>
                    const SizedBox(width: AppSpacing.md),
                itemBuilder: (BuildContext context, int index) {
                  final Listing listing = _listings[index];
                  return ListingCard(
                    listing: listing,
                    layout: ListingCardLayout.rail,
                    heroPrefix: 'business-${business.slug}',
                    onTap: () => Get.toNamed<void>(
                      Routes.listingPath(listing.slug),
                      arguments: <String, dynamic>{
                        'listing': listing,
                        'heroPrefix': 'business-${business.slug}',
                      },
                    ),
                  );
                },
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ContactRow extends StatelessWidget {
  const _ContactRow({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        constraints: const BoxConstraints(minHeight: AppSizes.minTouchTarget),
        child: Row(
          children: <Widget>[
            Icon(icon, size: 18, color: AppColors.teal),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.body,
              ),
            ),
            const Icon(
              Icons.chevron_right_rounded,
              size: 18,
              color: AppColors.border,
            ),
          ],
        ),
      ),
    );
  }
}
