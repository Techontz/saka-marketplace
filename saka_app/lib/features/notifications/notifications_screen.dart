import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/misc.dart';
import '../../data/models/paginated.dart';
import '../../data/repositories/account_repository.dart';
import '../../shared/widgets/paged_list.dart';

/// The notification centre.
///
/// In-app only. This backend stores notifications in the `notifications` table
/// and exposes them over REST; it has no push infrastructure — no FCM
/// credentials, no device-token endpoint — so the app does not register for
/// push and does not pretend to. Adding Firebase purely to claim the feature
/// would be dead weight and a new privacy surface.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final GlobalKey<PagedListState<AppNotification>> _key =
      GlobalKey<PagedListState<AppNotification>>();
  final AccountRepository _repository = Get.find<AccountRepository>();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: <Widget>[
          TextButton(
            onPressed: () async {
              await _repository.markAllNotificationsRead();
              _key.currentState?.reload();
            },
            child: const Text('Mark all read'),
          ),
          const SizedBox(width: AppSpacing.xs),
        ],
      ),
      body: PagedList<AppNotification>(
        key: _key,
        fetch: (int page) async {
          final ({Paginated<AppNotification> page, int unreadCount}) result =
              await _repository.notifications(page: page);
          return result.page;
        },
        separatorHeight: AppSpacing.sm,
        emptyIcon: Icons.notifications_none_rounded,
        emptyTitle: 'No notifications',
        emptyMessage: 'Updates about your listings, enquiries and bookings '
            'will appear here.',
        itemBuilder: (BuildContext context, AppNotification item, int _) =>
            _NotificationTile(
          notification: item,
          onTap: () async {
            if (!item.isRead) {
              await _repository.markNotificationRead(item.id);
              _key.currentState?.reload();
            }
            if (item.listingSlug != null) {
              await Get.toNamed<void>(Routes.listingPath(item.listingSlug!));
            }
          },
        ),
      ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({required this.notification, required this.onTap});

  final AppNotification notification;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
          // An unread row carries a teal edge rather than a coloured fill —
          // enough to scan for, quiet enough not to shout.
          border: notification.isRead
              ? null
              : const Border(
                  left: BorderSide(color: AppColors.primary, width: 3),
                ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                color: AppColors.muted,
                borderRadius: AppRadius.smAll,
              ),
              child: const Icon(
                Icons.notifications_rounded,
                size: 16,
                color: AppColors.mutedForeground,
              ),
            ),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    notification.title ?? 'SAKA update',
                    style: AppTypography.label.copyWith(
                      fontWeight: notification.isRead
                          ? FontWeight.w600
                          : FontWeight.w800,
                    ),
                  ),
                  if (notification.body != null) ...<Widget>[
                    const SizedBox(height: 2),
                    Text(notification.body!, style: AppTypography.bodySmall),
                  ],
                  const SizedBox(height: 4),
                  Text(
                    Fmt.relativeTime(notification.createdAt),
                    style: AppTypography.caption.copyWith(fontSize: 11),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
