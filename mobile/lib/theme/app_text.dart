import 'package:flutter/material.dart';

import 'app_colors.dart';

/// Skala huruf dari board "Panduan Gaya" (ukuran/tinggi baris · ketebalan).
abstract final class AppText {
  static const fontFamily = 'PlusJakartaSans';

  /// display · 26/32 · 800
  static const display = TextStyle(
    fontFamily: fontFamily,
    fontSize: 26,
    height: 32 / 26,
    fontWeight: FontWeight.w800,
    color: AppColors.textPrimary,
  );

  /// title · 20/28 · 700
  static const title = TextStyle(
    fontFamily: fontFamily,
    fontSize: 20,
    height: 28 / 20,
    fontWeight: FontWeight.w700,
    color: AppColors.textPrimary,
  );

  /// titleSmall · 18/24 · 800
  static const titleSmall = TextStyle(
    fontFamily: fontFamily,
    fontSize: 18,
    height: 24 / 18,
    fontWeight: FontWeight.w800,
    color: AppColors.textPrimary,
  );

  /// label · 15/22 · 700
  static const label = TextStyle(
    fontFamily: fontFamily,
    fontSize: 15,
    height: 22 / 15,
    fontWeight: FontWeight.w700,
    color: AppColors.textPrimary,
  );

  /// body · 14/20 · 500
  static const body = TextStyle(
    fontFamily: fontFamily,
    fontSize: 14,
    height: 20 / 14,
    fontWeight: FontWeight.w500,
    color: AppColors.textPrimary,
  );

  /// caption · 12/16 · 600
  static const caption = TextStyle(
    fontFamily: fontFamily,
    fontSize: 12,
    height: 16 / 12,
    fontWeight: FontWeight.w600,
    color: AppColors.textSecondary,
  );

  /// overline · 13 · 700 · kapital (teks ditulis kapital oleh pemanggil)
  static const overline = TextStyle(
    fontFamily: fontFamily,
    fontSize: 13,
    height: 18 / 13,
    fontWeight: FontWeight.w700,
    letterSpacing: 13 * 0.06,
    color: AppColors.textSecondary,
  );
}
