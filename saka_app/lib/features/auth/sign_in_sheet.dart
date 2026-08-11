import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/widgets/saka_logo.dart';
import '../../shared/widgets/saka_sheet.dart';
import '../../shared/widgets/saka_text_field.dart';
import 'auth_controller.dart';

/// Sign in, without leaving the page you were on.
///
/// A sheet rather than a route because it is almost always reached from an
/// interrupted action — tapping a heart, opening Saved, starting a booking.
/// Pushing a full-screen login there loses the user's place and their intent;
/// a sheet dismisses back onto exactly what they were doing.
class SignInSheet extends StatefulWidget {
  const SignInSheet({super.key, this.reason});

  /// Why the app is asking. "Sign in to save this listing" converts far better
  /// than a bare login form, because it names what the user is about to get.
  final String? reason;

  static Future<bool> show(BuildContext context, {String? reason}) async {
    final bool? result = await SakaSheet.show<bool>(
      context,
      child: SignInSheet(reason: reason),
    );
    return result ?? false;
  }

  @override
  State<SignInSheet> createState() => _SignInSheetState();
}

class _SignInSheetState extends State<SignInSheet> {
  final TextEditingController _email = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final GlobalKey<FormState> _form = GlobalKey<FormState>();

  ApiException? _error;
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await Get.find<AuthController>().signIn(
        email: _email.text,
        password: _password.text,
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on Object catch (error) {
      if (!mounted) return;
      setState(() => _error = ApiException.from(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _form,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Center(child: SakaLogo(height: 30)),
          const SizedBox(height: AppSpacing.lg),
          Text(
            'Welcome back',
            textAlign: TextAlign.center,
            style: AppTypography.headline,
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            widget.reason ?? 'Sign in to continue on SAKA.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xxl),

          SakaTextField(
            controller: _email,
            label: 'Email',
            hint: 'you@example.com',
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.next,
            autofillHints: const <String>[AutofillHints.email],
            // A 422 from the server binds onto the field it names, so a
            // rejected email shows its message under the email box rather than
            // as a generic banner.
            errorText: _error?.fieldError('email'),
            validator: (String? value) {
              final String v = (value ?? '').trim();
              if (v.isEmpty) return 'Enter your email';
              if (!v.contains('@') || !v.contains('.')) {
                return 'That does not look like an email';
              }
              return null;
            },
          ),
          const SizedBox(height: AppSpacing.md),
          SakaTextField(
            controller: _password,
            label: 'Password',
            obscureText: _obscure,
            textInputAction: TextInputAction.done,
            autofillHints: const <String>[AutofillHints.password],
            errorText: _error?.fieldError('password'),
            onSubmitted: (_) => _submit(),
            suffix: IconButton(
              onPressed: () => setState(() => _obscure = !_obscure),
              icon: Icon(
                _obscure
                    ? Icons.visibility_outlined
                    : Icons.visibility_off_outlined,
                size: 20,
                color: AppColors.mutedForeground,
              ),
              tooltip: _obscure ? 'Show password' : 'Hide password',
            ),
            validator: (String? value) =>
                (value ?? '').isEmpty ? 'Enter your password' : null,
          ),

          // A non-field error — wrong credentials, a rate limit, no connection.
          if (_error != null && _error!.fieldErrors.isEmpty) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            _ErrorNote(message: _error!.message),
          ],

          const SizedBox(height: AppSpacing.sm),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: () {
                Navigator.of(context).pop(false);
                Get.toNamed<void>(Routes.forgotPassword);
              },
              child: const Text('Forgot password?'),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),

          ElevatedButton(
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Text('Sign in'),
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Text('New to SAKA?', style: AppTypography.bodySmall),
              TextButton(
                onPressed: () {
                  Navigator.of(context).pop(false);
                  Get.toNamed<void>(Routes.register);
                },
                child: const Text('Create an account'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ErrorNote extends StatelessWidget {
  const _ErrorNote({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.destructive.withValues(alpha: 0.07),
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Icon(
            Icons.error_outline_rounded,
            size: 17,
            color: AppColors.destructive,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              message,
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.destructive,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
