import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';

/// The real SAKA mark, from `saka_web_next/public/saka.PNG`.
///
/// A widget rather than a raw Image so nothing in the app can stretch it: the
/// asset's own aspect ratio is preserved and only the HEIGHT is ever specified,
/// which is the one dimension a logo can safely be sized by.
class SakaLogo extends StatelessWidget {
  const SakaLogo({super.key, this.height = 28, this.onDark = false});

  /// For a dark surface — the splash, a photographic header.
  ///
  /// The asset is a colour PNG, so it is not recoloured (tinting a multi-colour
  /// logo destroys it). Instead a subtle white plate sits behind it, which is
  /// what the web does over its dark hero sections.
  const SakaLogo.onDark({super.key, this.height = 28}) : onDark = true;

  final double height;
  final bool onDark;

  static const String _asset = 'assets/images/saka_logo.png';

  @override
  Widget build(BuildContext context) {
    final Widget mark = Image.asset(
      _asset,
      height: height,
      fit: BoxFit.contain,
      filterQuality: FilterQuality.medium,
      // Read out as the brand name, not as "image".
      semanticLabel: 'SAKA',
      errorBuilder: (BuildContext context, Object _, StackTrace? _) =>
          _Wordmark(height: height, onDark: onDark),
    );

    if (!onDark) return mark;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(6),
      ),
      child: mark,
    );
  }
}

/// Text fallback, used only if the asset fails to decode.
///
/// Not a design choice — an app that shows nothing where its logo belongs looks
/// broken, and a missing asset must degrade to something readable.
class _Wordmark extends StatelessWidget {
  const _Wordmark({required this.height, required this.onDark});

  final double height;
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    return Text(
      'SAKA',
      style: TextStyle(
        fontFamily: 'Urbanist',
        fontSize: height * 0.82,
        fontWeight: FontWeight.w800,
        letterSpacing: -0.5,
        color: onDark ? Colors.white : AppColors.navy,
      ),
    );
  }
}
