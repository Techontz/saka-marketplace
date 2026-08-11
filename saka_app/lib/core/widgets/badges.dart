import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';

/// Small labelled markers.
///
/// Kept to a handful of variants on purpose. A marketplace card can carry four
/// or five signals at once, and if each has its own colour the card becomes a
/// colour chart. Only VERIFIED and the purpose badge are coloured; everything
/// else is neutral.

/// "VERIFIED" — a trust signal, so it gets the brand teal and nothing else does.
class VerifiedBadge extends StatelessWidget {
  const VerifiedBadge({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 5 : 7,
        vertical: compact ? 2.5 : 3.5,
      ),
      decoration: const BoxDecoration(
        color: AppColors.teal,
        borderRadius: AppRadius.brandAll,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(Icons.check_rounded, size: compact ? 9 : 11, color: Colors.white),
          SizedBox(width: compact ? 2 : 3),
          Text(
            'VERIFIED',
            style: AppTypography.overline.copyWith(
              color: Colors.white,
              fontSize: compact ? 8 : 9.5,
            ),
          ),
        ],
      ),
    );
  }
}

/// Rent / Sale / Lease / Hire. Colour-coded to match the web's badge map.
class PurposeBadge extends StatelessWidget {
  const PurposeBadge({required this.purpose, super.key});

  /// The raw API value (`rent`), not the label.
  final String purpose;

  @override
  Widget build(BuildContext context) {
    final (Color color, String label) = switch (purpose) {
      'rent' => (AppColors.purposeRent, 'Rent'),
      'sale' => (AppColors.purposeSale, 'Sale'),
      'lease' => (AppColors.purposeLease, 'Lease'),
      'hire' => (AppColors.purposeHire, 'Hire'),
      _ => (AppColors.navy, purpose),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color,
        borderRadius: AppRadius.brandAll,
      ),
      child: Text(
        label,
        style: AppTypography.caption.copyWith(
          color: Colors.white,
          fontWeight: FontWeight.w800,
          fontSize: 11,
        ),
      ),
    );
  }
}

/// "Sponsored". Legally and ethically required on paid placement, and
/// deliberately quiet — a loud ad label is as intrusive as a loud ad.
class SponsoredBadge extends StatelessWidget {
  const SponsoredBadge({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.navy.withValues(alpha: 0.55),
        borderRadius: AppRadius.brandAll,
      ),
      child: Text(
        'SPONSORED',
        style: AppTypography.overline.copyWith(
          color: Colors.white,
          fontSize: 8.5,
        ),
      ),
    );
  }
}

/// A neutral pill for status and metadata.
class SakaTag extends StatelessWidget {
  const SakaTag({
    required this.label,
    super.key,
    this.icon,
    this.color,
    this.background,
  });

  final String label;
  final IconData? icon;
  final Color? color;
  final Color? background;

  /// For a booking or listing status, where the colour carries meaning.
  factory SakaTag.status(String status, String label) {
    final Color color = switch (status) {
      'published' || 'confirmed' || 'approved' || 'active' => AppColors.success,
      'pending' || 'pending_review' || 'draft' => AppColors.warning,
      'rejected' || 'cancelled' || 'declined' || 'expired' =>
        AppColors.destructive,
      _ => AppColors.mutedForeground,
    };
    return SakaTag(
      label: label,
      color: color,
      background: color.withValues(alpha: 0.10),
    );
  }

  @override
  Widget build(BuildContext context) {
    final Color fg = color ?? AppColors.mutedForeground;
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: 4,
      ),
      decoration: BoxDecoration(
        color: background ?? AppColors.muted,
        borderRadius: AppRadius.brandAll,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 12, color: fg),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: AppTypography.caption.copyWith(
              color: fg,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

/// Stars plus a count.
///
/// Renders NOTHING when there are no reviews. An empty five-star row reads as
/// "rated zero", which is a lie about a listing nobody has reviewed yet.
class SakaRating extends StatelessWidget {
  const SakaRating({
    required this.average,
    required this.count,
    super.key,
    this.compact = false,
  });

  final double average;
  final int count;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    if (count <= 0) return const SizedBox.shrink();

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Icon(
          Icons.star_rounded,
          size: compact ? 13 : 15,
          color: AppColors.orange,
        ),
        const SizedBox(width: 3),
        Text(
          average.toStringAsFixed(1),
          style: AppTypography.caption.copyWith(
            color: AppColors.navy,
            fontWeight: FontWeight.w800,
            fontSize: compact ? 11.5 : 12.5,
          ),
        ),
        const SizedBox(width: 3),
        Text(
          '($count)',
          style: AppTypography.caption.copyWith(fontSize: compact ? 11 : 12),
        ),
      ],
    );
  }
}

/// "Masaki, Kinondoni" with a pin. One line, always ellipsised.
class LocationRow extends StatelessWidget {
  const LocationRow({
    required this.label,
    super.key,
    this.compact = false,
    this.color,
  });

  final String label;
  final bool compact;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Icon(
          Icons.location_on_rounded,
          size: compact ? 12 : 14,
          color: color ?? AppColors.teal,
        ),
        const SizedBox(width: 3),
        Expanded(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTypography.caption.copyWith(
              color: color ?? AppColors.mutedForeground,
              fontSize: compact ? 11.5 : 12.5,
            ),
          ),
        ),
      ],
    );
  }
}
