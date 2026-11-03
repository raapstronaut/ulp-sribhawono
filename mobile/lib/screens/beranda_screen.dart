import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../mock/mock_beranda.dart';
import '../mock/mock_work_orders.dart';
import '../theme/app_colors.dart';
import '../theme/app_text.dart';
import '../theme/app_theme.dart';
import '../widgets/app_bottom_nav.dart';
import '../widgets/not_ready.dart';
import '../widgets/wo_status_chip.dart';
import 'daftar_wo_screen.dart';
import 'detail_wo_screen.dart';

/// Artboard desain "2 · Beranda Shift". Masih memakai data contoh.
class BerandaScreen extends StatelessWidget {
  const BerandaScreen({super.key, this.data = mockBeranda});

  final BerandaData data;

  void _openWorkOrders(BuildContext context) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => DaftarWoScreen(pendingSyncCount: data.pendingSyncCount),
      ),
    );
  }

  void _openNextTask(BuildContext context) {
    final code = data.nextTask?.code;
    final item = mockWorkOrders.where((wo) => wo.code == code).firstOrNull;
    if (item == null) {
      showNotReady(context, 'Detail WO');
      return;
    }

    Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => DetailWoScreen(item: item)));
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        body: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Header(
                data: data,
                onNotifications: () => showNotReady(context, 'Notifikasi'),
              ),
              // Pita biru di belakang kartu membuatnya tampak menimpa header.
              Stack(
                children: [
                  const Positioned(
                    top: 0,
                    left: 0,
                    right: 0,
                    height: 48,
                    child: ColoredBox(color: AppColors.primary),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSizes.screenPadding,
                    ),
                    child: _ProgressCard(data: data),
                  ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.all(AppSizes.screenPadding),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (data.pendingSyncCount > 0) ...[
                      _SyncBanner(
                        count: data.pendingSyncCount,
                        onTap: () => showNotReady(context, 'Halaman Sinkron'),
                      ),
                      const SizedBox(height: 14),
                    ],
                    Text('TUGAS BERIKUTNYA', style: AppText.overline),
                    const SizedBox(height: 8),
                    _NextTaskCard(
                      task: data.nextTask,
                      onContinue: () => _openNextTask(context),
                    ),
                    const SizedBox(height: 14),
                    IntrinsicHeight(
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            child: _ShortcutTile(
                              icon: Icons.assignment_outlined,
                              title: 'WO APKUK',
                              subtitle: '${data.woRemaining} sisa di shift ini',
                              onTap: () => _openWorkOrders(context),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _ShortcutTile(
                              icon: Icons.location_on_outlined,
                              title: 'PASILA & P0',
                              subtitle: data.pasilaSummary,
                              onTap: () => showNotReady(context, 'PASILA & P0'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: AppBottomNav(
          current: AppTab.beranda,
          pendingSyncCount: data.pendingSyncCount,
          onSelected: (tab) {
            switch (tab) {
              case AppTab.beranda:
                break;
              case AppTab.pekerjaan:
                _openWorkOrders(context);
              case AppTab.sinkron:
              case AppTab.akun:
                showNotReady(context, tab.label);
            }
          },
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.data, required this.onNotifications});

  final BerandaData data;
  final VoidCallback onNotifications;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.primary,
      padding: EdgeInsets.fromLTRB(
        AppSizes.screenPadding,
        MediaQuery.paddingOf(context).top + 20,
        AppSizes.screenPadding,
        16,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'TIM POSKO',
                      style: AppText.caption.copyWith(
                        color: AppColors.accentLabel,
                        letterSpacing: 12 * 0.08,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      data.teamName,
                      style: AppText.title.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
              Material(
                color: Colors.white.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(AppSizes.radiusControl),
                child: IconButton(
                  tooltip: 'Notifikasi',
                  onPressed: onNotifications,
                  icon: Badge(
                    isLabelVisible: data.hasUnreadNotifications,
                    smallSize: 9,
                    backgroundColor: AppColors.accent,
                    child: const Icon(
                      Icons.notifications_none_rounded,
                      color: Colors.white,
                      size: 22,
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: AppColors.accent,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  data.shiftLabel,
                  style: AppText.body.copyWith(
                    color: AppColors.onAccent,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  data.shiftSchedule,
                  style: AppText.body.copyWith(color: AppColors.onPrimaryMuted),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.data});

  final BerandaData data;

  @override
  Widget build(BuildContext context) {
    final mutedStyle = AppText.caption.copyWith(fontSize: 13);

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppSizes.radiusSheet),
        boxShadow: const [
          BoxShadow(
            color: Color(0x1A0F1B2D),
            blurRadius: 24,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: Text(
                  'Progres WO APKUK',
                  style: AppText.label.copyWith(color: AppColors.textLabel),
                ),
              ),
              Text('Target ${data.woTarget} / shift', style: mutedStyle),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                '${data.woDone}',
                style: AppText.display.copyWith(
                  fontSize: 36,
                  height: 40 / 36,
                  color: AppColors.primary,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                '/ ${data.woTarget} selesai',
                style: AppText.titleSmall.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ExcludeSemantics(
            child: Row(
              children: [
                for (var i = 0; i < data.woTarget; i++) ...[
                  if (i > 0) const SizedBox(width: 4),
                  Expanded(
                    child: Container(
                      height: 8,
                      decoration: BoxDecoration(
                        color: _segmentColor(i),
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 14,
            runSpacing: 4,
            children: [
              _LegendItem(AppColors.progressDone, 'Selesai ${data.woDone}'),
              _LegendItem(
                AppColors.progressActive,
                'Dikerjakan ${data.woInProgress}',
              ),
              _LegendItem(
                AppColors.progressPending,
                'Belum ${data.woNotStarted}',
              ),
            ],
          ),
        ],
      ),
    );
  }

  Color _segmentColor(int index) {
    if (index < data.woDone) return AppColors.progressDone;
    if (index < data.woDone + data.woInProgress) {
      return AppColors.progressActive;
    }
    return AppColors.progressPending;
  }
}

class _LegendItem extends StatelessWidget {
  const _LegendItem(this.color, this.label);

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 8,
          height: 8,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 6),
        Text(label, style: AppText.caption),
      ],
    );
  }
}

class _SyncBanner extends StatelessWidget {
  const _SyncBanner({required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final borderRadius = BorderRadius.circular(14);

    return Material(
      color: AppColors.syncWaitingBackground,
      shape: RoundedRectangleBorder(
        borderRadius: borderRadius,
        side: const BorderSide(color: AppColors.syncWaitingBorder),
      ),
      child: InkWell(
        borderRadius: borderRadius,
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            children: [
              const Icon(
                Icons.schedule_rounded,
                size: 22,
                color: AppColors.syncWaitingIcon,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '$count laporan menunggu sinkronisasi',
                      style: AppText.body.copyWith(
                        fontWeight: FontWeight.w700,
                        color: AppColors.syncWaitingText,
                      ),
                    ),
                    Text(
                      'Tersimpan aman, dikirim otomatis saat ada sinyal',
                      style: AppText.caption.copyWith(
                        fontWeight: FontWeight.w500,
                        color: AppColors.syncWaitingText,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              const Icon(
                Icons.chevron_right_rounded,
                size: 20,
                color: AppColors.syncWaitingIcon,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NextTaskCard extends StatelessWidget {
  const _NextTaskCard({required this.task, required this.onContinue});

  final NextTask? task;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    final task = this.task;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppSizes.radiusCard),
        border: task == null
            ? Border.all(color: AppColors.border)
            : Border.all(color: AppColors.primary, width: 2),
      ),
      child: task == null
          ? Text(
              'Belum ada tugas pada shift ini.',
              style: AppText.body.copyWith(color: AppColors.textSecondary),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(task.code, style: AppText.caption)),
                    const SizedBox(width: 8),
                    WoStatusChip(task.status),
                  ],
                ),
                const SizedBox(height: 12),
                Text(task.title, style: AppText.titleSmall),
                const SizedBox(height: 2),
                Text(
                  task.subtitle,
                  style: AppText.body.copyWith(
                    fontSize: 13,
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: onContinue,
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(
                      AppSizes.buttonHeightSmall,
                    ),
                    textStyle: AppText.label,
                  ),
                  child: const Text('Lanjutkan pekerjaan'),
                ),
              ],
            ),
    );
  }
}

class _ShortcutTile extends StatelessWidget {
  const _ShortcutTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final borderRadius = BorderRadius.circular(AppSizes.radiusCard);

    return Material(
      color: AppColors.surface,
      borderRadius: borderRadius,
      child: InkWell(
        borderRadius: borderRadius,
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 24, color: AppColors.primary),
              const SizedBox(height: 8),
              Text(title, style: AppText.label),
              const SizedBox(height: 8),
              Text(
                subtitle,
                style: AppText.caption.copyWith(fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
