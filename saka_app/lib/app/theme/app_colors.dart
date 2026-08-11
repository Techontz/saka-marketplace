import 'package:flutter/material.dart';

/// The SAKA palette.
///
/// Every value is a direct conversion of a token in the web app's
/// `app/globals.css`, which declares them in OKLCH. Converting once, here, is
/// deliberate: the alternative is eyeballing "about the same teal" and shipping
/// a mobile app that is subtly a different brand from the website.
///
/// Nothing else in this app may write `Color(0xFF...)` inline. If a colour is
/// missing from this file, it either belongs here or the design is wrong.
abstract final class AppColors {
  // --- brand ---------------------------------------------------------------

  /// `--primary` / `--ring` — oklch(0.55 0.11 190).
  static const Color primary = Color(0xFF008580);

  /// `--teal` — oklch(0.58 0.11 190). A half-step lighter than primary, used
  /// where teal is decorative rather than interactive.
  static const Color teal = Color(0xFF008E89);

  /// `--orange` — oklch(0.72 0.19 55). The accent: used sparingly, for the one
  /// thing on a screen that must be found instantly.
  static const Color orange = Color(0xFFFB7C00);

  /// `--navy` — oklch(0.2 0.03 250). Headings and dark surfaces.
  static const Color navy = Color(0xFF0B1723);

  static const Color onPrimary = Color(0xFFFFFFFF);
  static const Color onOrange = Color(0xFFFFFFFF);
  static const Color onNavy = Color(0xFFFFFFFF);

  // --- surfaces ------------------------------------------------------------

  static const Color background = Color(0xFFFFFFFF);
  static const Color surface = Color(0xFFFFFFFF);

  /// `--page` — the faint blue-grey the web sits its cards on. Without it a
  /// white card on a white page has no edge and the layout flattens.
  static const Color page = Color(0xFFF4F7FA);

  static const Color foreground = Color(0xFF07121E);
  static const Color mutedForeground = Color(0xFF576574);
  static const Color muted = Color(0xFFEDF2F8);
  static const Color border = Color(0xFFE0E5EB);
  static const Color destructive = Color(0xFFE62B34);

  // --- semantic ------------------------------------------------------------

  static const Color success = Color(0xFF0E9F6E);
  static const Color warning = Color(0xFFD97706);

  /// Listing purpose badges, matching the web's `badgeColors` map so a "Rent"
  /// chip is the same blue in both products.
  static const Color purposeRent = Color(0xFF0EA5E9);
  static const Color purposeSale = teal;
  static const Color purposeLease = orange;
  static const Color purposeHire = navy;

  /// Skeletons. Two greys a hair apart — a shimmer with a strong contrast ratio
  /// reads as a broken image rather than as loading.
  static const Color shimmerBase = Color(0xFFE9EEF4);
  static const Color shimmerHighlight = Color(0xFFF7FAFC);

  /// The scrim behind bottom sheets and the gallery lightbox.
  static const Color scrim = Color(0x99000000);

  /// Gradient laid over gallery photos so white overlay text stays legible on
  /// a bright image. Not decoration — a contrast guarantee.
  static const List<Color> imageScrim = <Color>[
    Color(0x00000000),
    Color(0x8A000000),
  ];

  const AppColors._();
}
