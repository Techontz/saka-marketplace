import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/config/app_config.dart';
import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/listing_card.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_logo.dart';
import '../../data/models/listing.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/account_repository.dart';
import '../../shared/widgets/paged_list.dart';
import '../../shared/widgets/saka_text_field.dart';
import '../auth/auth_controller.dart';

/// Reviews the signed-in user has written.
class MyReviewsScreen extends StatelessWidget {
  const MyReviewsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AccountRepository repository = Get.find<AccountRepository>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('My reviews')),
      body: PagedList<Review>(
        fetch: (int page) => repository.myReviews(page: page),
        emptyIcon: Icons.star_border_rounded,
        emptyTitle: 'No reviews yet',
        emptyMessage: 'Reviews you write on listings will appear here.',
        itemBuilder: (BuildContext context, Review review, int _) => Container(
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
                  for (int i = 1; i <= 5; i++)
                    Icon(
                      i <= review.rating
                          ? Icons.star_rounded
                          : Icons.star_border_rounded,
                      size: 16,
                      color: AppColors.orange,
                    ),
                  const Spacer(),
                  Text(
                    Fmt.relativeTime(review.createdAt),
                    style: AppTypography.caption,
                  ),
                ],
              ),
              if (review.listingTitle != null) ...<Widget>[
                const SizedBox(height: AppSpacing.sm),
                Text(
                  review.listingTitle!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.label,
                ),
              ],
              if (review.body != null) ...<Widget>[
                const SizedBox(height: 4),
                Text(review.body!, style: AppTypography.bodySmall),
              ],
              if (review.hasReply) ...<Widget>[
                const SizedBox(height: AppSpacing.md),
                Container(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: AppColors.muted,
                    borderRadius: AppRadius.mdAll,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text('Seller replied', style: AppTypography.caption),
                      const SizedBox(height: 2),
                      Text(review.replyBody!, style: AppTypography.bodySmall),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Listings the user opened recently, from the server's own record.
class RecentlyViewedScreen extends StatelessWidget {
  const RecentlyViewedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AccountRepository repository = Get.find<AccountRepository>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Recently viewed')),
      body: PagedList<Listing>(
        fetch: (int page) => repository.recentlyViewed(page: page),
        emptyIcon: Icons.history_rounded,
        emptyTitle: 'Nothing viewed yet',
        emptyMessage: 'Listings you open will show up here so you can find '
            'them again.',
        itemBuilder: (BuildContext context, Listing listing, int _) =>
            ListingCard(
          listing: listing,
          layout: ListingCardLayout.list,
          heroPrefix: 'recent',
          onTap: () => Get.toNamed<void>(
            Routes.listingPath(listing.slug),
            arguments: <String, dynamic>{
              'listing': listing,
              'heroPrefix': 'recent',
            },
          ),
        ),
      ),
    );
  }
}

/// Edit the signed-in user's own details.
class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  late final TextEditingController _first;
  late final TextEditingController _last;
  late final TextEditingController _phone;

  ApiException? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final AuthController auth = Get.find<AuthController>();
    _first = TextEditingController(text: auth.user?.firstName ?? '');
    _last = TextEditingController(text: auth.user?.lastName ?? '');
    _phone = TextEditingController(text: auth.user?.phone ?? '');
  }

  @override
  void dispose() {
    _first.dispose();
    _last.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final AuthController auth = Get.find<AuthController>();
      await Get.find<AccountRepository>().updateProfile(<String, dynamic>{
        'first_name': _first.text.trim(),
        'last_name': _last.text.trim(),
        if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      });
      await auth.refreshUser();
      if (!mounted) return;
      Get.back<void>();
      Get.snackbar(
        'Saved',
        'Your profile has been updated.',
        snackPosition: SnackPosition.BOTTOM,
        margin: const EdgeInsets.all(AppSpacing.screen),
      );
    } on Object catch (error) {
      if (mounted) setState(() => _error = ApiException.from(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Edit profile')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screen),
          children: <Widget>[
            SakaTextField(
              controller: _first,
              label: 'First name',
              textCapitalization: TextCapitalization.words,
              errorText: _error?.fieldError('first_name'),
              validator: (String? v) =>
                  (v ?? '').trim().isEmpty ? 'Required' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _last,
              label: 'Last name',
              textCapitalization: TextCapitalization.words,
              errorText: _error?.fieldError('last_name'),
              validator: (String? v) =>
                  (v ?? '').trim().isEmpty ? 'Required' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _phone,
              label: 'Phone',
              keyboardType: TextInputType.phone,
              helper: 'Changing this will require verifying the new number '
                  'before you can publish listings.',
              errorText: _error?.fieldError('phone'),
            ),
            if (_error != null && _error!.fieldErrors.isEmpty) ...<Widget>[
              const SizedBox(height: AppSpacing.md),
              Text(
                _error!.message,
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.destructive,
                ),
              ),
            ],
            const SizedBox(height: AppSpacing.xxl),
            ElevatedButton(
              onPressed: _busy ? null : _save,
              child: _busy
                  ? const SizedBox(
                      width: 20, height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white,
                      ),
                    )
                  : const Text('Save changes'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Change password.
class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _current = TextEditingController();
  final TextEditingController _next = TextEditingController();
  final TextEditingController _confirm = TextEditingController();

  ApiException? _error;
  bool _busy = false;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await Get.find<AccountRepository>().changePassword(
        currentPassword: _current.text,
        password: _next.text,
        passwordConfirmation: _confirm.text,
      );
      if (!mounted) return;
      Get.back<void>();
      Get.snackbar(
        'Password changed',
        'Use your new password next time you sign in.',
        snackPosition: SnackPosition.BOTTOM,
        margin: const EdgeInsets.all(AppSpacing.screen),
      );
    } on Object catch (error) {
      if (mounted) setState(() => _error = ApiException.from(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Change password')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.screen),
          children: <Widget>[
            SakaTextField(
              controller: _current,
              label: 'Current password',
              obscureText: true,
              errorText: _error?.fieldError('current_password'),
              validator: (String? v) =>
                  (v ?? '').isEmpty ? 'Enter your current password' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _next,
              label: 'New password',
              obscureText: true,
              errorText: _error?.fieldError('password'),
              validator: (String? v) =>
                  (v ?? '').length < 8 ? 'At least 8 characters' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _confirm,
              label: 'Confirm new password',
              obscureText: true,
              validator: (String? v) =>
                  v != _next.text ? 'Passwords do not match' : null,
            ),
            if (_error != null && _error!.fieldErrors.isEmpty) ...<Widget>[
              const SizedBox(height: AppSpacing.md),
              Text(
                _error!.message,
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.destructive,
                ),
              ),
            ],
            const SizedBox(height: AppSpacing.xxl),
            ElevatedButton(
              onPressed: _busy ? null : _save,
              child: _busy
                  ? const SizedBox(
                      width: 20, height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white,
                      ),
                    )
                  : const Text('Change password'),
            ),
          ],
        ),
      ),
    );
  }
}

/// About.
class AboutScreen extends StatelessWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('About SAKA')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.screen),
        children: <Widget>[
          const Center(child: SakaLogo(height: 40)),
          const SizedBox(height: AppSpacing.lg),
          Text(
            'SAKA is a Tanzanian marketplace for property, vehicles, '
            'electronics, services and professional specialists — with '
            'verified sellers, real availability and no fake listings.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xxl),
          PressableScale(
            onTap: () => launchUrl(
              Uri.parse(AppConfig.webOrigin),
              mode: LaunchMode.externalApplication,
            ),
            child: Container(
              height: 54,
              alignment: Alignment.center,
              decoration: const BoxDecoration(
                color: AppColors.surface,
                borderRadius: AppRadius.lgAll,
                boxShadow: AppShadows.card,
              ),
              child: Text(
                // Derived from the configured origin, not hardcoded: a staging
                // build must not send the user to the production site.
                'Visit ${Uri.parse(AppConfig.webOrigin).host}',
                style: AppTypography.label,
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.xxxl),
          Center(
            child: Column(
              children: <Widget>[
                Text('Built by', style: AppTypography.caption),
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
                      AppConfig.developerName,
                      style: AppTypography.label.copyWith(
                        color: AppColors.primary,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
