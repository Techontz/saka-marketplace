import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/category.dart';
import '../../data/repositories/listing_repository.dart';
import '../home/home_controller.dart';
import '../listings/listings_screen.dart';
import '../search/search_screen.dart';

/// Explore: the full taxonomy, plus the other three verticals.
///
/// Home is a curated feed; this is the index. Every category the backend
/// publishes appears here with its real count, expandable to its children —
/// nothing is hardcoded, so a vertical added in the admin portal shows up on
/// the next launch.
class ExploreScreen extends StatefulWidget {
  const ExploreScreen({required this.scrollController, super.key});

  final ScrollController scrollController;

  @override
  State<ExploreScreen> createState() => _ExploreScreenState();
}

class _ExploreScreenState extends State<ExploreScreen>
    with AutomaticKeepAliveClientMixin {
  final Set<String> _expanded = <String>{};

  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    super.build(context);

    // Reuses the home controller's already-loaded taxonomy rather than fetching
    // it again — same data, same cache, one request per launch.
    final HomeController home = Get.find<HomeController>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(
        title: const Text('Explore'),
        actions: <Widget>[
          IconButton(
            onPressed: () => Get.to<void>(() => const SearchScreen()),
            icon: const Icon(Icons.search_rounded),
            tooltip: 'Search',
          ),
          const SizedBox(width: AppSpacing.xs),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: home.loadAll,
        color: AppColors.primary,
        child: Obx(() {
          final List<Category> categories = home.topCategories;

          return ListView(
            controller: widget.scrollController,
            physics: const AlwaysScrollableScrollPhysics(
              parent: BouncingScrollPhysics(),
            ),
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screen,
              AppSpacing.lg,
              AppSpacing.screen,
              AppSpacing.xxxl,
            ),
            children: <Widget>[
              _DirectoryRow(
                icon: Icons.storefront_rounded,
                color: AppColors.teal,
                title: 'Businesses',
                subtitle: 'Verified shops, agencies and service providers',
                onTap: () => Get.toNamed<void>(Routes.businesses),
              ),
              const SizedBox(height: AppSpacing.md),
              _DirectoryRow(
                icon: Icons.workspace_premium_rounded,
                color: AppColors.orange,
                title: 'Specialists',
                subtitle: 'Lawyers, accountants, tutors, engineers and more',
                onTap: () => Get.toNamed<void>(Routes.specialists),
              ),
              const SizedBox(height: AppSpacing.md),
              _DirectoryRow(
                icon: Icons.place_rounded,
                color: AppColors.navy,
                title: 'Public places',
                subtitle: 'Hospitals, schools, banks and stations near you',
                onTap: () => Get.toNamed<void>(Routes.places),
              ),

              const SizedBox(height: AppSpacing.xxl),
              Text('All categories', style: AppTypography.section),
              const SizedBox(height: AppSpacing.md),

              if (categories.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(AppSpacing.xxl),
                  child: Center(
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                )
              else
                Container(
                  decoration: const BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: AppRadius.lgAll,
                    boxShadow: AppShadows.card,
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: Column(
                    children: <Widget>[
                      for (int i = 0; i < categories.length; i++) ...<Widget>[
                        if (i > 0) const Divider(height: 1, indent: AppSpacing.lg),
                        _CategoryRow(
                          category: categories[i],
                          isExpanded: _expanded.contains(categories[i].slug),
                          onToggle: () => setState(() {
                            final String slug = categories[i].slug;
                            if (!_expanded.remove(slug)) _expanded.add(slug);
                          }),
                          onOpen: (Category target) => Get.to<void>(
                            () => ListingsScreen(
                              initialQuery:
                                  ListingQuery(categorySlug: target.slug),
                              title: target.name,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
            ],
          );
        }),
      ),
    );
  }
}

class _DirectoryRow extends StatelessWidget {
  const _DirectoryRow({
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.99,
      semanticLabel: title,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Row(
          children: <Widget>[
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.10),
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(icon, size: 21, color: color),
            ),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(title, style: AppTypography.label),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.caption,
                  ),
                ],
              ),
            ),
            const Icon(
              Icons.chevron_right_rounded,
              size: 20,
              color: AppColors.border,
            ),
          ],
        ),
      ),
    );
  }
}

class _CategoryRow extends StatelessWidget {
  const _CategoryRow({
    required this.category,
    required this.isExpanded,
    required this.onToggle,
    required this.onOpen,
  });

  final Category category;
  final bool isExpanded;
  final VoidCallback onToggle;
  final ValueChanged<Category> onOpen;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        PressableScale(
          // A vertical with children expands; a leaf opens straight away.
          // Making a leaf expand into nothing is the kind of dead interaction
          // that makes an app feel unfinished.
          onTap: category.hasChildren ? onToggle : () => onOpen(category),
          scale: 0.995,
          child: Container(
            constraints:
                const BoxConstraints(minHeight: AppSizes.minTouchTarget),
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.md,
            ),
            child: Row(
              children: <Widget>[
                Text(
                  category.icon ?? '•',
                  style: const TextStyle(fontSize: 19, height: 1),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Text(category.name, style: AppTypography.label),
                ),
                Text(
                  Fmt.compactCount(category.listingCount),
                  style: AppTypography.caption,
                ),
                const SizedBox(width: AppSpacing.sm),
                AnimatedRotation(
                  turns: isExpanded ? 0.25 : 0,
                  duration: AppMotion.fast,
                  child: Icon(
                    category.hasChildren
                        ? Icons.chevron_right_rounded
                        : Icons.arrow_forward_rounded,
                    size: 19,
                    color: AppColors.border,
                  ),
                ),
              ],
            ),
          ),
        ),
        AnimatedSize(
          duration: AppMotion.base,
          curve: AppMotion.easeOut,
          alignment: Alignment.topCenter,
          child: !isExpanded
              ? const SizedBox(width: double.infinity)
              : Container(
                  width: double.infinity,
                  color: AppColors.page,
                  child: Column(
                    children: <Widget>[
                      _ChildRow(
                        label: 'All ${category.name}',
                        count: category.listingCount,
                        onTap: () => onOpen(category),
                      ),
                      for (final Category child in category.children)
                        _ChildRow(
                          label: child.name,
                          count: child.listingCount,
                          onTap: () => onOpen(child),
                        ),
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}

class _ChildRow extends StatelessWidget {
  const _ChildRow({
    required this.label,
    required this.count,
    required this.onTap,
  });

  final String label;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        constraints: const BoxConstraints(minHeight: AppSizes.minTouchTarget),
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.huge,
          AppSpacing.md,
          AppSpacing.lg,
          AppSpacing.md,
        ),
        child: Row(
          children: <Widget>[
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.navy,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            Text(Fmt.compactCount(count), style: AppTypography.caption),
          ],
        ),
      ),
    );
  }
}
