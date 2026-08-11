import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/app_colors.dart';
import '../../../app/theme/app_tokens.dart';
import '../../../app/theme/app_typography.dart';
import '../../../core/widgets/badges.dart';
import '../../../core/widgets/pressable.dart';
import '../../../core/widgets/saka_image.dart';
import '../../../data/models/media.dart';
import '../../../data/models/misc.dart';
import '../../../data/repositories/ads_repository.dart';

/// A SAKA advertisement.
///
/// Three rules, all of them about not ruining the product:
///
///  1. **nothing renders when nothing is eligible** — no reserved box, no
///     placeholder, no heading. The strip is simply absent;
///  2. **the impression fires only once the creative has actually been seen**,
///     not when it is built. Counting a build would bill an advertiser for a
///     slot the user scrolled past between frames;
///  3. **it is labelled**. "SPONSORED", quietly but unmistakably.
///
/// There is no third-party ad SDK anywhere in this app. The web loads AdSense
/// only when a publisher id is configured and none exists for mobile, so
/// carrying an inert integration would be pure attack surface.
class AdStrip extends StatefulWidget {
  const AdStrip({
    required this.creatives,
    required this.placement,
    super.key,
  });

  final List<AdCreative> creatives;
  final String placement;

  @override
  State<AdStrip> createState() => _AdStripState();
}

class _AdStripState extends State<AdStrip> {
  /// Creatives already counted this session. An impression is counted once per
  /// creative per screen life, not once per scroll past it.
  final Set<String> _counted = <String>{};

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _countVisible();
  }

  @override
  void didUpdateWidget(AdStrip oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.creatives != widget.creatives) _countVisible();
  }

  /// The strip sits high on the home screen and is on screen as soon as it has
  /// content, so "rendered with content" is a truthful impression here. A slot
  /// further down the page would need a real visibility observer before it
  /// could claim the same.
  void _countVisible() {
    if (widget.creatives.isEmpty) return;
    final AdsRepository ads = Get.find<AdsRepository>();
    for (final AdCreative creative in widget.creatives) {
      if (_counted.add(creative.uuid)) {
        ads.recordImpression(creative.uuid, widget.placement);
      }
    }
  }

  Future<void> _open(AdCreative creative) async {
    Get.find<AdsRepository>().recordClick(creative.uuid, widget.placement);

    // The destination is whatever the backend put on the creative. The API
    // derives it server-side from the promoted listing — an advertiser never
    // supplies a raw URL — so there is nothing here to validate against
    // phishing that the backend has not already settled.
    final String? target = creative.ctaLabel;
    if (target == null) return;
    final Uri? uri = Uri.tryParse(target);
    if (uri == null || !uri.hasScheme) return;
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.creatives.isEmpty) return const SizedBox.shrink();

    final AdCreative creative = widget.creatives.first;

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.lg,
        AppSpacing.screen,
        0,
      ),
      child: PressableScale(
        onTap: () => _open(creative),
        scale: 0.99,
        semanticLabel: 'Sponsored: ${creative.headline ?? 'advertisement'}',
        child: Container(
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: AppRadius.lgAll,
            boxShadow: AppShadows.card,
          ),
          clipBehavior: Clip.antiAlias,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              if (creative.displayImage != null)
                Stack(
                  children: <Widget>[
                    // A fixed 3:1 box, matching the placement's declared mobile
                    // aspect ratio. The height is reserved before the image
                    // loads, so the rails below never jump.
                    AspectRatio(
                      aspectRatio: 3,
                      child: SakaImage.url(
                        url: creative.displayImage,
                        size: MediaSize.card,
                        fit: BoxFit.cover,
                      ),
                    ),
                    const Positioned(
                      top: AppSpacing.sm,
                      left: AppSpacing.sm,
                      child: SponsoredBadge(),
                    ),
                  ],
                ),
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          if (creative.displayImage == null) ...<Widget>[
                            const SponsoredBadge(),
                            const SizedBox(height: AppSpacing.sm),
                          ],
                          if (creative.headline != null)
                            Text(
                              creative.headline!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.cardTitle,
                            ),
                          if (creative.body != null) ...<Widget>[
                            const SizedBox(height: 2),
                            Text(
                              creative.body!,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: AppTypography.caption,
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    const Icon(
                      Icons.arrow_forward_rounded,
                      size: 18,
                      color: AppColors.primary,
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
