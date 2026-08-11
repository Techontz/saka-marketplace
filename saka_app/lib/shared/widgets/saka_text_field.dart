import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';

/// Every text input in this app.
///
/// The label sits ABOVE the field rather than floating inside it. A floating
/// Material label animates on focus, changes the field's height, and is the
/// single most Android-looking element in a form; a static label is calmer,
/// always readable, and does not move when the user starts typing.
class SakaTextField extends StatelessWidget {
  const SakaTextField({
    required this.controller,
    required this.label,
    super.key,
    this.hint,
    this.helper,
    this.errorText,
    this.keyboardType,
    this.textInputAction,
    this.obscureText = false,
    this.enabled = true,
    this.maxLines = 1,
    this.maxLength,
    this.autofillHints,
    this.inputFormatters,
    this.prefix,
    this.suffix,
    this.validator,
    this.onChanged,
    this.onSubmitted,
    this.textCapitalization = TextCapitalization.none,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final String? helper;

  /// A server-supplied error for this field, from a 422's `errors` map. Takes
  /// precedence over the local validator, because the server saw the whole
  /// request and the validator only saw one box.
  final String? errorText;

  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final bool obscureText;
  final bool enabled;
  final int maxLines;
  final int? maxLength;
  final List<String>? autofillHints;
  final List<TextInputFormatter>? inputFormatters;
  final Widget? prefix;
  final Widget? suffix;
  final String? Function(String?)? validator;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final TextCapitalization textCapitalization;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.only(left: 2, bottom: 6),
          child: Text(label, style: AppTypography.label),
        ),
        TextFormField(
          controller: controller,
          keyboardType: keyboardType,
          textInputAction: textInputAction,
          obscureText: obscureText,
          enabled: enabled,
          maxLines: obscureText ? 1 : maxLines,
          maxLength: maxLength,
          autofillHints: autofillHints,
          inputFormatters: inputFormatters,
          textCapitalization: textCapitalization,
          style: AppTypography.body,
          cursorColor: AppColors.primary,
          onChanged: onChanged,
          onFieldSubmitted: onSubmitted,
          validator: validator,
          // Validates as the user corrects a mistake, not while they are first
          // typing — flagging "invalid email" at the second keystroke is
          // nagging, not help.
          autovalidateMode: AutovalidateMode.onUserInteraction,
          decoration: InputDecoration(
            hintText: hint,
            helperText: helper,
            helperStyle: AppTypography.caption,
            errorText: errorText,
            prefixIcon: prefix,
            suffixIcon: suffix,
            // The counter is off by default; a "0/120" under every box is
            // clutter unless the limit is genuinely tight.
            counterText: '',
          ),
        ),
      ],
    );
  }
}

/// A search box. Same design language, but the shape and behaviour a search
/// field needs: rounded, prefixed, clearable, and never wrapped in a Form.
class SakaSearchField extends StatelessWidget {
  const SakaSearchField({
    required this.controller,
    required this.onChanged,
    super.key,
    this.hint = 'Search SAKA',
    this.autofocus = false,
    this.onSubmitted,
    this.onClear,
  });

  final TextEditingController controller;
  final ValueChanged<String> onChanged;
  final String hint;
  final bool autofocus;
  final ValueChanged<String>? onSubmitted;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      autofocus: autofocus,
      textInputAction: TextInputAction.search,
      style: AppTypography.body,
      cursorColor: AppColors.primary,
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(
          Icons.search_rounded,
          size: 21,
          color: AppColors.mutedForeground,
        ),
        // The clear button appears only when there is something to clear, and
        // is a full 44pt target despite the small glyph.
        suffixIcon: ValueListenableBuilder<TextEditingValue>(
          valueListenable: controller,
          builder: (BuildContext context, TextEditingValue value, Widget? _) {
            if (value.text.isEmpty) return const SizedBox.shrink();
            return IconButton(
              onPressed: () {
                controller.clear();
                onChanged('');
                onClear?.call();
              },
              icon: const Icon(Icons.close_rounded, size: 19),
              color: AppColors.mutedForeground,
              tooltip: 'Clear',
              constraints: const BoxConstraints(
                minWidth: AppSizes.minTouchTarget,
                minHeight: AppSizes.minTouchTarget,
              ),
            );
          },
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        border: const OutlineInputBorder(
          borderRadius: AppRadius.pillAll,
          borderSide: BorderSide.none,
        ),
        enabledBorder: const OutlineInputBorder(
          borderRadius: AppRadius.pillAll,
          borderSide: BorderSide.none,
        ),
        focusedBorder: const OutlineInputBorder(
          borderRadius: AppRadius.pillAll,
          borderSide: BorderSide(color: AppColors.primary, width: 1.6),
        ),
      ),
    );
  }
}
