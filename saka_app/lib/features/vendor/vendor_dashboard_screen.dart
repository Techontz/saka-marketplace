import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/states.dart';
import '../../data/repositories/vendor_repository.dart';

/// The vendor's own numbers.
///
/// Every figure comes from `GET /seller/dashboard`. Nothing here is computed
/// on the client and nothing is invented — a vendor with no listings sees
/// zeroes and a route to their first listing, not a demo chart.
class VendorDashboardScreen extends StatefulWidget {
  const VendorDashboardScreen({super.key});

  @override
  State<VendorDashboardScreen> createState() => _VendorDashboardScreenState();
}

class _VendorDashboardScreenState extends State<VendorDashboardScreen> {
  VendorDashboard? _dashboard;
  ApiException? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = _dashboard == null);
    try {
      final VendorDashboard data =
          await Get.find<VendorRepository>().dashboard();
      if (!mounted) return;
      setState(() {
        _dashboard = data;
        _error = null;
        _loading = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('My business')),
      body: Builder(
        builder: (BuildContext context) {
          if (_loading) {
            return const Center(child: CircularProgressIndicator(strokeWidth: 2));
          }
          if (_error != null) {
            return SakaErrorState(error: _error!, onRetry: _load);
          }

          final VendorDashboard data = _dashboard!;

          return RefreshIndicator(
            onRefresh: _load,
            color: AppColors.primary,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(
                parent: BouncingScrollPhysics(),
              ),
              padding: const EdgeInsets.all(AppSpacing.screen),
              children: <Widget>[
                // Publishing is gated on phone verification by the backend, so
                // a vendor who cannot publish is told why before they spend
                // twenty minutes writing a listing.
                if (!data.canPublish)
                  _Callout(
                    icon: Icons.phone_android_rounded,
                    title: 'Verify your phone to publish',
                    message: 'SAKA requires a verified phone number before a '
                        'listing can go live.',
                    color: AppColors.warning,
                  ),

                if (data.profileCompletionPercent < 100) ...<Widget>[
                  const SizedBox(height: AppSpacing.md),
                  _ProfileProgress(data: data),
                ],

                const SizedBox(height: AppSpacing.xl),
                Text('Listings', style: AppTypography.section),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: _Stat(
                        label: 'Active',
                        value: data.activeListings,
                        color: AppColors.success,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: _Stat(
                        label: 'In review',
                        value: data.pendingListings,
                        color: AppColors.warning,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: _Stat(
                        label: 'Drafts',
                        value: data.draftListings,
                        color: AppColors.mutedForeground,
                      ),
                    ),
                  ],
                ),

                const SizedBox(height: AppSpacing.xl),
                Text('Engagement', style: AppTypography.section),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: _Stat(
                        label: 'Views (30 days)',
                        value: data.viewsLast30Days,
                        color: AppColors.primary,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: _Stat(
                        label: 'Saves',
                        value: data.totalFavorites,
                        color: AppColors.destructive,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: _Stat(
                        label: 'Enquiries',
                        value: data.totalInquiries,
                        color: AppColors.orange,
                        badge: data.unreadInquiries,
                      ),
                    ),
                  ],
                ),

                const SizedBox(height: AppSpacing.xl),
                _Link(
                  icon: Icons.inventory_2_outlined,
                  label: 'My listings',
                  detail: '${data.totalListings}',
                  onTap: () => Get.toNamed<void>(Routes.vendorListings),
                ),
                const SizedBox(height: AppSpacing.sm),
                _Link(
                  icon: Icons.verified_user_outlined,
                  label: 'Identity verification',
                  detail: data.sellerVerified ? 'Verified' : 'Not verified',
                  onTap: () => Get.toNamed<void>(Routes.vendorVerification),
                ),
                const SizedBox(height: AppSpacing.sm),
                _Link(
                  icon: Icons.campaign_outlined,
                  label: 'Promotions',
                  detail: 'Request a placement',
                  onTap: () => Get.toNamed<void>(Routes.vendorPromotions),
                ),

                const SizedBox(height: AppSpacing.xxl),
                // Stated rather than hidden: listing creation is a long
                // multi-step form the web already does well, and shipping a
                // half-built wizard would be worse than pointing at the one
                // that works.
                Container(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  decoration: BoxDecoration(
                    color: AppColors.muted,
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text('Creating a new listing',
                          style: AppTypography.label),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'The new-listing wizard — category attributes, photos '
                        'and pricing — is on the SAKA vendor portal at '
                        'saka.africa. Listings created there are managed here, '
                        'including plot boundaries.',
                        style: AppTypography.caption,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({
    required this.label,
    required this.value,
    required this.color,
    this.badge,
  });

  final String label;
  final int value;
  final Color color;
  final int? badge;

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
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(
                Fmt.compactCount(value),
                style: AppTypography.headline.copyWith(color: color),
              ),
              if (badge != null && badge! > 0) ...<Widget>[
                const SizedBox(width: 5),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 5,
                    vertical: 2,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.orange,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    '$badge new',
                    style: AppTypography.overline.copyWith(
                      color: Colors.white,
                      fontSize: 8,
                    ),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 2,
            style: AppTypography.caption.copyWith(fontSize: 11),
          ),
        ],
      ),
    );
  }
}

class _ProfileProgress extends StatelessWidget {
  const _ProfileProgress({required this.data});

  final VendorDashboard data;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text('Profile completeness',
                    style: AppTypography.label),
              ),
              Text(
                '${data.profileCompletionPercent}%',
                style: AppTypography.label.copyWith(color: AppColors.primary),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: data.profileCompletionPercent / 100,
              minHeight: 6,
              backgroundColor: AppColors.muted,
              valueColor:
                  const AlwaysStoppedAnimation<Color>(AppColors.primary),
            ),
          ),
          if (data.missingProfileFields.isNotEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              // The backend names the gaps, so the vendor is told what to do
              // rather than shown a bare percentage.
              'Still missing: ${data.missingProfileFields.map(_label).join(', ')}',
              style: AppTypography.caption,
            ),
          ],
        ],
      ),
    );
  }

  String _label(String key) => switch (key) {
        'display_name' => 'business name',
        'bio' => 'description',
        'logo' => 'logo',
        'phone_verified' => 'phone verification',
        'email_verified' => 'email verification',
        'whatsapp' => 'WhatsApp number',
        'has_published_listing' => 'your first listing',
        _ => key.replaceAll('_', ' '),
      };
}

class _Callout extends StatelessWidget {
  const _Callout({
    required this.icon,
    required this.title,
    required this.message,
    required this.color,
  });

  final IconData icon;
  final String title;
  final String message;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: AppRadius.lgAll,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 20, color: color),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(title, style: AppTypography.label.copyWith(color: color)),
                const SizedBox(height: 2),
                Text(message, style: AppTypography.caption),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Link extends StatelessWidget {
  const _Link({
    required this.icon,
    required this.label,
    required this.detail,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String detail;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        constraints: const BoxConstraints(minHeight: 54),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        decoration: const BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Row(
          children: <Widget>[
            Icon(icon, size: 20, color: AppColors.navy),
            const SizedBox(width: AppSpacing.md),
            Expanded(child: Text(label, style: AppTypography.label)),
            Text(detail, style: AppTypography.caption),
            const SizedBox(width: AppSpacing.xs),
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
