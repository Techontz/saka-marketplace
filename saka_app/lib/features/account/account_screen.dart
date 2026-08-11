import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/config/app_config.dart';
import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_logo.dart';
import '../../data/models/user.dart';
import '../auth/auth_controller.dart';
import '../auth/sign_in_sheet.dart';
import '../bookings/my_bookings_screen.dart';
import '../vendor/vendor_dashboard_screen.dart';

/// The account centre.
///
/// One screen for both audiences. The vendor section appears only when the API
/// says the account HAS a seller profile — a buyer never sees a greyed-out
/// "My business" they cannot enter, and a vendor never has to find a second app.
class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AuthController auth = Get.find<AuthController>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Account')),
      body: Obx(() {
        final AppUser? user = auth.user;

        return ListView(
          physics: const BouncingScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.screen,
            AppSpacing.lg,
            AppSpacing.screen,
            AppSpacing.huge,
          ),
          children: <Widget>[
            if (user == null)
              _GuestCard(
                onSignIn: () => SignInSheet.show(context),
                onRegister: () => Get.toNamed<void>(Routes.register),
              )
            else
              _ProfileCard(user: user),

            const SizedBox(height: AppSpacing.xxl),

            if (user != null && user.isVendor) ...<Widget>[
              _Group(
                title: 'My business',
                children: <Widget>[
                  _Row(
                    icon: Icons.dashboard_outlined,
                    label: 'Vendor dashboard',
                    onTap: () => Get.to<void>(
                      () => const VendorDashboardScreen(),
                    ),
                  ),
                  _Row(
                    icon: Icons.verified_user_outlined,
                    label: 'Identity verification',
                    onTap: () => Get.toNamed<void>(Routes.vendorVerification),
                  ),
                  _Row(
                    icon: Icons.campaign_outlined,
                    label: 'Promotions',
                    onTap: () => Get.toNamed<void>(Routes.vendorPromotions),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.xxl),
            ],

            _Group(
              title: 'Activity',
              children: <Widget>[
                _Row(
                  icon: Icons.event_available_outlined,
                  label: 'My bookings',
                  onTap: () => user == null
                      ? SignInSheet.show(
                          context,
                          reason: 'Sign in to see your bookings.',
                        )
                      : Get.to<void>(() => const MyBookingsScreen()),
                ),
                _Row(
                  icon: Icons.notifications_none_rounded,
                  label: 'Notifications',
                  onTap: () => user == null
                      ? SignInSheet.show(context)
                      : Get.toNamed<void>(Routes.notifications),
                ),
                _Row(
                  icon: Icons.star_border_rounded,
                  label: 'My reviews',
                  onTap: () => user == null
                      ? SignInSheet.show(context)
                      : Get.toNamed<void>(Routes.myReviews),
                ),
                _Row(
                  icon: Icons.history_rounded,
                  label: 'Recently viewed',
                  onTap: () => user == null
                      ? SignInSheet.show(context)
                      : Get.toNamed<void>(Routes.recentlyViewed),
                ),
              ],
            ),

            const SizedBox(height: AppSpacing.xxl),

            _Group(
              title: 'Settings',
              children: <Widget>[
                if (user != null)
                  _Row(
                    icon: Icons.person_outline_rounded,
                    label: 'Edit profile',
                    onTap: () => Get.toNamed<void>(Routes.editProfile),
                  ),
                if (user != null)
                  _Row(
                    icon: Icons.lock_outline_rounded,
                    label: 'Change password',
                    onTap: () => Get.toNamed<void>(Routes.changePassword),
                  ),
                _Row(
                  icon: Icons.info_outline_rounded,
                  label: 'About SAKA',
                  onTap: () => Get.toNamed<void>(Routes.about),
                ),
              ],
            ),

            if (user != null) ...<Widget>[
              const SizedBox(height: AppSpacing.xxl),
              _Group(
                children: <Widget>[
                  _Row(
                    icon: Icons.logout_rounded,
                    label: 'Sign out',
                    isDestructive: true,
                    onTap: () => _confirmSignOut(context, auth),
                  ),
                ],
              ),
            ],

            const SizedBox(height: AppSpacing.xxl),
            const _Credit(),
          ],
        );
      }),
    );
  }

  Future<void> _confirmSignOut(
    BuildContext context,
    AuthController auth,
  ) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Sign out?'),
        content: const Text(
          'Your saved listings stay on your account and will be here when '
          'you sign back in.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.destructive),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );

    if (confirmed ?? false) await auth.signOut();
  }
}

class _GuestCard extends StatelessWidget {
  const _GuestCard({required this.onSignIn, required this.onRegister});

  final VoidCallback onSignIn;
  final VoidCallback onRegister;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.xl),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      child: Column(
        children: <Widget>[
          const SakaLogo(height: 30),
          const SizedBox(height: AppSpacing.lg),
          Text(
            'Sign in to SAKA',
            style: AppTypography.title,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'Save listings, book specialists and manage your enquiries.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(onPressed: onSignIn, child: const Text('Sign in')),
          ),
          const SizedBox(height: AppSpacing.sm),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton(
              onPressed: onRegister,
              child: const Text('Create an account'),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.user});

  final AppUser user;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        borderRadius: AppRadius.lgAll,
        boxShadow: AppShadows.card,
      ),
      child: Row(
        children: <Widget>[
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.10),
              shape: BoxShape.circle,
            ),
            alignment: Alignment.center,
            child: Text(
              user.initials,
              style: AppTypography.title.copyWith(color: AppColors.primary),
            ),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  user.fullName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.title,
                ),
                const SizedBox(height: 2),
                Text(
                  user.email,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.caption,
                ),
                const SizedBox(height: AppSpacing.sm),
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: <Widget>[
                    if (user.sellerProfile?.isVerified ?? false)
                      const VerifiedBadge(compact: true),
                    // Phone verification gates publishing on this backend, so
                    // its absence is worth surfacing rather than hiding.
                    if (!user.phoneVerified)
                      SakaTag(
                        label: 'Phone not verified',
                        icon: Icons.info_outline_rounded,
                        color: AppColors.warning,
                        background: AppColors.warning.withValues(alpha: 0.10),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Group extends StatelessWidget {
  const _Group({required this.children, this.title});

  final List<Widget> children;
  final String? title;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        if (title != null) ...<Widget>[
          Padding(
            padding: const EdgeInsets.only(left: 2, bottom: AppSpacing.sm),
            child: Text(title!, style: AppTypography.section),
          ),
        ],
        Container(
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: AppRadius.lgAll,
            boxShadow: AppShadows.card,
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: <Widget>[
              for (int i = 0; i < children.length; i++) ...<Widget>[
                if (i > 0) const Divider(height: 1, indent: 52),
                children[i],
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.icon,
    required this.label,
    required this.onTap,
    this.isDestructive = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool isDestructive;

  @override
  Widget build(BuildContext context) {
    final Color color = isDestructive ? AppColors.destructive : AppColors.navy;

    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      semanticLabel: label,
      child: Container(
        constraints: const BoxConstraints(minHeight: 54),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        child: Row(
          children: <Widget>[
            Icon(icon, size: 20, color: color),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Text(
                label,
                style: AppTypography.body.copyWith(
                  color: color,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            if (!isDestructive)
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

/// The developer credit, matching the web footer. Quiet, and clearly
/// subordinate to SAKA's own brand.
class _Credit extends StatelessWidget {
  const _Credit();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        children: <Widget>[
          Text('SAKA Marketplace', style: AppTypography.caption),
          const SizedBox(height: 2),
          PressableScale(
            onTap: () => launchUrl(
              Uri.parse(AppConfig.developerUrl),
              mode: LaunchMode.externalApplication,
            ),
            child: Container(
              height: AppSizes.minTouchTarget,
              alignment: Alignment.center,
              child: Text(
                'Technology by ${AppConfig.developerName}',
                style: AppTypography.caption.copyWith(
                  color: AppColors.mutedForeground,
                  decoration: TextDecoration.underline,
                  fontSize: 11.5,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
