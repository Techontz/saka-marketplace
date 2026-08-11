import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/states.dart';
import '../../data/repositories/vendor_repository.dart';
import '../../shared/widgets/saka_text_field.dart';

/// Identity verification.
///
/// **The app never stores, caches or logs a NIDA number.** It is typed, sent
/// once over TLS as multipart, and forgotten — the log interceptor redacts the
/// `document_number` key by name, nothing writes it to Hive or secure storage,
/// and the API only ever returns it masked (`•••• •••• •••• 6777`).
///
/// There is no automated check. The backend's own provider reports
/// `available: false` and this screen says so in the words the operator
/// approved, rather than leaving a vendor to assume a machine has stalled.
class VerificationScreen extends StatefulWidget {
  const VerificationScreen({super.key});

  @override
  State<VerificationScreen> createState() => _VerificationScreenState();
}

class _VerificationScreenState extends State<VerificationScreen> {
  final VendorRepository _repository = Get.find<VendorRepository>();
  final TextEditingController _number = TextEditingController();
  final ImagePicker _picker = ImagePicker();

  VerificationState? _state;
  ApiException? _error;
  bool _loading = true;
  bool _submitting = false;

  String? _type;
  XFile? _document;
  double _progress = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    // Cleared explicitly on the way out. The controller holds the typed digits
    // in memory and there is no reason for them to outlive the screen.
    _number.clear();
    _number.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = _state == null);
    try {
      final VerificationState state = await _repository.verifications();
      if (!mounted) return;
      setState(() {
        _state = state;
        _type ??= state.types.isEmpty ? null : state.types.first.value;
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

  Future<void> _pick() async {
    final XFile? file = await _picker.pickImage(
      source: ImageSource.gallery,
      // Compressed before upload: a 12MP phone photo of an ID card is ~6MB and
      // the backend caps images at 5MB. 1600px is well past legible for a card.
      maxWidth: 1600,
      imageQuality: 85,
    );
    if (file != null && mounted) setState(() => _document = file);
  }

  Future<void> _submit() async {
    final String? type = _type;
    final XFile? document = _document;
    if (type == null || document == null) return;

    setState(() {
      _submitting = true;
      _progress = 0;
      _error = null;
    });

    try {
      await _repository.submitVerification(
        type: type,
        filePath: document.path,
        documentNumber: _number.text,
        onProgress: (int sent, int total) {
          if (mounted && total > 0) setState(() => _progress = sent / total);
        },
      );
      if (!mounted) return;
      // Wiped the moment it has been sent.
      _number.clear();
      setState(() {
        _document = null;
        _submitting = false;
      });
      await _load();
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Identity verification')),
      body: Builder(
        builder: (BuildContext context) {
          if (_loading) {
            return const Center(
              child: CircularProgressIndicator(strokeWidth: 2),
            );
          }
          if (_state == null && _error != null) {
            return SakaErrorState(error: _error!, onRetry: _load);
          }

          final VerificationState state = _state!;
          final bool isNida = _type == 'national_id';
          final int digits = _number.text.replaceAll(RegExp(r'\D'), '').length;

          return ListView(
            padding: const EdgeInsets.all(AppSpacing.screen),
            children: <Widget>[
              // The honest statement, unconditional.
              Container(
                padding: const EdgeInsets.all(AppSpacing.lg),
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.07),
                  borderRadius: AppRadius.lgAll,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    const Icon(
                      Icons.shield_outlined,
                      size: 20,
                      color: AppColors.primary,
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            'Reviewed by a person',
                            style: AppTypography.label.copyWith(
                              color: AppColors.primary,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            state.automatedAvailable
                                ? 'Your document will be checked and you will '
                                    'be told either way.'
                                : 'Automated identity checks are not available '
                                    'in Tanzania yet. Identity verification is '
                                    'manually reviewed by authorized '
                                    'administrators, and your document is '
                                    'stored privately.',
                            style: AppTypography.caption,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

              if (state.requests.isNotEmpty) ...<Widget>[
                const SizedBox(height: AppSpacing.xxl),
                Text('Your documents', style: AppTypography.section),
                const SizedBox(height: AppSpacing.md),
                for (final VerificationRequest request in state.requests)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: _RequestRow(request: request),
                  ),
              ],

              const SizedBox(height: AppSpacing.xxl),
              Text('Submit a document', style: AppTypography.section),
              const SizedBox(height: AppSpacing.md),

              Wrap(
                spacing: AppSpacing.sm,
                runSpacing: AppSpacing.sm,
                children: <Widget>[
                  for (final ({String value, String label}) type in state.types)
                    PressableScale(
                      onTap: () => setState(() => _type = type.value),
                      scale: 0.95,
                      child: Container(
                        constraints:
                            const BoxConstraints(minHeight: AppSizes.minTouchTarget),
                        alignment: Alignment.center,
                        padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.lg,
                        ),
                        decoration: BoxDecoration(
                          color: _type == type.value
                              ? AppColors.primary
                              : AppColors.muted,
                          borderRadius: AppRadius.pillAll,
                        ),
                        child: Text(
                          type.label,
                          style: AppTypography.caption.copyWith(
                            color: _type == type.value
                                ? Colors.white
                                : AppColors.navy,
                            fontWeight: FontWeight.w700,
                            fontSize: 13,
                          ),
                        ),
                      ),
                    ),
                ],
              ),

              const SizedBox(height: AppSpacing.lg),
              SakaTextField(
                controller: _number,
                label: isNida ? 'NIDA number' : 'Document number (optional)',
                keyboardType: TextInputType.number,
                inputFormatters: <TextInputFormatter>[
                  // Digits, spaces and dashes: NIDA cards are printed grouped
                  // and the server normalises before validating.
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9\s-]')),
                ],
                helper: isNida
                    ? '${state.nidaDigits} digits, as printed on the card. '
                        'Dashes and spaces are fine.'
                    : 'Speeds up the review.',
                errorText: isNida && digits > 0 && digits != state.nidaDigits
                    ? '$digits of ${state.nidaDigits} digits.'
                    : _error?.fieldError('document_number'),
                onChanged: (_) => setState(() {}),
              ),

              const SizedBox(height: AppSpacing.lg),
              PressableScale(
                onTap: _pick,
                child: Container(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: AppRadius.lgAll,
                    border: Border.all(
                      color: _document == null
                          ? AppColors.border
                          : AppColors.primary,
                      width: _document == null ? 1 : 1.5,
                    ),
                  ),
                  child: Row(
                    children: <Widget>[
                      Icon(
                        _document == null
                            ? Icons.add_photo_alternate_outlined
                            : Icons.check_circle_rounded,
                        size: 22,
                        color: _document == null
                            ? AppColors.mutedForeground
                            : AppColors.success,
                      ),
                      const SizedBox(width: AppSpacing.md),
                      Expanded(
                        child: Text(
                          _document == null
                              ? 'Choose a photo of your document'
                              : _document!.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: AppTypography.body,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              if (_submitting && _progress > 0) ...<Widget>[
                const SizedBox(height: AppSpacing.md),
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: _progress,
                    minHeight: 5,
                    backgroundColor: AppColors.muted,
                  ),
                ),
              ],

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
                onPressed: _submitting || _document == null || _type == null
                    ? null
                    : _submit,
                child: _submitting
                    ? const SizedBox(
                        width: 20, height: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white,
                        ),
                      )
                    : const Text('Submit for review'),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _RequestRow extends StatelessWidget {
  const _RequestRow({required this.request});

  final VerificationRequest request;

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
                child: Text(
                  request.typeLabel ?? request.type.replaceAll('_', ' '),
                  style: AppTypography.label,
                ),
              ),
              SakaTag.status(
                request.status,
                request.statusLabel ?? request.status,
              ),
            ],
          ),
          // Masked, always. The API never sends the real digits and this app
          // has no code path that could produce them.
          if (request.maskedNumber != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(request.maskedNumber!, style: AppTypography.caption),
          ],
          if (request.reviewerNote != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(request.reviewerNote!, style: AppTypography.bodySmall),
          ],
          if (request.submittedAt != null) ...<Widget>[
            const SizedBox(height: 4),
            Text(
              'Submitted ${Fmt.relativeTime(request.submittedAt)}',
              style: AppTypography.caption.copyWith(fontSize: 11),
            ),
          ],
        ],
      ),
    );
  }
}
