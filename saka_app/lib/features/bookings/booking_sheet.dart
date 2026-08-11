import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/booking.dart';
import '../../data/repositories/booking_repository.dart';
import '../../data/repositories/directory_repository.dart';
import '../../shared/widgets/saka_sheet.dart';
import '../../shared/widgets/saka_text_field.dart';
import '../auth/auth_controller.dart';

/// Booking a specialist.
///
/// Three steps in one sheet: pick a slot, give your details, confirm. Real
/// availability from the backend at every step — no slot is ever synthesised
/// here, and the grid is re-fetched after a conflict rather than guessed at.
///
/// **No payment UI, anywhere.** This backend has no payment provider and the
/// sheet says so in words: "Nothing is charged now."
class BookingSheet extends StatefulWidget {
  const BookingSheet({
    required this.specialistSlug,
    required this.specialistName,
    required this.service,
    super.key,
  });

  final String specialistSlug;
  final String specialistName;
  final SpecialistService service;

  static Future<Booking?> show(
    BuildContext context, {
    required String specialistSlug,
    required String specialistName,
    required SpecialistService service,
  }) {
    return SakaSheet.show<Booking>(
      context,
      title: service.name,
      child: BookingSheet(
        specialistSlug: specialistSlug,
        specialistName: specialistName,
        service: service,
      ),
    );
  }

  @override
  State<BookingSheet> createState() => _BookingSheetState();
}

class _BookingSheetState extends State<BookingSheet> {
  final DirectoryRepository _directory = Get.find<DirectoryRepository>();
  final BookingRepository _bookings = Get.find<BookingRepository>();

  final GlobalKey<FormState> _form = GlobalKey<FormState>();
  final TextEditingController _name = TextEditingController();
  final TextEditingController _phone = TextEditingController();
  final TextEditingController _note = TextEditingController();

  Availability? _availability;
  String? _selectedDate;
  String? _selectedTime;
  ApiException? _error;

  bool _loadingSlots = true;
  bool _submitting = false;
  Booking? _confirmed;

  /// Step 0 = pick a slot, 1 = details. Confirmation replaces the whole sheet.
  int _step = 0;

  @override
  void initState() {
    super.initState();
    final AuthController auth = Get.find<AuthController>();
    _name.text = auth.user?.fullName ?? '';
    _phone.text = auth.user?.phone ?? '';
    _loadSlots();
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadSlots() async {
    setState(() {
      _loadingSlots = true;
      _error = null;
    });

    try {
      final Availability availability = await _directory.specialistSlots(
        slug: widget.specialistSlug,
        serviceUuid: widget.service.uuid,
      );
      if (!mounted) return;
      setState(() {
        _availability = availability;
        _loadingSlots = false;
        // Pre-select the first day that actually has slots, so the grid is
        // never an empty rectangle waiting for a tap.
        final List<SlotDay> days = availability.daysWithSlots;
        if (days.isNotEmpty && _selectedDate == null) {
          _selectedDate = days.first.date;
        }
        // A previously chosen time may have been taken while the sheet was
        // open; drop it rather than submit a slot the grid no longer offers.
        if (_selectedTime != null && !_isStillAvailable(_selectedTime!)) {
          _selectedTime = null;
        }
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _loadingSlots = false;
      });
    }
  }

  bool _isStillAvailable(String time) {
    final SlotDay? day = _dayFor(_selectedDate);
    if (day == null) return false;
    return day.slots.any((TimeSlot s) => s.start == time);
  }

  SlotDay? _dayFor(String? date) {
    if (date == null || _availability == null) return null;
    for (final SlotDay day in _availability!.days) {
      if (day.date == date) return day;
    }
    return null;
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    if (_selectedDate == null || _selectedTime == null) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final Booking booking = await _bookings.create(
        serviceUuid: widget.service.uuid,
        date: _selectedDate!,
        startTime: _selectedTime!,
        customerName: _name.text,
        customerPhone: _phone.text,
        customerEmail: Get.find<AuthController>().user?.email,
        note: _note.text,
      );
      if (!mounted) return;
      HapticFeedback.mediumImpact();
      setState(() {
        _confirmed = booking;
        _submitting = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      final ApiException failure = ApiException.from(error);
      setState(() {
        _error = failure;
        _submitting = false;
      });

      // A 409 means somebody else took the slot between rendering and
      // submitting. The only correct response is to go back to the grid and
      // re-fetch it — retrying the same slot would fail forever.
      if (failure.kind == ApiErrorKind.conflict) {
        setState(() {
          _step = 0;
          _selectedTime = null;
        });
        await _loadSlots();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_confirmed != null) {
      return _Confirmation(
        booking: _confirmed!,
        specialistName: widget.specialistName,
        onDone: () => Navigator.of(context).pop(_confirmed),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        _ServiceHeader(service: widget.service),
        const SizedBox(height: AppSpacing.xl),

        if (_error != null && _error!.kind == ApiErrorKind.conflict)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.lg),
            child: _Notice(
              icon: Icons.event_busy_rounded,
              message: _error!.message,
              color: AppColors.warning,
            ),
          ),

        if (_step == 0) ..._slotStep() else ..._detailsStep(),
      ],
    );
  }

  List<Widget> _slotStep() {
    if (_loadingSlots) {
      return <Widget>[
        const Padding(
          padding: EdgeInsets.symmetric(vertical: AppSpacing.huge),
          child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
        ),
      ];
    }

    final Availability? availability = _availability;

    if (availability == null || availability.daysWithSlots.isEmpty) {
      return <Widget>[
        Padding(
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xxl),
          child: Column(
            children: <Widget>[
              const Icon(
                Icons.event_busy_outlined,
                size: 34,
                color: AppColors.mutedForeground,
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                'No times available',
                style: AppTypography.title,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: AppSpacing.xs),
              Text(
                '${widget.specialistName} has not opened any slots for this '
                'service in the next two weeks. Try contacting them directly.',
                textAlign: TextAlign.center,
                style: AppTypography.bodySmall,
              ),
            ],
          ),
        ),
      ];
    }

    final List<SlotDay> days = availability.daysWithSlots;
    final SlotDay? day = _dayFor(_selectedDate);

    return <Widget>[
      Text('Choose a day', style: AppTypography.label),
      const SizedBox(height: AppSpacing.md),
      SizedBox(
        height: 72,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          physics: const BouncingScrollPhysics(),
          itemCount: days.length,
          separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.sm),
          itemBuilder: (BuildContext context, int index) {
            final SlotDay d = days[index];
            return _DayChip(
              date: d.date,
              slotCount: d.slots.length,
              isSelected: d.date == _selectedDate,
              onTap: () => setState(() {
                _selectedDate = d.date;
                _selectedTime = null;
              }),
            );
          },
        ),
      ),

      const SizedBox(height: AppSpacing.xl),
      Text('Choose a time', style: AppTypography.label),
      const SizedBox(height: AppSpacing.md),

      if (day == null)
        Text('Pick a day first.', style: AppTypography.bodySmall)
      else
        Wrap(
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: <Widget>[
            for (final TimeSlot slot in day.slots)
              _TimeChip(
                label: slot.start,
                isSelected: slot.start == _selectedTime,
                onTap: () => setState(() => _selectedTime = slot.start),
              ),
          ],
        ),

      // The specialist's timezone, shown only when it differs from the device's
      // — a slot is 09:00 where the specialist is, and silently converting it
      // would show a customer abroad a time neither party agreed to.
      if (availability.timezone != DateTime.now().timeZoneName) ...<Widget>[
        const SizedBox(height: AppSpacing.md),
        Text(
          'Times shown in ${availability.timezone.replaceAll('_', ' ')}.',
          style: AppTypography.caption,
        ),
      ],

      const SizedBox(height: AppSpacing.xxl),
      ElevatedButton(
        onPressed: _selectedTime == null ? null : () => setState(() => _step = 1),
        child: const Text('Continue'),
      ),
    ];
  }

  List<Widget> _detailsStep() {
    return <Widget>[
      Form(
        key: _form,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            _SelectedSlot(
              date: _selectedDate!,
              time: _selectedTime!,
              onChange: () => setState(() => _step = 0),
            ),
            const SizedBox(height: AppSpacing.xl),
            SakaTextField(
              controller: _name,
              label: 'Your name',
              textCapitalization: TextCapitalization.words,
              errorText: _error?.fieldError('customer_name'),
              validator: (String? v) =>
                  (v ?? '').trim().length < 2 ? 'Enter your name' : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _phone,
              label: 'Phone number',
              hint: '+255 7xx xxx xxx',
              keyboardType: TextInputType.phone,
              helper: 'The specialist will confirm on this number.',
              errorText: _error?.fieldError('customer_phone'),
              validator: (String? v) => (v ?? '').trim().length < 7
                  ? 'Enter a reachable number'
                  : null,
            ),
            const SizedBox(height: AppSpacing.md),
            SakaTextField(
              controller: _note,
              label: 'Anything they should know? (optional)',
              maxLines: 3,
              errorText: _error?.fieldError('note'),
            ),

            if (_error != null &&
                _error!.fieldErrors.isEmpty &&
                _error!.kind != ApiErrorKind.conflict) ...<Widget>[
              const SizedBox(height: AppSpacing.md),
              _Notice(
                icon: Icons.error_outline_rounded,
                message: _error!.message,
                color: AppColors.destructive,
              ),
            ],

            const SizedBox(height: AppSpacing.lg),
            // The payment position, stated plainly. There is no provider behind
            // this backend and the app must not imply otherwise.
            _Notice(
              icon: Icons.info_outline_rounded,
              message: 'Nothing is charged now. You will agree payment '
                  'directly with the specialist.',
              color: AppColors.mutedForeground,
            ),

            const SizedBox(height: AppSpacing.xl),
            ElevatedButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Text('Request booking'),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextButton(
              onPressed: _submitting ? null : () => setState(() => _step = 0),
              child: const Text('Back'),
            ),
          ],
        ),
      ),
    ];
  }
}

class _ServiceHeader extends StatelessWidget {
  const _ServiceHeader({required this.service});

  final SpecialistService service;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.muted,
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
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
              ],
            ),
          ),
          Text(
            Fmt.price(service.price, showUnit: false),
            style: AppTypography.price.copyWith(fontSize: 15),
          ),
        ],
      ),
    );
  }
}

class _DayChip extends StatelessWidget {
  const _DayChip({
    required this.date,
    required this.slotCount,
    required this.isSelected,
    required this.onTap,
  });

  final String date;
  final int slotCount;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // "Tue 12 Aug", parsed WITHOUT a timezone conversion — a booking date is a
    // calendar date, not an instant.
    final String label = Fmt.apiDate(date);
    final List<String> parts = label.split(' ');

    return PressableScale(
      onTap: onTap,
      scale: 0.95,
      semanticLabel: '$label, $slotCount times available',
      child: AnimatedContainer(
        duration: AppMotion.instant,
        width: 64,
        decoration: BoxDecoration(
          color: isSelected ? AppColors.primary : AppColors.muted,
          borderRadius: AppRadius.mdAll,
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Text(
              parts.isNotEmpty ? parts[0].toUpperCase() : '',
              style: AppTypography.overline.copyWith(
                color: isSelected ? Colors.white70 : AppColors.mutedForeground,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              parts.length > 1 ? parts[1] : '',
              style: AppTypography.title.copyWith(
                fontSize: 18,
                color: isSelected ? Colors.white : AppColors.navy,
              ),
            ),
            Text(
              parts.length > 2 ? parts[2] : '',
              style: AppTypography.caption.copyWith(
                fontSize: 10.5,
                color: isSelected ? Colors.white70 : AppColors.mutedForeground,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TimeChip extends StatelessWidget {
  const _TimeChip({
    required this.label,
    required this.isSelected,
    required this.onTap,
  });

  final String label;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.94,
      child: AnimatedContainer(
        duration: AppMotion.instant,
        constraints: const BoxConstraints(
          minWidth: 76,
          minHeight: AppSizes.minTouchTarget,
        ),
        alignment: Alignment.center,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.primary : AppColors.muted,
          borderRadius: AppRadius.mdAll,
        ),
        child: Text(
          label,
          style: AppTypography.label.copyWith(
            color: isSelected ? Colors.white : AppColors.navy,
          ),
        ),
      ),
    );
  }
}

class _SelectedSlot extends StatelessWidget {
  const _SelectedSlot({
    required this.date,
    required this.time,
    required this.onChange,
  });

  final String date;
  final String time;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.07),
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
        children: <Widget>[
          const Icon(Icons.event_rounded, size: 19, color: AppColors.primary),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              '${Fmt.apiDate(date)} at $time',
              style: AppTypography.label.copyWith(color: AppColors.primary),
            ),
          ),
          TextButton(onPressed: onChange, child: const Text('Change')),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({
    required this.icon,
    required this.message,
    required this.color,
  });

  final IconData icon;
  final String message;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: AppRadius.mdAll,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icon, size: 17, color: color),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(
              message,
              style: AppTypography.caption.copyWith(color: color, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }
}

class _Confirmation extends StatelessWidget {
  const _Confirmation({
    required this.booking,
    required this.specialistName,
    required this.onDone,
  });

  final Booking booking;
  final String specialistName;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Center(
          child: Container(
            width: 68,
            height: 68,
            decoration: BoxDecoration(
              color: AppColors.success.withValues(alpha: 0.10),
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.check_rounded,
              size: 32,
              color: AppColors.success,
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text(
          'Booking requested',
          textAlign: TextAlign.center,
          style: AppTypography.headline,
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          // "Requested", never "confirmed": the booking is PENDING until the
          // specialist accepts, and the backend's own meta says exactly that.
          '$specialistName will confirm your appointment shortly. '
          'You will see the status in My bookings.',
          textAlign: TextAlign.center,
          style: AppTypography.bodySmall,
        ),
        const SizedBox(height: AppSpacing.xl),
        Container(
          padding: const EdgeInsets.all(AppSpacing.lg),
          decoration: BoxDecoration(
            color: AppColors.muted,
            borderRadius: AppRadius.mdAll,
          ),
          child: Column(
            children: <Widget>[
              _Line(label: 'Service', value: booking.serviceName ?? '—'),
              const SizedBox(height: AppSpacing.sm),
              _Line(label: 'Date', value: Fmt.apiDate(booking.scheduledDate)),
              const SizedBox(height: AppSpacing.sm),
              _Line(
                label: 'Time',
                value: '${booking.startTime} – ${booking.endTime}',
              ),
              const SizedBox(height: AppSpacing.sm),
              _Line(label: 'Reference', value: booking.uuid.split('-').first),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.xl),
        ElevatedButton(onPressed: onDone, child: const Text('Done')),
      ],
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Expanded(child: Text(label, style: AppTypography.caption)),
        Expanded(
          flex: 2,
          child: Text(
            value,
            textAlign: TextAlign.right,
            style: AppTypography.label,
          ),
        ),
      ],
    );
  }
}
