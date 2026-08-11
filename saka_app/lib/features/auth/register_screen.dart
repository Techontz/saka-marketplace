import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/widgets/saka_logo.dart';
import '../../shared/widgets/saka_text_field.dart';
import 'auth_controller.dart';

/// Create an account.
///
/// Only the fields `POST /auth/register` actually requires, plus an optional
/// phone. Asking for anything the API will not store is friction for nothing —
/// and publishing a listing later needs a VERIFIED phone, which is a separate
/// step the vendor dashboard explains rather than something to bury here.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _first = TextEditingController();
  final TextEditingController _last = TextEditingController();
  final TextEditingController _email = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _password = TextEditingController();
  final TextEditingController _confirm = TextEditingController();

  ApiException? _error;
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    for (final TextEditingController c in <TextEditingController>[
      _first, _last, _email, _phone, _password, _confirm,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await Get.find<AuthController>().register(
        firstName: _first.text,
        lastName: _last.text,
        email: _email.text,
        password: _password.text,
        passwordConfirmation: _confirm.text,
        phone: _phone.text,
      );
      if (!mounted) return;
      Get.back<void>();
    } on Object catch (error) {
      if (!mounted) return;
      setState(() => _error = ApiException.from(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.screen, 0, AppSpacing.screen, AppSpacing.huge,
          ),
          children: <Widget>[
            const Center(child: SakaLogo(height: 32)),
            const SizedBox(height: AppSpacing.xl),
            Text('Create your account', style: AppTypography.headline),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'Save listings, book specialists and sell on SAKA.',
              style: AppTypography.bodySmall,
            ),
            const SizedBox(height: AppSpacing.xxl),

            Row(
              children: <Widget>[
                Expanded(
                  child: SakaTextField(
                    controller: _first,
                    label: 'First name',
                    textCapitalization: TextCapitalization.words,
                    textInputAction: TextInputAction.next,
                    errorText: _error?.fieldError('first_name'),
                    validator: (String? v) =>
                        (v ?? '').trim().isEmpty ? 'Required' : null,
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: SakaTextField(
                    controller: _last,
                    label: 'Last name',
                    textCapitalization: TextCapitalization.words,
                    textInputAction: TextInputAction.next,
                    errorText: _error?.fieldError('last_name'),
                    validator: (String? v) =>
                        (v ?? '').trim().isEmpty ? 'Required' : null,
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _email,
              label: 'Email',
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autofillHints: const <String>[AutofillHints.email],
              errorText: _error?.fieldError('email'),
              validator: (String? v) {
                final String value = (v ?? '').trim();
                if (value.isEmpty) return 'Enter your email';
                if (!value.contains('@') || !value.contains('.')) {
                  return 'That does not look like an email';
                }
                return null;
              },
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _phone,
              label: 'Phone (optional)',
              hint: '+255 7xx xxx xxx',
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.next,
              helper: 'Needed later if you want to publish listings.',
              errorText: _error?.fieldError('phone'),
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _password,
              label: 'Password',
              obscureText: _obscure,
              textInputAction: TextInputAction.next,
              autofillHints: const <String>[AutofillHints.newPassword],
              errorText: _error?.fieldError('password'),
              suffix: IconButton(
                onPressed: () => setState(() => _obscure = !_obscure),
                // A screen reader announces the tooltip; without it this is an
                // unlabelled button on a password field.
                tooltip: _obscure ? 'Show password' : 'Hide password',
                icon: Icon(
                  _obscure
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  size: 20,
                  color: AppColors.mutedForeground,
                ),
              ),
              validator: (String? v) =>
                  (v ?? '').length < 8 ? 'At least 8 characters' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _confirm,
              label: 'Confirm password',
              obscureText: _obscure,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _submit(),
              validator: (String? v) =>
                  v != _password.text ? 'Passwords do not match' : null,
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

            const SizedBox(height: AppSpacing.xxl),
            ElevatedButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white,
                      ),
                    )
                  : const Text('Create account'),
            ),
          ],
        ),
      ),
    );
  }
}
