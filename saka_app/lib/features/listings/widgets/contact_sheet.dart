import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/errors/api_exception.dart';
import '../../../core/widgets/pressable.dart';
import '../../../data/models/listing.dart';
import '../../../data/repositories/listing_repository.dart';
import '../../../shared/widgets/saka_sheet.dart';
import '../../../shared/widgets/saka_text_field.dart';
import '../../auth/auth_controller.dart';

/// How to reach a seller.
///
/// The message form posts to `/inquiries` — the real primitive this backend
/// has. There is no realtime chat on SAKA and this app does not pretend
/// otherwise: no typing indicators, no message threads, no "seller is online".
/// An inquiry is an email-like enquiry the seller answers from their portal.
class ContactSheet extends StatefulWidget {
  const ContactSheet({required this.listing, super.key});

  final Listing listing;

  static Future<void> show(BuildContext context, {required Listing listing}) {
    return SakaSheet.show<void>(
      context,
      title: 'Contact seller',
      child: ContactSheet(listing: listing),
    );
  }

  @override
  State<ContactSheet> createState() => _ContactSheetState();
}

class _ContactSheetState extends State<ContactSheet> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _name = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _message = TextEditingController();

  ApiException? _error;
  bool _busy = false;
  bool _sent = false;

  @override
  void initState() {
    super.initState();
    // Prefilled for a signed-in user; a guest can still enquire, which is how
    // the web behaves and how most enquiries actually arrive.
    final AuthController auth = Get.find<AuthController>();
    _name.text = auth.user?.fullName ?? '';
    _phone.text = auth.user?.phone ?? '';
    _message.text = "Hello, I'm interested in \"${widget.listing.title}\". "
        'Is it still available?';
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (!(_form.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await Get.find<ListingRepository>().inquire(
        listingSlug: widget.listing.slug,
        name: _name.text.trim(),
        phone: _phone.text.trim(),
        email: Get.find<AuthController>().user?.email,
        message: _message.text.trim(),
      );
      if (!mounted) return;
      setState(() => _sent = true);
    } on Object catch (error) {
      if (!mounted) return;
      setState(() => _error = ApiException.from(error));
    } finally {
      if (mounted) setState(() => _busy = false);
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
    if (_sent) {
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              color: AppColors.success.withValues(alpha: 0.10),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.check_rounded, size: 30, color: AppColors.success),
          ),
          const SizedBox(height: AppSpacing.lg),
          Text('Message sent', style: AppTypography.title),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'The seller will see it in their SAKA inbox and reply on the '
            'number you gave.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Done'),
            ),
          ),
        ],
      );
    }

    final String? phone = widget.listing.seller?.phone;

    return Form(
      key: _form,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          // Direct channels first — most Tanzanian buyers would rather ring or
          // WhatsApp than fill in a form, and burying that under a form is how
          // a marketplace loses the contact.
          if (phone != null) ...<Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: _Channel(
                    icon: Icons.phone_rounded,
                    label: 'Call',
                    color: AppColors.teal,
                    onTap: () => _launch('tel:$phone'),
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: _Channel(
                    icon: Icons.chat_rounded,
                    label: 'WhatsApp',
                    color: const Color(0xFF25D366),
                    onTap: () => _launch(
                      'https://wa.me/${phone.replaceAll(RegExp(r'[^0-9]'), '')}'
                      '?text=${Uri.encodeComponent(_message.text)}',
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.xl),
            Row(
              children: <Widget>[
                const Expanded(child: Divider()),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                  child: Text('or send a message', style: AppTypography.caption),
                ),
                const Expanded(child: Divider()),
              ],
            ),
            const SizedBox(height: AppSpacing.xl),
          ],

          SakaTextField(
            controller: _name,
            label: 'Your name',
            textCapitalization: TextCapitalization.words,
            errorText: _error?.fieldError('name'),
            validator: (String? v) =>
                (v ?? '').trim().length < 2 ? 'Enter your name' : null,
          ),
          const SizedBox(height: AppSpacing.md),
          SakaTextField(
            controller: _phone,
            label: 'Your phone',
            hint: '+255 7xx xxx xxx',
            keyboardType: TextInputType.phone,
            helper: 'The seller will call or WhatsApp you on this number.',
            errorText: _error?.fieldError('phone'),
            validator: (String? v) =>
                (v ?? '').trim().length < 7 ? 'Enter a reachable number' : null,
          ),
          const SizedBox(height: AppSpacing.md),
          SakaTextField(
            controller: _message,
            label: 'Message',
            maxLines: 4,
            errorText: _error?.fieldError('message'),
            validator: (String? v) =>
                (v ?? '').trim().length < 5 ? 'Write a short message' : null,
          ),

          if (_error != null && _error!.fieldErrors.isEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            Text(
              _error!.message,
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.destructive,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],

          const SizedBox(height: AppSpacing.xl),
          ElevatedButton(
            onPressed: _busy ? null : _send,
            child: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Text('Send message'),
          ),
        ],
      ),
    );
  }
}

class _Channel extends StatelessWidget {
  const _Channel({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: label,
      child: Container(
        height: 56,
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.10),
          borderRadius: AppRadius.mdAll,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: 19, color: color),
            const SizedBox(width: AppSpacing.sm),
            Text(
              label,
              style: AppTypography.button.copyWith(color: color, fontSize: 14.5),
            ),
          ],
        ),
      ),
    );
  }
}
