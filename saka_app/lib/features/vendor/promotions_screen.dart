import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/badges.dart';
import '../../data/models/json.dart';
import '../../data/repositories/vendor_repository.dart';
import '../../shared/widgets/paged_list.dart';

/// Promotion requests.
///
/// **No payment UI, no pricing, no "paid" state.** SAKA has no payment provider
/// and the promotion architecture is deliberately payment-READY rather than
/// payment-enabled: a vendor submits a request, an administrator reviews it.
/// Inventing a checkout here would be the single most damaging thing this app
/// could do to the client's credibility.
class PromotionsScreen extends StatelessWidget {
  const PromotionsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final VendorRepository repository = Get.find<VendorRepository>();

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Promotions')),
      body: PagedList<Map<String, dynamic>>(
        fetch: (int page) => repository.promotions(page: page),
        emptyIcon: Icons.campaign_outlined,
        emptyTitle: 'No promotion requests',
        emptyMessage: 'Ask SAKA to feature one of your listings. Requests are '
            'reviewed by an administrator — nothing is charged.',
        header: Padding(
          padding: const EdgeInsets.only(bottom: AppSpacing.md),
          child: Container(
            padding: const EdgeInsets.all(AppSpacing.lg),
            decoration: BoxDecoration(
              color: AppColors.muted,
              borderRadius: AppRadius.lgAll,
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Icon(
                  Icons.info_outline_rounded,
                  size: 19,
                  color: AppColors.mutedForeground,
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Text(
                    'New promotion requests are created on the SAKA vendor '
                    'portal at saka.africa. This screen shows the status of '
                    'requests you have already submitted.',
                    style: AppTypography.caption,
                  ),
                ),
              ],
            ),
          ),
        ),
        itemBuilder: (BuildContext context, Map<String, dynamic> row, int _) {
          final String status = asStringOr(row['status'], 'draft');
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
                        asStringOr(row['headline'], 'Promotion request'),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.cardTitle,
                      ),
                    ),
                    SakaTag.status(
                      status,
                      asStringOr(row['status_label'], status),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                if (asString(row['placement_label']) case final String label)
                  Text(label, style: AppTypography.caption),
                if (asDate(row['created_at']) case final DateTime at) ...<Widget>[
                  const SizedBox(height: 4),
                  Text(
                    'Requested ${Fmt.relativeTime(at)}',
                    style: AppTypography.caption.copyWith(fontSize: 11),
                  ),
                ],
                if (asString(row['review_note']) case final String note) ...<Widget>[
                  const SizedBox(height: AppSpacing.sm),
                  Text(note, style: AppTypography.bodySmall),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}
