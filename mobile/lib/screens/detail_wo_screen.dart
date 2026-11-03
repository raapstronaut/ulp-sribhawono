import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../mock/mock_work_orders.dart';
import '../theme/app_colors.dart';
import '../theme/app_text.dart';
import '../theme/app_theme.dart';
import '../widgets/not_ready.dart';
import '../widgets/subpage_app_bar.dart';
import '../widgets/wo_status_chip.dart';

Future<bool> _openExternally(Uri uri) =>
    launchUrl(uri, mode: LaunchMode.externalApplication);

/// Artboard desain "4 · Detail WO". Masih memakai data contoh.
class DetailWoScreen extends StatelessWidget {
  const DetailWoScreen({
    super.key,
    required this.item,
    this.openUrl = _openExternally,
  });

  final WorkOrderItem item;
  final Future<bool> Function(Uri uri) openUrl;

  /// WO yang sudah selesai tidak punya tindakan lanjutan.
  bool get _canAct => item.status != WoStatus.selesai;

  void _showMessage(BuildContext context, String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _openMaps(BuildContext context) async {
    final uri = Uri.https('www.google.com', '/maps/search/', {
      'api': '1',
      'query': '${item.latitude},${item.longitude}',
    });
    final messenger = ScaffoldMessenger.of(context);

    var opened = false;
    try {
      opened = await openUrl(uri);
    } on PlatformException {
      opened = false;
    }

    if (!opened) {
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(
          const SnackBar(content: Text('Google Maps tidak dapat dibuka')),
        );
    }
  }

  Future<void> _copyCoordinates(BuildContext context) async {
    await Clipboard.setData(ClipboardData(text: item.coordinates));
    if (context.mounted) _showMessage(context, 'Koordinat disalin');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: SubpageAppBar(
        title: 'Detail WO',
        subtitle: item.code,
        backTooltip: 'Kembali',
      ),
      body: ListView(
        padding: const EdgeInsets.all(AppSizes.screenPadding),
        children: [
          _SummaryCard(item: item),
          const SizedBox(height: 14),
          _LocationCard(
            coordinates: item.coordinates,
            onCopy: () => _copyCoordinates(context),
            onOpenMaps: () => _openMaps(context),
          ),
          const SizedBox(height: 14),
          _HistoryCard(entries: item.history),
        ],
      ),
      bottomNavigationBar: _canAct
          ? _ActionBar(
              onComplete: () => showNotReady(context, 'Form laporan selesai'),
              onReschedule: () => showNotReady(context, 'Form reschedule'),
              onAlreadyComplete: () =>
                  showNotReady(context, 'Laporan APKUK sudah lengkap'),
            )
          : null,
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppSizes.radiusCard),
      ),
      child: child,
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.item});

  final WorkOrderItem item;

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _LargeChip(
                label: item.status.label,
                background: item.status.background,
                foreground: item.status.foreground,
                fontWeight: FontWeight.w700,
              ),
              _LargeChip(
                label: '$mockShiftName · Urutan ${item.routeOrder}',
                background: AppColors.background,
                foreground: AppColors.textLabel,
                fontWeight: FontWeight.w600,
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            'Pemasangan APKUK',
            style: AppText.caption.copyWith(fontSize: 13),
          ),
          const SizedBox(height: 2),
          Text(
            'Tiang ${item.pole}',
            style: AppText.display.copyWith(fontSize: 24, height: 30 / 24),
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _InfoField(label: 'Penyulang', value: item.feeder),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: _InfoField(label: 'Section', value: item.section),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _InfoField(label: 'Naik dari', value: item.previousPole),
              ),
              const SizedBox(width: 16),
              const Expanded(
                child: _InfoField(label: 'Batas waktu', value: mockDueTime),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFFFF8E1),
              borderRadius: BorderRadius.circular(AppSizes.radiusControl),
              border: Border.all(color: AppColors.accentLabel),
            ),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: AppColors.accent,
                    borderRadius: BorderRadius.circular(AppSizes.radiusControl),
                  ),
                  child: Text(
                    '${item.missingApkuk}',
                    style: AppText.title.copyWith(
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                      color: AppColors.onAccent,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'APKUK perlu dipasang',
                        style: AppText.label.copyWith(
                          fontWeight: FontWeight.w800,
                          color: AppColors.onAccent,
                        ),
                      ),
                      Text(
                        'Laporan terakhir: terpasang '
                        '${item.reportedInstalled} bh, '
                        'kurang ${item.missingApkuk} bh',
                        style: AppText.caption.copyWith(
                          fontWeight: FontWeight.w500,
                          color: const Color(0xFF5C4A00),
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
    );
  }
}

class _LargeChip extends StatelessWidget {
  const _LargeChip({
    required this.label,
    required this.background,
    required this.foreground,
    required this.fontWeight,
  });

  final String label;
  final Color background;
  final Color foreground;
  final FontWeight fontWeight;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        label,
        style: AppText.body.copyWith(
          fontSize: 13,
          height: 18 / 13,
          fontWeight: fontWeight,
          color: foreground,
        ),
      ),
    );
  }
}

class _InfoField extends StatelessWidget {
  const _InfoField({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: AppText.caption.copyWith(color: AppColors.textMuted),
        ),
        const SizedBox(height: 2),
        Text(value, style: AppText.body.copyWith(fontWeight: FontWeight.w700)),
      ],
    );
  }
}

class _LocationCard extends StatelessWidget {
  const _LocationCard({
    required this.coordinates,
    required this.onCopy,
    required this.onOpenMaps,
  });

  final String coordinates;
  final VoidCallback onCopy;
  final VoidCallback onOpenMaps;

  @override
  Widget build(BuildContext context) {
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(AppSizes.radiusCard),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 132,
            child: Stack(
              alignment: Alignment.center,
              children: [
                Positioned.fill(child: CustomPaint(painter: _GridPainter())),
                const Icon(
                  Icons.location_on,
                  size: 40,
                  color: AppColors.primary,
                  semanticLabel: 'Lokasi tiang',
                ),
                Positioned(
                  left: 10,
                  bottom: 10,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.92),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      'Pratinjau lokasi',
                      style: AppText.caption.copyWith(
                        fontSize: 11,
                        color: AppColors.textLabel,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: _InfoField(label: 'Koordinat', value: coordinates),
                    ),
                    const SizedBox(width: 8),
                    IconButton(
                      tooltip: 'Salin koordinat',
                      onPressed: onCopy,
                      style: IconButton.styleFrom(
                        fixedSize: const Size(44, 44),
                        side: const BorderSide(color: AppColors.border),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(
                            AppSizes.radiusControl,
                          ),
                        ),
                      ),
                      icon: const Icon(
                        Icons.copy_rounded,
                        size: 20,
                        color: AppColors.textLabel,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: onOpenMaps,
                  icon: const Icon(Icons.navigation_outlined, size: 20),
                  label: const Text('Buka Google Maps'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Pola kisi 24 px sebagai latar pratinjau, bukan peta sungguhan.
class _GridPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = const Color(0xFFE8EEF5),
    );

    final line = Paint()
      ..color = const Color(0xFFD5DEE9)
      ..strokeWidth = 1;
    for (var x = 0.0; x <= size.width; x += 24) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), line);
    }
    for (var y = 0.0; y <= size.height; y += 24) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), line);
    }
  }

  @override
  bool shouldRepaint(_GridPainter oldDelegate) => false;
}

class _HistoryCard extends StatelessWidget {
  const _HistoryCard({required this.entries});

  final List<WoHistoryEntry> entries;

  @override
  Widget build(BuildContext context) {
    return _Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('RIWAYAT', style: AppText.overline),
          for (final entry in entries) ...[
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 10,
                  height: 10,
                  margin: const EdgeInsets.only(top: 5),
                  decoration: BoxDecoration(
                    color: entry.status.accent,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        entry.title,
                        style: AppText.body.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        entry.detail,
                        style: AppText.caption.copyWith(
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.onComplete,
    required this.onReschedule,
    required this.onAlreadyComplete,
  });

  final VoidCallback onComplete;
  final VoidCallback onReschedule;
  final VoidCallback onAlreadyComplete;

  @override
  Widget build(BuildContext context) {
    final neutralStyle = OutlinedButton.styleFrom(
      foregroundColor: AppColors.textLabel,
      side: const BorderSide(color: AppColors.borderStrong, width: 1.5),
      textStyle: AppText.label.copyWith(fontSize: 14),
    );

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.border)),
        boxShadow: [
          BoxShadow(
            color: Color(0x0F0F1B2D),
            blurRadius: 16,
            offset: Offset(0, -6),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSizes.screenPadding,
            12,
            AppSizes.screenPadding,
            16,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              FilledButton.icon(
                onPressed: onComplete,
                icon: const Icon(Icons.check_rounded, size: 22),
                label: const Text('Laporkan selesai'),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: onReschedule,
                      style: neutralStyle,
                      child: const Text('Reschedule'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: onAlreadyComplete,
                      style: neutralStyle,
                      child: const Text('Sudah lengkap'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
