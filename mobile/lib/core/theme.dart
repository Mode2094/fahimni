import 'package:flutter/material.dart';

import '../models/teacher_context.dart';

/// SHAHEAD Student App — premium design system.
///
/// Identity: education teal (matches the SHAHEAD web brand) + amber accent,
/// soft rounded clay-inspired surfaces, tinted layered shadows, Tajawal
/// (Arabic-first) type family. Light-first with a proper dark mode.
///
/// Tokens follow the 4/8 spacing rhythm, one corner-radius family, and a
/// single accent — the whole app renders through these tokens, never
/// ad-hoc hex values.
class AppColors {
  AppColors._();

  // Brand
  static const Color primary = Color(0xFF0D9488);
  static const Color primaryDeep = Color(0xFF0F766E);
  static const Color primaryBright = Color(0xFF14B8A6);
  static const Color primarySoft = Color(0xFFCCFBF1);
  static const Color accent = Color(0xFFD97706); // amber — single accent
  static const Color accentSoft = Color(0xFFFFF7ED);

  // Surfaces
  static const Color background = Color(0xFFF0FDFA);
  static const Color backgroundTop = Color(0xFFE6FFFA);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceMuted = Color(0xFFE8F1F4);

  // Text
  static const Color textPrimary = Color(0xFF134E4A);
  static const Color textSecondary = Color(0xFF5B7A75);
  static const Color textTertiary = Color(0xFF94A3A3);

  // Status
  static const Color success = Color(0xFF059669);
  static const Color successSoft = Color(0xFFD1FAE5);
  static const Color danger = Color(0xFFDC2626);
  static const Color dangerSoft = Color(0xFFFEE2E2);
  static const Color warning = Color(0xFFD97706);
  static const Color warningSoft = Color(0xFFFEF3C7);

  // Dark surface palette
  static const Color darkBackground = Color(0xFF0B1220);
  static const Color darkSurface = Color(0xFF111C2E);
  static const Color darkSurfaceElevated = Color(0xFF16233A);
  static const Color darkTextPrimary = Color(0xFFE7F6F2);
  static const Color darkTextSecondary = Color(0xFF9FB9B3);
}

class AppShadows {
  AppShadows._();

  /// Soft, teal-tinted layered shadow — never pure black.
  static const List<BoxShadow> card = [
    BoxShadow(color: Color(0x120D9488), blurRadius: 24, offset: Offset(0, 8)),
    BoxShadow(color: Color(0x0A0F766E), blurRadius: 6, offset: Offset(0, 2)),
  ];

  static const List<BoxShadow> cardSoft = [
    BoxShadow(color: Color(0x0D0D9488), blurRadius: 14, offset: Offset(0, 4)),
  ];

  static const List<BoxShadow> floating = [
    BoxShadow(color: Color(0x1F0D9488), blurRadius: 32, offset: Offset(0, 12)),
    BoxShadow(color: Color(0x0A0F766E), blurRadius: 8, offset: Offset(0, 4)),
  ];

  static const List<BoxShadow> button = [
    BoxShadow(color: Color(0x400F766E), blurRadius: 16, offset: Offset(0, 6)),
    BoxShadow(color: Color(0x220D9488), blurRadius: 4, offset: Offset(0, 2)),
  ];
}

class AppRadius {
  AppRadius._();

  static const double sm = 14;
  static const double md = 20;
  static const double lg = 28;
  static const double pill = 999;
}

class AppSpace {
  AppSpace._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 40;
  static const double giant = 48;
}

/// Builds the app theme from the validated Teacher Context branding
/// (primary color etc. come from the teacher landing page — same source as
/// the web). Falls back to the SHAHEAD brand teal when no branding exists.
class AppTheme {
  static Color _parseHex(String? hex, Color fallback) {
    if (hex == null || hex.isEmpty) return fallback;
    var value = hex.replaceFirst('#', '');
    if (value.length == 3) {
      value = value.split('').map((c) => '$c$c').join();
    }
    if (value.length != 6) return fallback;
    final parsed = int.tryParse(value, radix: 16);
    if (parsed == null) return fallback;
    return Color(0xFF000000 | parsed);
  }

  static ThemeData build(TeacherContext? teacher, {required Locale locale, required Brightness brightness}) {
    final isDark = brightness == Brightness.dark;
    final brand = _parseHex(teacher?.site?.primaryColor, AppColors.primary);

    final bg = isDark ? AppColors.darkBackground : AppColors.background;
    final surface = isDark ? AppColors.darkSurface : AppColors.surface;
    final textPrimary = isDark ? AppColors.darkTextPrimary : AppColors.textPrimary;
    final textSecondary = isDark ? AppColors.darkTextSecondary : AppColors.textSecondary;

    const fontFamily = 'Tajawal';

    final scheme = ColorScheme.fromSeed(
      seedColor: brand,
      primary: brand,
      secondary: AppColors.accent,
      brightness: brightness,
      surface: surface,
    );

    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      fontFamily: fontFamily,
      scaffoldBackgroundColor: bg,
    );

    return base.copyWith(
      textTheme: base.textTheme.apply(
        fontFamily: fontFamily,
        bodyColor: textPrimary,
        displayColor: textPrimary,
      ).copyWith(
        displayLarge: const TextStyle(fontSize: 40, fontWeight: FontWeight.w800, height: 1.1),
        displayMedium: const TextStyle(fontSize: 32, fontWeight: FontWeight.w800, height: 1.15),
        headlineMedium: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, height: 1.2),
        titleLarge: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, height: 1.25),
        titleMedium: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, height: 1.3),
        bodyLarge: TextStyle(fontSize: 16, fontWeight: FontWeight.w400, height: 1.6, color: textPrimary),
        bodyMedium: TextStyle(fontSize: 14, fontWeight: FontWeight.w400, height: 1.6, color: textSecondary),
        labelLarge: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        elevation: 0,
        centerTitle: true,
        foregroundColor: textPrimary,
        titleTextStyle: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: textPrimary, fontFamily: fontFamily),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        indicatorColor: AppColors.primarySoft,
        elevation: 0,
        height: 68,
        labelTextStyle: WidgetStatePropertyAll(
          TextStyle(fontFamily: fontFamily, fontWeight: FontWeight.w700, fontSize: 12),
        ),
        iconTheme: WidgetStatePropertyAll(
          IconThemeData(color: textSecondary, size: 26),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: brand,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(54),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, fontFamily: fontFamily),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: brand,
          side: BorderSide(color: brand.withValues(alpha: 0.35)),
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700, fontFamily: fontFamily),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? AppColors.darkSurfaceElevated : AppColors.surface,
        hintStyle: TextStyle(color: textSecondary.withValues(alpha: 0.7), fontSize: 14, fontFamily: fontFamily),
        prefixIconColor: textSecondary,
        contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 18),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          borderSide: BorderSide(color: AppColors.surfaceMuted, width: 1.2),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          borderSide: BorderSide(color: AppColors.surfaceMuted, width: 1.2),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          borderSide: BorderSide(color: brand, width: 1.8),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.4),
        ),
      ),
      dividerTheme: DividerThemeData(color: AppColors.surfaceMuted, thickness: 1, space: 1),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: isDark ? AppColors.darkSurfaceElevated : AppColors.textPrimary,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.lg)),
      ),
    );
  }
}

/// Small localization helper (AR/EN inline, same convention as the web app).
class L10n {
  static String tr(Locale locale, String ar, String en) =>
      locale.languageCode.startsWith('ar') ? ar : en;
}
