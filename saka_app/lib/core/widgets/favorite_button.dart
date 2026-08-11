import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../features/favorites/favorites_controller.dart';

/// The heart.
///
/// Fills in the SAME FRAME as the tap — the controller updates optimistically
/// and rolls back only if the request fails. Waiting for a round trip before
/// filling a heart is the single most common way a marketplace app feels slow,
/// because it puts a network on the cheapest possible interaction.
class FavoriteButton extends StatefulWidget {
  const FavoriteButton({
    required this.slug,
    super.key,
    this.kind = FavoriteKind.listing,
    this.onAuthRequired,
    this.size = 20,
    this.onSurface = false,
  });

  final String slug;
  final FavoriteKind kind;

  /// Called when a guest taps. The caller decides what to do — this widget
  /// never navigates on its own.
  final VoidCallback? onAuthRequired;

  final double size;

  /// True when the heart sits on a photograph rather than on a card, where it
  /// needs its own translucent plate to stay visible over any image.
  final bool onSurface;

  @override
  State<FavoriteButton> createState() => _FavoriteButtonState();
}

enum FavoriteKind { listing, business }

class _FavoriteButtonState extends State<FavoriteButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pop = AnimationController(
    vsync: this,
    duration: AppMotion.base,
  );

  late final Animation<double> _scale = TweenSequence<double>(
    <TweenSequenceItem<double>>[
      // Down, then past 1.0, then settle. The overshoot is the acknowledgement
      // — it is what makes the tap feel received without a toast.
      TweenSequenceItem<double>(
        tween: Tween<double>(begin: 1, end: 0.8)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 25,
      ),
      TweenSequenceItem<double>(
        tween: Tween<double>(begin: 0.8, end: 1.18)
            .chain(CurveTween(curve: Curves.easeOutBack)),
        weight: 45,
      ),
      TweenSequenceItem<double>(
        tween: Tween<double>(begin: 1.18, end: 1)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 30,
      ),
    ],
  ).animate(_pop);

  @override
  void dispose() {
    _pop.dispose();
    super.dispose();
  }

  FavoritesController get _favorites => Get.find<FavoritesController>();

  bool get _isSaved => widget.kind == FavoriteKind.listing
      ? _favorites.isListingSaved(widget.slug)
      : _favorites.isBusinessSaved(widget.slug);

  Future<void> _toggle() async {
    // Animate only when SAVING. Playing a celebratory pop while removing
    // something reads as the app misunderstanding the gesture.
    if (!_isSaved) {
      _pop.forward(from: 0);
      HapticFeedback.lightImpact();
    } else {
      HapticFeedback.selectionClick();
    }

    void showError(String message) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
    }

    if (widget.kind == FavoriteKind.listing) {
      await _favorites.toggleListing(
        widget.slug,
        onAuthRequired: widget.onAuthRequired,
        onError: showError,
      );
    } else {
      await _favorites.toggleBusiness(
        widget.slug,
        onAuthRequired: widget.onAuthRequired,
        onError: showError,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _toggle,
      child: SizedBox(
        // 44×44 hit area regardless of the drawn size.
        width: AppSizes.minTouchTarget,
        height: AppSizes.minTouchTarget,
        child: Center(
          child: Container(
            width: widget.size + 16,
            height: widget.size + 16,
            decoration: widget.onSurface
                ? BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.92),
                    shape: BoxShape.circle,
                    boxShadow: AppShadows.floating,
                  )
                : null,
            alignment: Alignment.center,
            // Obx scoped to the icon ALONE. Wrapping the card would rebuild the
            // image and every label each time any heart in the app changed.
            child: Obx(() {
              final bool saved = _isSaved;
              return ScaleTransition(
                scale: _scale,
                child: Icon(
                  saved ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                  size: widget.size,
                  color: saved
                      ? AppColors.destructive
                      : (widget.onSurface
                          ? AppColors.navy
                          : AppColors.mutedForeground),
                  semanticLabel: saved ? 'Saved' : 'Save',
                ),
              );
            }),
          ),
        ),
      ),
    );
  }
}
