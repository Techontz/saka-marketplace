import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Type.
///
/// Urbanist, the web's `--font-display`, bundled as five static weights rather
/// than fetched at runtime — a font request over a slow mobile connection
/// blocks first paint, which is precisely what this product cannot afford.
///
/// No widget in this app writes a raw `fontSize`. Every size lives here so the
/// scale stays a scale.
abstract final class AppTypography {
  static const String fontFamily = 'Urbanist';

  /// Tight tracking on the big sizes, loose on the small ones. Large type set
  /// at default tracking looks accidentally spaced; small type set tight
  /// becomes unreadable at arm's length on a bright Dar es Salaam afternoon.
  static const TextStyle display = TextStyle(
    fontFamily: fontFamily,
    fontSize: 32,
    height: 1.15,
    fontWeight: FontWeight.w800,
    letterSpacing: -0.8,
    color: AppColors.navy,
  );

  static const TextStyle headline = TextStyle(
    fontFamily: fontFamily,
    fontSize: 24,
    height: 1.2,
    fontWeight: FontWeight.w800,
    letterSpacing: -0.5,
    color: AppColors.navy,
  );

  static const TextStyle title = TextStyle(
    fontFamily: fontFamily,
    fontSize: 19,
    height: 1.25,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.3,
    color: AppColors.navy,
  );

  /// Section headings — "Featured", "Near you". One notch above body, not two.
  static const TextStyle section = TextStyle(
    fontFamily: fontFamily,
    fontSize: 17,
    height: 1.3,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.2,
    color: AppColors.navy,
  );

  /// A listing card's title. Bounded to two lines everywhere it is used.
  static const TextStyle cardTitle = TextStyle(
    fontFamily: fontFamily,
    fontSize: 15,
    height: 1.3,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.1,
    color: AppColors.navy,
  );

  static const TextStyle body = TextStyle(
    fontFamily: fontFamily,
    fontSize: 15,
    height: 1.5,
    fontWeight: FontWeight.w400,
    color: AppColors.foreground,
  );

  static const TextStyle bodySmall = TextStyle(
    fontFamily: fontFamily,
    fontSize: 13.5,
    height: 1.45,
    fontWeight: FontWeight.w400,
    color: AppColors.mutedForeground,
  );

  static const TextStyle label = TextStyle(
    fontFamily: fontFamily,
    fontSize: 14,
    height: 1.2,
    fontWeight: FontWeight.w600,
    color: AppColors.navy,
  );

  static const TextStyle caption = TextStyle(
    fontFamily: fontFamily,
    fontSize: 12,
    height: 1.3,
    fontWeight: FontWeight.w500,
    letterSpacing: 0.1,
    color: AppColors.mutedForeground,
  );

  /// Badges: "VERIFIED", "SPONSORED". Small, heavy, widely tracked — the only
  /// place in the app that sets uppercase type.
  static const TextStyle overline = TextStyle(
    fontFamily: fontFamily,
    fontSize: 10,
    height: 1.2,
    fontWeight: FontWeight.w800,
    letterSpacing: 0.7,
  );

  /// Prices.
  ///
  /// A separate style because a price is the single most-scanned element on a
  /// marketplace card, and because `FontFeature.tabularFigures` keeps the
  /// digits on a fixed pitch — without it a scrolling column of prices jitters
  /// as the glyph widths change.
  static const TextStyle price = TextStyle(
    fontFamily: fontFamily,
    fontSize: 17,
    height: 1.2,
    fontWeight: FontWeight.w800,
    letterSpacing: -0.3,
    color: AppColors.teal,
    fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
  );

  static const TextStyle priceLarge = TextStyle(
    fontFamily: fontFamily,
    fontSize: 26,
    height: 1.15,
    fontWeight: FontWeight.w800,
    letterSpacing: -0.6,
    color: AppColors.teal,
    fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
  );

  static const TextStyle button = TextStyle(
    fontFamily: fontFamily,
    fontSize: 15.5,
    height: 1.2,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.1,
  );

  const AppTypography._();
}
