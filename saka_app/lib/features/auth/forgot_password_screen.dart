import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../shared/widgets/saka_text_field.dart';
import 'auth_controller.dart';

/// Request a reset link.
///
/// The screen never reveals whether an address is registered — the API does not
/// either, deliberately, because a "no such account" message turns a password
/// form into an account-enumeration oracle.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _email = TextEditingController();

  ApiException? _error;
  bool _busy = false;
  bool _sent = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    // See RegisterScreen: the keyboard's "done" action bypasses the disabled
    // button. Password reset is 3 per hour per email — a duplicate submit sends
    // the user a second email and burns a third of that budget.
    if (_busy) return;
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await Get.find<AuthController>().forgotPassword(_email.text);
      if (mounted) setState(() => _sent = true);
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
      appBar: AppBar(title: const Text('Reset password')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.screen),
        child: _sent
            ? Column(
                children: <Widget>[
                  const SizedBox(height: AppSpacing.xxl),
                  Container(
                    width: 64,
                    height: 64,
                    decoration: BoxDecoration(
                      color: AppColors.success.withValues(alpha: 0.10),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.mark_email_read_outlined,
                      size: 30,
                      color: AppColors.success,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Text('Check your email', style: AppTypography.title),
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    'If ${_email.text.trim()} has a SAKA account, a reset link '
                    'is on its way. Open it on this device to set a new '
                    'password.',
                    textAlign: TextAlign.center,
                    style: AppTypography.bodySmall,
                  ),
                  const SizedBox(height: AppSpacing.xxl),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () => Get.back<void>(),
                      child: const Text('Back to sign in'),
                    ),
                  ),
                ],
              )
            : Form(
                key: _form,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Text(
                      'Enter the email on your SAKA account and we will send '
                      'a link to reset your password.',
                      style: AppTypography.bodySmall,
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    SakaTextField(
                      controller: _email,
                      label: 'Email',
                      keyboardType: TextInputType.emailAddress,
                      errorText: _error?.fieldError('email'),
                      onSubmitted: (_) => _submit(),
                      validator: (String? v) => (v ?? '').trim().contains('@')
                          ? null
                          : 'Enter your email',
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
                    const SizedBox(height: AppSpacing.xl),
                    ElevatedButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              width: 20, height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2, color: Colors.white,
                              ),
                            )
                          : const Text('Send reset link'),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
