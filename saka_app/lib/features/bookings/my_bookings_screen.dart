import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/badges.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/booking.dart';
import '../../data/models/paginated.dart';
import '../../data/repositories/booking_repository.dart';
import '../../shared/widgets/paged_list.dart';

/// The customer's appointments, split upcoming / past.
///
/// Sorted on `starts_at_utc`, the one absolute instant on the record —
/// comparing the local date strings would order them wrongly across timezones.
class MyBookingsScreen extends StatefulWidget {
  const MyBookingsScreen({super.key});

  @override
  State<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends State<MyBookingsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this);
  final GlobalKey<PagedListState<Booking>> _upcomingKey =
      GlobalKey<PagedListState<Booking>>();

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final BookingRepository repository = Get.find<BookingRepository>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(
        title: const Text('My bookings'),
        bottom: TabBar(
          controller: _tabs,
          labelColor: AppColors.primary,
          unselectedLabelColor: AppColors.mutedForeground,
          indicatorColor: AppColors.primary,
          indicatorSize: TabBarIndicatorSize.label,
          labelStyle: AppTypography.label,
          tabs: const <Widget>[Tab(text: 'Upcoming'), Tab(text: 'Past')],
        ),
      ),
      body: TabBarView(
        controller: _tabs,
        children: <Widget>[
          PagedList<Booking>(
            key: _upcomingKey,
            fetch: (int page) async {
              final Paginated<Booking> result =
                  await repository.myBookings(page: page);
              return Paginated<Booking>(
                items: result.items
                    .where((Booking b) => b.isUpcoming)
                    .toList(growable: false),
                currentPage: result.currentPage,
                lastPage: result.lastPage,
                total: result.total,
              );
            },
            emptyIcon: Icons.event_available_outlined,
            emptyTitle: 'No upcoming bookings',
            emptyMessage: 'Book a lawyer, accountant, tutor or engineer from '
                'the Specialists directory.',
            itemBuilder: (BuildContext context, Booking booking, int _) =>
                _BookingCard(
              booking: booking,
              onCancelled: () => _upcomingKey.currentState?.reload(),
            ),
          ),
          PagedList<Booking>(
            fetch: (int page) async {
              final Paginated<Booking> result =
                  await repository.myBookings(page: page);
              return Paginated<Booking>(
                items: result.items
                    .where((Booking b) => !b.isUpcoming)
                    .toList(growable: false),
                currentPage: result.currentPage,
                lastPage: result.lastPage,
                total: result.total,
              );
            },
            emptyIcon: Icons.history_rounded,
            emptyTitle: 'Nothing here yet',
            emptyMessage: 'Past and cancelled appointments will appear here.',
            itemBuilder: (BuildContext context, Booking booking, int _) =>
                _BookingCard(booking: booking, onCancelled: () {}),
          ),
        ],
      ),
    );
  }
}

class _BookingCard extends StatelessWidget {
  const _BookingCard({required this.booking, required this.onCancelled});

  final Booking booking;
  final VoidCallback onCancelled;

  Future<void> _cancel(BuildContext context) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Cancel this booking?'),
        content: Text(
          'The specialist will be told the slot is free again. '
          '${Fmt.apiDate(booking.scheduledDate)} at ${booking.startTime}.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep it'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.destructive),
            child: const Text('Cancel booking'),
          ),
        ],
      ),
    );

    if (!(confirmed ?? false)) return;

    try {
      await Get.find<BookingRepository>().cancel(booking.uuid);
      onCancelled();
    } on Object catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ApiException.from(error).message)),
      );
    }
  }

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
                  booking.serviceName ?? 'Appointment',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.cardTitle,
                ),
              ),
              SakaTag.status(booking.status, booking.statusLabel),
            ],
          ),
          if (booking.specialistTitle != null) ...<Widget>[
            const SizedBox(height: 3),
            Text(
              booking.specialistTitle!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.caption,
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          Row(
            children: <Widget>[
              const Icon(
                Icons.event_rounded,
                size: 15,
                color: AppColors.mutedForeground,
              ),
              const SizedBox(width: 5),
              Text(
                '${Fmt.apiDate(booking.scheduledDate)} · '
                '${booking.startTime} – ${booking.endTime}',
                style: AppTypography.bodySmall.copyWith(
                  color: AppColors.navy,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
          if (booking.awaitsSpecialist) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              'Waiting for the specialist to confirm.',
              style: AppTypography.caption.copyWith(color: AppColors.warning),
            ),
          ],
          if (booking.specialistNote != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              booking.specialistNote!,
              style: AppTypography.caption,
            ),
          ],
          // The cancel action appears only when the BACKEND says the booking is
          // cancellable — the client never decides that from the status string.
          if (booking.isCancellable) ...<Widget>[
            const SizedBox(height: AppSpacing.md),
            PressableScale(
              onTap: () => _cancel(context),
              child: Container(
                height: AppSizes.minTouchTarget,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppColors.destructive.withValues(alpha: 0.07),
                  borderRadius: AppRadius.mdAll,
                ),
                child: Text(
                  'Cancel booking',
                  style: AppTypography.label.copyWith(
                    color: AppColors.destructive,
                    fontSize: 13.5,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
