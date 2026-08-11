import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'app_colors.dart';
import 'app_tokens.dart';
import 'app_typography.dart';

/// The single ThemeData for SAKA.
///
/// Light only, deliberately. The web ships a `.dark` block but nothing in the
/// product ever switches to it — no toggle, no `prefers-color-scheme` handling
/// in the layout — so its dark palette has never been reviewed against real
/// listing photography. Shipping a mobile dark mode derived from unreviewed
/// tokens would invent a look the brand has not approved. The palette is ready
/// in AppColors if and when the web commits to one.
abstract final class AppTheme {
  static ThemeData get light {
    const ColorScheme scheme = ColorScheme.light(
      primary: AppColors.primary,
      onPrimary: AppColors.onPrimary,
      secondary: AppColors.orange,
      onSecondary: AppColors.onOrange,
      surface: AppColors.surface,
      onSurface: AppColors.foreground,
      error: AppColors.destructive,
      onError: Colors.white,
      outline: AppColors.border,
      surfaceContainerHighest: AppColors.muted,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      fontFamily: AppTypography.fontFamily,
      scaffoldBackgroundColor: AppColors.page,
      splashFactory: InkSparkle.splashFactory,

      // The ripple is Material's signature and reads as "Android app" on iOS.
      // Presses are acknowledged with a scale instead (see PressableScale).
      splashColor: Colors.transparent,
      highlightColor: Colors.transparent,

      textTheme: const TextTheme(
        displayLarge: AppTypography.display,
        headlineMedium: AppTypography.headline,
        titleLarge: AppTypography.title,
        titleMedium: AppTypography.section,
        titleSmall: AppTypography.cardTitle,
        bodyLarge: AppTypography.body,
        bodyMedium: AppTypography.body,
        bodySmall: AppTypography.bodySmall,
        labelLarge: AppTypography.label,
        labelMedium: AppTypography.caption,
        labelSmall: AppTypography.overline,
      ),

      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.background,
        surfaceTintColor: Colors.transparent,
        foregroundColor: AppColors.navy,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: true,
        titleTextStyle: AppTypography.title,
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          statusBarBrightness: Brightness.light,
        ),
      ),

      cardTheme: CardThemeData(
        color: AppColors.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.lgAll,
          side: AppBorders.hairline,
        ),
      ),

      dividerTheme: const DividerThemeData(
        color: AppColors.border,
        thickness: 1,
        space: 1,
      ),

      // --- inputs ------------------------------------------------------------
      //
      // Filled, not outlined. An outlined field on a white card draws two
      // competing rectangles; a filled field on `muted` reads as one object and
      // matches the web's input treatment.
      inputDecorationTheme: InputDecorationThemeData(
        filled: true,
        fillColor: AppColors.muted,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.lg,
        ),
        hintStyle: AppTypography.body.copyWith(
          color: AppColors.mutedForeground,
        ),
        labelStyle: AppTypography.label,
        errorStyle: AppTypography.caption.copyWith(
          color: AppColors.destructive,
          fontWeight: FontWeight.w600,
        ),
        border: const OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide.none,
        ),
        enabledBorder: const OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide.none,
        ),
        focusedBorder: const OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.primary, width: 1.6),
        ),
        errorBorder: const OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.destructive, width: 1.4),
        ),
        focusedErrorBorder: const OutlineInputBorder(
          borderRadius: AppRadius.mdAll,
          borderSide: BorderSide(color: AppColors.destructive, width: 1.6),
        ),
      ),

      // --- buttons -----------------------------------------------------------
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: AppColors.onPrimary,
          disabledBackgroundColor: AppColors.border,
          disabledForegroundColor: AppColors.mutedForeground,
          elevation: 0,
          minimumSize: const Size.fromHeight(AppSizes.buttonHeight),
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.mdAll),
          textStyle: AppTypography.button,
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.navy,
          minimumSize: const Size.fromHeight(AppSizes.buttonHeight),
          side: AppBorders.hairline,
          shape: const RoundedRectangleBorder(borderRadius: AppRadius.mdAll),
          textStyle: AppTypography.button,
        ),
      ),

      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.primary,
          // Even a bare text link is a 44pt target.
          minimumSize: const Size(AppSizes.minTouchTarget, AppSizes.minTouchTarget),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
          textStyle: AppTypography.button,
        ),
      ),

      // --- surfaces ----------------------------------------------------------
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.background,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        modalElevation: 0,
        shape: RoundedRectangleBorder(borderRadius: AppRadius.sheetTop),
        clipBehavior: Clip.antiAlias,
        showDragHandle: false,
        dragHandleColor: AppColors.border,
      ),

      dialogTheme: DialogThemeData(
        backgroundColor: AppColors.background,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.xlAll),
        titleTextStyle: AppTypography.title,
        contentTextStyle: AppTypography.body,
      ),

      chipTheme: ChipThemeData(
        backgroundColor: AppColors.muted,
        selectedColor: AppColors.primary,
        side: BorderSide.none,
        labelStyle: AppTypography.label,
        secondaryLabelStyle: AppTypography.label.copyWith(
          color: AppColors.onPrimary,
        ),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.sm,
        ),
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.pillAll),
      ),

      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.navy,
        contentTextStyle: AppTypography.body.copyWith(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.mdAll),
        elevation: 0,
      ),

      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.primary,
        linearTrackColor: AppColors.muted,
        circularTrackColor: AppColors.muted,
      ),

      // Cupertino page transitions on both platforms: the horizontal slide with
      // an interactive back-swipe is what makes a Flutter app stop feeling like
      // a Flutter app, and Android users read it as normal too.
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: <TargetPlatform, PageTransitionsBuilder>{
          TargetPlatform.android: CupertinoPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
        },
      ),
    );
  }

  const AppTheme._();
}
