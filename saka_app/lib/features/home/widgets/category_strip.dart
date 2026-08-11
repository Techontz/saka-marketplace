import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/pressable.dart';
import '../../../data/models/category.dart';

/// The vertical picker.
///
/// Emoji, not icons. The seeded taxonomy carries an emoji per vertical ("🏠",
/// "🚗") and the web renders exactly those; substituting a Material icon set
/// would be a silent redesign, and would need a hardcoded slug→icon map that
/// breaks the moment an administrator adds a vertical.
class CategoryStrip extends StatelessWidget {
  const CategoryStrip({
    required this.categories,
    required this.onTap,
    super.key,
  });

  final List<Category> categories;
  final ValueChanged<Category> onTap;

  /// Icon tile + label + count, with only the text scaling.
  ///
  /// A fixed 96 overflowed by 15px on a real 1080×2400 device — the two text
  /// lines below the 52pt tile are taller than a constant can predict once the
  /// system font scale is involved.
  static double _heightFor(BuildContext context) {
    final double scale = MediaQuery.textScalerOf(context).scale(1);
    const double tile = 52;
    const double gap = 6;
    const double text = 17 + 15; // name + count
    const double padding = AppSpacing.md * 2;
    return tile + gap + (text * scale) + padding;
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: _heightFor(context),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.screen,
          vertical: AppSpacing.md,
        ),
        physics: const BouncingScrollPhysics(),
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
        itemBuilder: (BuildContext context, int index) {
          final Category category = categories[index];
          return _CategoryTile(
            category: category,
            onTap: () => onTap(category),
          );
        },
      ),
    );
  }
}

class _CategoryTile extends StatelessWidget {
  const _CategoryTile({required this.category, required this.onTap});

  final Category category;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.95,
      semanticLabel: '${category.name}, ${category.listingCount} listings',
      child: SizedBox(
        width: 76,
        child: Column(
          children: <Widget>[
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: AppColors.muted,
                borderRadius: AppRadius.lgAll,
              ),
              alignment: Alignment.center,
              child: Text(
                // Falls back to the first letter when a category has no emoji
                // — an empty tile reads as a broken image.
                category.icon ?? category.name.characters.first.toUpperCase(),
                style: const TextStyle(fontSize: 24, height: 1),
              ),
            ),
            const SizedBox(height: 6),
            Text(
              category.name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: AppTypography.caption.copyWith(
                color: AppColors.navy,
                fontWeight: FontWeight.w700,
                fontSize: 11.5,
              ),
            ),
            Text(
              Fmt.compactCount(category.listingCount),
              maxLines: 1,
              style: AppTypography.caption.copyWith(fontSize: 10.5),
            ),
          ],
        ),
      ),
    );
  }
}

/// The strip's skeleton, at the same height so nothing moves when it resolves.
class CategoryStripSkeleton extends StatelessWidget {
  const CategoryStripSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      // Same computation as the real strip, so nothing shifts on resolve.
      height: CategoryStrip._heightFor(context),
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.screen,
          vertical: AppSpacing.md,
        ),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 6,
        separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
        itemBuilder: (BuildContext context, int index) => SizedBox(
          width: 76,
          child: Column(
            children: <Widget>[
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: AppColors.shimmerBase,
                  borderRadius: AppRadius.lgAll,
                ),
              ),
              const SizedBox(height: 8),
              Container(
                width: 48,
                height: 9,
                decoration: BoxDecoration(
                  color: AppColors.shimmerBase,
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
