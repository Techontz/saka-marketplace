import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../errors/api_exception.dart';
import 'pressable.dart';

/// Empty, error and loading — the three states every collection in this app
/// must have, built once so they are consistent and impossible to skip.

/// Nothing here, and that is fine.
///
/// Always carries a specific line, never "No data". The difference between
/// "No listings found in this area" and "Nothing found" is the difference
/// between a user who widens their search and a user who closes the app.
class SakaEmptyState extends StatelessWidget {
  const SakaEmptyState({
    required this.icon,
    required this.title,
    super.key,
    this.message,
    this.actionLabel,
    this.onAction,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;

  /// For an empty rail inside a scrolling page, where a full-height empty state
  /// would push everything below it off screen.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: EdgeInsets.symmetric(
          horizontal: AppSpacing.xxxl,
          vertical: compact ? AppSpacing.xxl : AppSpacing.huge,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              width: compact ? 52 : 68,
              height: compact ? 52 : 68,
              decoration: const BoxDecoration(
                color: AppColors.muted,
                shape: BoxShape.circle,
              ),
              child: Icon(
                icon,
                size: compact ? 24 : 30,
                color: AppColors.mutedForeground,
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            Text(
              title,
              textAlign: TextAlign.center,
              style: compact ? AppTypography.label : AppTypography.title,
            ),
            if (message != null) ...<Widget>[
              const SizedBox(height: AppSpacing.sm),
              Text(
                message!,
                textAlign: TextAlign.center,
                style: AppTypography.bodySmall,
              ),
            ],
            if (actionLabel != null && onAction != null) ...<Widget>[
              const SizedBox(height: AppSpacing.xl),
              OutlinedButton(
                onPressed: onAction,
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size(0, AppSizes.minTouchTarget),
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.xxl,
                  ),
                ),
                child: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Something failed, and the copy says which thing.
///
/// The message comes from [ApiException], which has already turned the status
/// code into something a person can act on. A retry button appears only when
/// retrying could actually help — offering "Try again" on a 403 is a lie.
class SakaErrorState extends StatelessWidget {
  const SakaErrorState({
    required this.error,
    super.key,
    this.onRetry,
    this.compact = false,
  });

  final ApiException error;
  final VoidCallback? onRetry;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final IconData icon = switch (error.kind) {
      ApiErrorKind.offline => Icons.wifi_off_rounded,
      ApiErrorKind.timeout => Icons.schedule_rounded,
      ApiErrorKind.notFound => Icons.search_off_rounded,
      ApiErrorKind.forbidden => Icons.lock_outline_rounded,
      ApiErrorKind.unauthorized => Icons.person_off_outlined,
      _ => Icons.error_outline_rounded,
    };

    final String title = switch (error.kind) {
      ApiErrorKind.offline => 'No connection',
      ApiErrorKind.timeout => 'Taking too long',
      ApiErrorKind.notFound => 'Not found',
      ApiErrorKind.forbidden => 'Not allowed',
      ApiErrorKind.unauthorized => 'Session expired',
      _ => 'Something went wrong',
    };

    return Center(
      child: Padding(
        padding: EdgeInsets.symmetric(
          horizontal: AppSpacing.xxxl,
          vertical: compact ? AppSpacing.xxl : AppSpacing.huge,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              width: compact ? 52 : 68,
              height: compact ? 52 : 68,
              decoration: BoxDecoration(
                color: AppColors.destructive.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: Icon(
                icon,
                size: compact ? 24 : 30,
                color: AppColors.destructive,
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            Text(
              title,
              textAlign: TextAlign.center,
              style: compact ? AppTypography.label : AppTypography.title,
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              error.message,
              textAlign: TextAlign.center,
              style: AppTypography.bodySmall,
            ),
            // The request id is the one piece of internal detail worth showing,
            // and only on a server fault, where it is what makes a support
            // conversation solvable. Never a stack trace, never a status line.
            if (error.requestId != null &&
                error.kind == ApiErrorKind.server) ...<Widget>[
              const SizedBox(height: AppSpacing.xs),
              Text(
                'Reference ${error.requestId}',
                style: AppTypography.caption.copyWith(fontSize: 11),
              ),
            ],
            if (onRetry != null && error.isRetryable) ...<Widget>[
              const SizedBox(height: AppSpacing.xl),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: const Text('Try again'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size(0, AppSizes.minTouchTarget),
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.xxl,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// A shimmering block, sized like the thing it stands in for.
///
/// Skeletons are only ever used where the final layout is KNOWN. Where it is
/// not — a detail screen whose height depends on the description — a skeleton
/// would guess wrong and cause exactly the layout shift it was meant to avoid.
class SakaSkeleton extends StatelessWidget {
  const SakaSkeleton({
    super.key,
    this.width,
    this.height = 14,
    this.borderRadius,
  });

  final double? width;
  final double height;
  final BorderRadius? borderRadius;

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppColors.shimmerBase,
      highlightColor: AppColors.shimmerHighlight,
      period: const Duration(milliseconds: 1100),
      child: Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: AppColors.shimmerBase,
          borderRadius: borderRadius ?? AppRadius.smAll,
        ),
      ),
    );
  }
}

/// A prompt to sign in, shown where a feature genuinely needs an account.
///
/// Never a dead end: it explains what signing in unlocks and offers the action
/// inline, rather than bouncing the user to a login screen with no context.
class SakaAuthPrompt extends StatelessWidget {
  const SakaAuthPrompt({
    required this.title,
    required this.message,
    required this.onSignIn,
    super.key,
  });

  final String title;
  final String message;
  final VoidCallback onSignIn;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xxxl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              width: 68,
              height: 68,
              decoration: BoxDecoration(
                color: AppColors.primary.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.lock_open_rounded,
                size: 30,
                color: AppColors.primary,
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            Text(title, textAlign: TextAlign.center, style: AppTypography.title),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              textAlign: TextAlign.center,
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.xl),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: onSignIn,
                child: const Text('Sign in'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A thin bar that appears when the device loses its connection.
///
/// Deliberately not a blocking dialog: the app stays usable on cached content,
/// and the bar simply explains why nothing new is arriving.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({required this.onRetry, super.key});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.navy,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.screen,
            vertical: AppSpacing.sm,
          ),
          child: Row(
            children: <Widget>[
              const Icon(Icons.wifi_off_rounded, size: 16, color: Colors.white),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  "You're offline. Showing saved content.",
                  style: AppTypography.caption.copyWith(color: Colors.white),
                ),
              ),
              PressableScale(
                onTap: onRetry,
                child: Container(
                  height: AppSizes.minTouchTarget,
                  alignment: Alignment.center,
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.md,
                  ),
                  child: Text(
                    'Retry',
                    style: AppTypography.caption.copyWith(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
