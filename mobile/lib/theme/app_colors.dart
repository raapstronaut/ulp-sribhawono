import 'package:flutter/material.dart';

/// Warna dari board "Panduan Gaya" pada desain POSKO.
/// Nama token sama dengan yang tertulis di desain.
abstract final class AppColors {
  static const primary = Color(0xFF0A4B8F);
  static const primaryDark = Color(0xFF07335F);
  static const primarySoft = Color(0xFFE3EEFB);

  static const accent = Color(0xFFFFC72C);
  static const onAccent = Color(0xFF1A1300);
  static const accentLabel = Color(0xFFFFE08A);

  static const background = Color(0xFFF2F5F9);
  static const surface = Color(0xFFFFFFFF);
  static const border = Color(0xFFE2E8F0);
  static const borderStrong = Color(0xFFCBD5E1);

  static const textPrimary = Color(0xFF0F1B2D);
  static const textSecondary = Color(0xFF4A5568);
  static const textMuted = Color(0xFF64748B);
  static const textLabel = Color(0xFF334155);
  static const onPrimaryMuted = Color(0xFFD6E4F5);

  static const danger = Color(0xFFB91C1C);

  // Progres WO
  static const progressDone = Color(0xFF15803D);
  static const progressActive = Color(0xFFEA580C);
  static const progressPending = borderStrong;

  // Status "menunggu sinkronisasi"
  static const syncWaitingBackground = Color(0xFFFEF3C7);
  static const syncWaitingBorder = Color(0xFFFCD34D);
  static const syncWaitingIcon = Color(0xFF92400E);
  static const syncWaitingText = Color(0xFF78350F);
  static const syncBadge = Color(0xFFB45309);
}
