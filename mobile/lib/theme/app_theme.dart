import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'app_text.dart';

/// Ukuran dasar dari Panduan Gaya: tombol utama 52, area sentuh min 44,
/// radius tombol/input 12, kartu 16, tepi layar 16.
abstract final class AppSizes {
  static const buttonHeight = 52.0;
  static const buttonHeightSmall = 48.0;
  static const inputHeight = 52.0;
  static const radiusControl = 12.0;
  static const radiusCard = 16.0;
  static const radiusSheet = 20.0;
  static const screenPadding = 16.0;
}

abstract final class AppTheme {
  static ThemeData light() {
    final controlShape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(AppSizes.radiusControl),
    );

    return ThemeData(
      useMaterial3: true,
      fontFamily: AppText.fontFamily,
      scaffoldBackgroundColor: AppColors.background,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.primary,
        primary: AppColors.primary,
        onPrimary: Colors.white,
        secondary: AppColors.accent,
        onSecondary: AppColors.onAccent,
        surface: AppColors.surface,
        onSurface: AppColors.textPrimary,
        error: AppColors.danger,
      ),
      textTheme: const TextTheme(
        headlineSmall: AppText.display,
        titleLarge: AppText.title,
        titleMedium: AppText.titleSmall,
        labelLarge: AppText.label,
        bodyMedium: AppText.body,
        bodySmall: AppText.caption,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(AppSizes.buttonHeight),
          shape: controlShape,
          textStyle: AppText.label.copyWith(fontSize: 16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.primary,
          minimumSize: const Size.fromHeight(AppSizes.buttonHeightSmall),
          side: const BorderSide(color: AppColors.primary, width: 1.5),
          shape: controlShape,
          textStyle: AppText.label,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 15,
        ),
        hintStyle: AppText.body.copyWith(color: AppColors.textMuted),
        prefixIconColor: AppColors.textSecondary,
        suffixIconColor: AppColors.textSecondary,
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppSizes.radiusControl),
          borderSide: const BorderSide(
            color: AppColors.borderStrong,
            width: 1.5,
          ),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppSizes.radiusControl),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppSizes.radiusControl),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.5),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AppSizes.radiusControl),
          borderSide: const BorderSide(color: AppColors.danger, width: 1.5),
        ),
      ),
    );
  }
}
