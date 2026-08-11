import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Spacing.
///
/// A 4pt scale. Named rather than numbered so a review can argue about
/// "should this be `md` or `lg`" instead of "why 14".
abstract final class AppSpacing {
  static const double xxs = 2;
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 20;
  static const double xxl = 24;
  static const double xxxl = 32;
  static const double huge = 48;

  /// The horizontal inset every screen's content sits inside. One value, so
  /// section headings, cards and body copy share an optical left edge — the
  /// single biggest difference between a designed screen and an assembled one.
  static const double screen = 20;

  const AppSpacing._();
}

/// Corner radii.
///
/// The web's `--radius` is 0.3125rem = 5px, which is very tight — right for
/// dense desktop tables, wrong for a thumb-sized mobile card. Mobile keeps the
/// 5px for small controls (chips, badges) where the brand reads, and opens up
/// for cards and sheets where iOS convention and touch ergonomics dominate.
abstract final class AppRadius {
  /// The web's exact `--radius`. Badges, chips, small inputs.
  static const double brand = 5;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 20;

  /// Bottom sheets. iOS uses a large top-corner radius and nothing else.
  static const double sheet = 24;
  static const double pill = 999;

  static const BorderRadius brandAll = BorderRadius.all(Radius.circular(brand));
  static const BorderRadius smAll = BorderRadius.all(Radius.circular(sm));
  static const BorderRadius mdAll = BorderRadius.all(Radius.circular(md));
  static const BorderRadius lgAll = BorderRadius.all(Radius.circular(lg));
  static const BorderRadius xlAll = BorderRadius.all(Radius.circular(xl));
  static const BorderRadius pillAll = BorderRadius.all(Radius.circular(pill));
  static const BorderRadius sheetTop = BorderRadius.vertical(
    top: Radius.circular(sheet),
  );

  const AppRadius._();
}

/// Elevation.
///
/// Deliberately almost invisible. A marketplace card carries a photograph; a
/// heavy drop shadow under it reads as a 2014 Android app. These are closer to
/// an edge than a shadow — enough to lift the card off `AppColors.page`.
abstract final class AppShadows {
  static const List<BoxShadow> none = <BoxShadow>[];

  static const List<BoxShadow> card = <BoxShadow>[
    BoxShadow(
      color: Color(0x0A0B1723),
      blurRadius: 12,
      offset: Offset(0, 2),
    ),
  ];

  static const List<BoxShadow> raised = <BoxShadow>[
    BoxShadow(
      color: Color(0x140B1723),
      blurRadius: 20,
      offset: Offset(0, 6),
    ),
  ];

  /// For a control that floats over content — the gallery close button, the
  /// map's locate button — where the background is an unknown photograph.
  static const List<BoxShadow> floating = <BoxShadow>[
    BoxShadow(
      color: Color(0x1F000000),
      blurRadius: 16,
      offset: Offset(0, 4),
    ),
  ];

  const AppShadows._();
}

/// Motion.
///
/// Everything lands between 150ms and 300ms. Below 120ms a transition reads as
/// a glitch; above ~350ms the user is waiting on the animation rather than on
/// the app, which is the exact feeling this product is trying to avoid.
abstract final class AppMotion {
  static const Duration instant = Duration(milliseconds: 120);
  static const Duration fast = Duration(milliseconds: 180);
  static const Duration base = Duration(milliseconds: 240);
  static const Duration slow = Duration(milliseconds: 320);

  /// iOS-like: quick to leave, gentle to arrive.
  static const Curve easeOut = Curves.easeOutCubic;
  static const Curve easeInOut = Curves.easeInOutCubic;

  /// The favourite heart. A little overshoot is the whole point of the
  /// interaction — it is the app saying "got it" without a toast.
  static const Curve spring = Curves.easeOutBack;

  const AppMotion._();
}

/// Sizes that carry a rule with them.
abstract final class AppSizes {
  /// WCAG 2.5.8 AAA, and Apple's HIG minimum. Every tappable thing in this app
  /// is at least this big, even when the *painted* control is smaller.
  static const double minTouchTarget = 44;

  static const double buttonHeight = 52;
  static const double inputHeight = 52;
  static const double bottomNavHeight = 64;
  static const double appBarHeight = 56;

  /// Listing card imagery. 4:3 for grid cards, 3:2 for the wider rails, 4:3
  /// for the detail hero — chosen to match the aspect ratios the backend's
  /// image variants are generated at, so nothing is letterboxed.
  static const double cardImageAspect = 4 / 3;
  static const double railImageAspect = 3 / 2;
  static const double heroImageAspect = 4 / 3;

  const AppSizes._();
}

/// The one place a border is defined.
abstract final class AppBorders {
  static const BorderSide hairline = BorderSide(
    color: AppColors.border,
    width: 1,
  );

  static Border get all => const Border.fromBorderSide(hairline);

  const AppBorders._();
}
