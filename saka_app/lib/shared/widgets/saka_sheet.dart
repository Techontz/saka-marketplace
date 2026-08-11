import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';

/// Every bottom sheet in this app.
///
/// One presenter so the grab handle, the corner radius, the scrim, the keyboard
/// inset and the safe-area padding are identical everywhere. Sheets are the
/// app's primary modal surface — filters, location, sort, sign-in, booking —
/// and a stack of subtly different ones is the fastest way to make a product
/// feel assembled rather than designed.
abstract final class SakaSheet {
  static Future<T?> show<T>(
    BuildContext context, {
    required Widget child,
    String? title,
    bool isScrollControlled = true,
    bool isDismissible = true,
    double? heightFactor,
  }) {
    return showModalBottomSheet<T>(
      context: context,
      isScrollControlled: isScrollControlled,
      isDismissible: isDismissible,
      enableDrag: isDismissible,
      backgroundColor: Colors.transparent,
      barrierColor: AppColors.scrim,
      // Full height available; the sheet itself sizes to its content unless a
      // factor is given. A sheet that always fills the screen is a page.
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * 0.92,
      ),
      builder: (BuildContext context) => _SheetFrame(
        title: title,
        heightFactor: heightFactor,
        child: child,
      ),
    );
  }
}

class _SheetFrame extends StatelessWidget {
  const _SheetFrame({required this.child, this.title, this.heightFactor});

  final Widget child;
  final String? title;
  final double? heightFactor;

  @override
  Widget build(BuildContext context) {
    // The keyboard inset is added to the bottom padding rather than resizing
    // the sheet, so a focused field lifts above the keyboard instead of being
    // covered by it.
    final double keyboard = MediaQuery.viewInsetsOf(context).bottom;
    final double bottomSafe = MediaQuery.paddingOf(context).bottom;

    final Widget body = Container(
      decoration: const BoxDecoration(
        color: AppColors.background,
        borderRadius: AppRadius.sheetTop,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const SizedBox(height: AppSpacing.sm),
          // The grab handle. Small, quiet, and the one affordance that tells a
          // first-time user this panel can be dragged away.
          Container(
            width: 38,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          if (title != null) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.screen,
              ),
              child: Row(
                children: <Widget>[
                  Expanded(child: Text(title!, style: AppTypography.title)),
                  IconButton(
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(Icons.close_rounded, size: 22),
                    color: AppColors.mutedForeground,
                    tooltip: 'Close',
                    constraints: const BoxConstraints(
                      minWidth: AppSizes.minTouchTarget,
                      minHeight: AppSizes.minTouchTarget,
                    ),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
          ],
          Flexible(
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                AppSpacing.screen,
                AppSpacing.xl,
                AppSpacing.screen,
                AppSpacing.xl + bottomSafe + keyboard,
              ),
              child: child,
            ),
          ),
        ],
      ),
    );

    if (heightFactor == null) return body;
    return FractionallySizedBox(heightFactor: heightFactor, child: body);
  }
}
