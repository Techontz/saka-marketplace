import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/pressable.dart';
import '../../../data/models/booking.dart';
import '../../../data/repositories/directory_repository.dart';
import '../../bookings/booking_sheet.dart';
import 'detail_sections.dart';

/// A specialist's bookable menu, on their listing page.
///
/// Loads lazily and renders NOTHING when the listing has no services — which is
/// every non-specialist listing, and also a specialist who has not published a
/// menu yet. Probing every listing for services would be a wasted request on
/// 95% of the catalogue, so the caller decides when to mount this.
class SpecialistServicesBlock extends StatefulWidget {
  const SpecialistServicesBlock({
    required this.slug,
    required this.specialistName,
    super.key,
  });

  final String slug;
  final String specialistName;

  @override
  State<SpecialistServicesBlock> createState() =>
      _SpecialistServicesBlockState();
}

class _SpecialistServicesBlockState extends State<SpecialistServicesBlock> {
  List<SpecialistService> _services = const <SpecialistService>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final List<SpecialistService> services =
          await Get.find<DirectoryRepository>()
              .specialistServices(widget.slug);
      if (!mounted) return;
      setState(() {
        _services = services.where((SpecialistService s) => s.isActive).toList();
        _loading = false;
      });
    } on Object {
      // A 404 here is the normal answer for a non-specialist listing. The block
      // simply disappears.
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading || _services.isEmpty) return const SizedBox.shrink();

    return DetailCard(
      title: 'Book a session',
      child: Column(
        children: <Widget>[
          for (int i = 0; i < _services.length; i++) ...<Widget>[
            if (i > 0) const Divider(height: AppSpacing.xl),
            _ServiceRow(
              service: _services[i],
              onBook: () => BookingSheet.show(
                context,
                specialistSlug: widget.slug,
                specialistName: widget.specialistName,
                service: _services[i],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ServiceRow extends StatelessWidget {
  const _ServiceRow({required this.service, required this.onBook});

  final SpecialistService service;
  final VoidCallback onBook;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(service.name, style: AppTypography.label),
              const SizedBox(height: 2),
              Text(
                <String>[
                  service.durationLabel,
                  if (service.modeLabel != null) service.modeLabel!,
                ].join(' · '),
                style: AppTypography.caption,
              ),
              if (service.description != null) ...<Widget>[
                const SizedBox(height: 5),
                Text(
                  service.description!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodySmall,
                ),
              ],
              const SizedBox(height: AppSpacing.sm),
              Text(
                Fmt.price(service.price, showUnit: false),
                style: AppTypography.price.copyWith(fontSize: 15),
              ),
            ],
          ),
        ),
        const SizedBox(width: AppSpacing.md),
        PressableScale(
          onTap: onBook,
          scale: 0.95,
          semanticLabel: 'Book ${service.name}',
          child: Container(
            height: AppSizes.minTouchTarget,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
            alignment: Alignment.center,
            decoration: const BoxDecoration(
              color: AppColors.primary,
              borderRadius: AppRadius.mdAll,
            ),
            child: Text(
              'Book',
              style: AppTypography.button.copyWith(
                color: Colors.white,
                fontSize: 14,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
