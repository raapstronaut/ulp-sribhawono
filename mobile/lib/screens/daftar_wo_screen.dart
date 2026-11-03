import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../mock/mock_work_orders.dart';
import '../theme/app_colors.dart';
import '../theme/app_text.dart';
import '../theme/app_theme.dart';
import '../widgets/app_bottom_nav.dart';
import '../widgets/not_ready.dart';
import '../widgets/wo_status_chip.dart';

enum _WoFilter {
  semua('Semua'),
  aktif('Aktif'),
  selesai('Selesai');

  const _WoFilter(this.label);

  final String label;

  bool matches(WorkOrderItem item) => switch (this) {
    _WoFilter.semua => true,
    _WoFilter.aktif => item.status != WoStatus.selesai,
    _WoFilter.selesai => item.status == WoStatus.selesai,
  };
}

/// Artboard desain "3 · Daftar WO APKUK". Masih memakai data contoh.
class DaftarWoScreen extends StatefulWidget {
  const DaftarWoScreen({
    super.key,
    this.items = mockWorkOrders,
    this.subtitle = mockShiftSubtitle,
    this.pendingSyncCount = 1,
  });

  final List<WorkOrderItem> items;
  final String subtitle;
  final int pendingSyncCount;

  @override
  State<DaftarWoScreen> createState() => _DaftarWoScreenState();
}

class _DaftarWoScreenState extends State<DaftarWoScreen> {
  _WoFilter _filter = _WoFilter.semua;

  @override
  Widget build(BuildContext context) {
    final visible = widget.items.where(_filter.matches).toList();

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 60,
        backgroundColor: AppColors.surface,
        surfaceTintColor: Colors.transparent,
        systemOverlayStyle: SystemUiOverlayStyle.dark,
        titleSpacing: 0,
        shape: const Border(bottom: BorderSide(color: AppColors.border)),
        leading: IconButton(
          tooltip: 'Kembali ke beranda',
          icon: const Icon(Icons.arrow_back_rounded),
          onPressed: () => Navigator.of(context).pop(),
        ),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('WO APKUK', style: AppText.titleSmall),
            Text(widget.subtitle, style: AppText.caption),
          ],
        ),
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSizes.screenPadding,
              vertical: 8,
            ),
            // Wrap: pil turun ke baris baru bila font HP diperbesar.
            child: Wrap(
              spacing: 8,
              children: [
                for (final filter in _WoFilter.values)
                  _FilterPill(
                    label:
                        '${filter.label} '
                        '${widget.items.where(filter.matches).length}',
                    selected: filter == _filter,
                    onTap: () => setState(() => _filter = filter),
                  ),
              ],
            ),
          ),
          Expanded(
            child: visible.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: Text(
                        'Tidak ada WO pada filter ini.',
                        textAlign: TextAlign.center,
                        style: AppText.body.copyWith(
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(
                      AppSizes.screenPadding,
                      0,
                      AppSizes.screenPadding,
                      12,
                    ),
                    itemCount: visible.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (context, index) => _WorkOrderCard(
                      item: visible[index],
                      onTap: () => showNotReady(context, 'Detail WO'),
                    ),
                  ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSizes.screenPadding,
              vertical: 10,
            ),
            decoration: const BoxDecoration(
              color: AppColors.surface,
              border: Border(top: BorderSide(color: AppColors.border)),
            ),
            child: OutlinedButton.icon(
              onPressed: () => showNotReady(context, 'Form reschedule'),
              icon: const Icon(Icons.calendar_month_outlined, size: 20),
              label: const Text('Ajukan reschedule sisa WO'),
            ),
          ),
        ],
      ),
      bottomNavigationBar: AppBottomNav(
        current: AppTab.pekerjaan,
        pendingSyncCount: widget.pendingSyncCount,
        onSelected: (tab) {
          switch (tab) {
            case AppTab.beranda:
              Navigator.of(context).pop();
            case AppTab.pekerjaan:
              break;
            case AppTab.sinkron:
            case AppTab.akun:
              showNotReady(context, tab.label);
          }
        },
      ),
    );
  }
}

class _FilterPill extends StatelessWidget {
  const _FilterPill({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      // Area sentuh 44 walau pil yang terlihat setinggi 36.
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Container(
            height: 36,
            alignment: Alignment.center,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(
              color: selected ? AppColors.primary : AppColors.surface,
              borderRadius: BorderRadius.circular(999),
              border: Border.all(
                color: selected ? AppColors.primary : AppColors.borderStrong,
                width: 1.5,
              ),
            ),
            child: Text(
              label,
              style: AppText.body.copyWith(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: selected ? Colors.white : AppColors.textLabel,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _WorkOrderCard extends StatelessWidget {
  const _WorkOrderCard({required this.item, required this.onTap});

  final WorkOrderItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDone = item.status == WoStatus.selesai;
    final isActive = item.status == WoStatus.dikerjakan;
    final borderRadius = BorderRadius.circular(AppSizes.radiusCard);

    return Opacity(
      opacity: isDone ? 0.78 : 1,
      child: Material(
        color: AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: borderRadius,
          side: isActive
              ? const BorderSide(color: AppColors.progressActive, width: 2)
              : const BorderSide(color: AppColors.border),
        ),
        child: InkWell(
          borderRadius: borderRadius,
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _RouteOrderBadge(order: item.routeOrder, status: item.status),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              'Tiang ${item.pole}',
                              style: AppText.label.copyWith(
                                fontSize: 16,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          WoStatusChip(item.status),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${item.feeder} · Kurang ${item.missingApkuk} APKUK',
                        style: AppText.body.copyWith(
                          fontSize: 13,
                          color: AppColors.textSecondary,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        item.code,
                        style: AppText.caption.copyWith(
                          fontSize: 11,
                          color: AppColors.textMuted,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RouteOrderBadge extends StatelessWidget {
  const _RouteOrderBadge({required this.order, required this.status});

  final int order;
  final WoStatus status;

  @override
  Widget build(BuildContext context) {
    final isDone = status == WoStatus.selesai;
    final ringColor = status == WoStatus.dikerjakan
        ? AppColors.progressActive
        : status.foreground;

    return Container(
      width: 32,
      height: 32,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: isDone ? status.background : AppColors.background,
        border: isDone ? null : Border.all(color: ringColor, width: 2),
      ),
      child: Text(
        '$order',
        style: AppText.body.copyWith(
          fontWeight: FontWeight.w800,
          color: isDone ? status.foreground : ringColor,
        ),
      ),
    );
  }
}
