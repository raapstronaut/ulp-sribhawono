import 'package:flutter/material.dart';

import '../theme/app_text.dart';

/// Status WO pada Panduan Gaya. Nilainya mengikuti WorkOrderStatus di backend;
/// "terlambat" bukan status tersimpan, melainkan dihitung dari batas waktu.
enum WoStatus {
  ditugaskan('Ditugaskan', Color(0xFFE3EEFB), Color(0xFF0A4B8F)),
  dikerjakan('Dikerjakan', Color(0xFFFFEDD5), Color(0xFF9A3412)),
  menungguVerifikasi(
    'Menunggu Verifikasi',
    Color(0xFFEDE9FE),
    Color(0xFF5B21B6),
  ),
  rescheduleDiajukan(
    'Reschedule Diajukan',
    Color(0xFFF1F5F9),
    Color(0xFF334155),
  ),
  selesai('Selesai', Color(0xFFDCFCE7), Color(0xFF166534)),
  terlambat('Terlambat', Color(0xFFFEE2E2), Color(0xFFB91C1C));

  const WoStatus(this.label, this.background, this.foreground);

  final String label;
  final Color background;
  final Color foreground;
}

class WoStatusChip extends StatelessWidget {
  const WoStatusChip(this.status, {super.key});

  final WoStatus status;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: status.background,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        status.label,
        style: AppText.caption.copyWith(
          fontWeight: FontWeight.w700,
          color: status.foreground,
        ),
      ),
    );
  }
}
