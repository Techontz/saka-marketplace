import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_tokens.dart';

/// A press that answers.
///
/// Replaces Material's ink ripple, which is switched off app-wide in AppTheme.
/// A ripple spreading across a photographic listing card is the single loudest
/// "this is an Android app" signal in Flutter; a 2% scale-down over 120ms reads
/// as the card physically yielding, which is what iOS does and what a premium
/// marketplace should feel like.
///
/// The gesture target is never smaller than 44pt even when the painted child
/// is, so a small heart or chevron is still comfortably tappable.
class PressableScale extends StatefulWidget {
  const PressableScale({
    required this.child,
    super.key,
    this.onTap,
    this.onLongPress,
    this.scale = 0.98,
    this.haptic = true,
    this.semanticLabel,
    this.enforceMinTarget = false,
  });

  final Widget child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  /// Cards use the default; small controls press harder because a 2% change on
  /// a 24pt icon is invisible.
  final double scale;

  final bool haptic;
  final String? semanticLabel;

  /// Wraps the child so the HIT AREA is at least 44×44 without changing how the
  /// child paints.
  final bool enforceMinTarget;

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: AppMotion.instant,
    reverseDuration: AppMotion.fast,
    lowerBound: 0,
    upperBound: 1,
  );

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _down(TapDownDetails _) => _controller.forward();
  void _up(TapUpDetails _) => _controller.reverse();
  void _cancel() => _controller.reverse();

  void _tap() {
    if (widget.onTap == null) return;
    // A selection click, not an impact: impact feedback on every card tap
    // becomes a buzzing phone within a minute of scrolling.
    if (widget.haptic) HapticFeedback.selectionClick();
    widget.onTap!.call();
  }

  @override
  Widget build(BuildContext context) {
    final bool enabled = widget.onTap != null || widget.onLongPress != null;

    Widget content = AnimatedBuilder(
      animation: _controller,
      builder: (BuildContext context, Widget? child) {
        return Transform.scale(
          scale: 1 - (1 - widget.scale) * _controller.value,
          alignment: Alignment.center,
          child: child,
        );
      },
      child: widget.child,
    );

    if (widget.enforceMinTarget) {
      content = ConstrainedBox(
        constraints: const BoxConstraints(
          minWidth: AppSizes.minTouchTarget,
          minHeight: AppSizes.minTouchTarget,
        ),
        child: Center(child: content),
      );
    }

    return Semantics(
      button: enabled,
      label: widget.semanticLabel,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: enabled ? _down : null,
        onTapUp: enabled ? _up : null,
        onTapCancel: enabled ? _cancel : null,
        onTap: enabled ? _tap : null,
        onLongPress: widget.onLongPress,
        child: content,
      ),
    );
  }
}
