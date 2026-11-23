import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../services/work_order_read_api.dart';
import '../theme/app_colors.dart';
import '../theme/app_text.dart';

/// Real POSKO Work Orders, separate from the existing mockup screens.
/// Only GET requests are sent; job actions are intentionally not enabled.
class WorkOrderLiveScreen extends StatefulWidget {
  const WorkOrderLiveScreen({super.key, required this.token});

  final String token;

  @override
  State<WorkOrderLiveScreen> createState() => _WorkOrderLiveScreenState();
}

class _WorkOrderLiveScreenState extends State<WorkOrderLiveScreen> {
  final WorkOrderReadApi _api = WorkOrderReadApi();
  String _scope = 'active';
  List<LiveWorkOrder> _items = [];
  bool _busy = false;
  String? _error;
  int _page = 0;
  int _lastPage = 1;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _api.close();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await _api.feed(
        widget.token,
        scope: _scope,
        page: more ? _page + 1 : 1,
      );
      if (!mounted) return;
      setState(() {
        _items = more ? [..._items, ...result.items] : result.items;
        _page = result.page;
        _lastPage = result.lastPage;
        _total = result.total;
      });
    } on WorkOrderReadError catch (error) {
      if (mounted) setState(() => _error = error.message);
    } on Exception {
      if (mounted) setState(() => _error = 'Gagal memuat pekerjaan.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _setScope(String scope) {
    if (_scope == scope || _busy) return;
    setState(() {
      _scope = scope;
      _items = [];
      _page = 0;
      _lastPage = 1;
      _total = 0;
      _error = null;
    });
    _load();
  }

  void _open(LiveWorkOrder item) {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => _LiveWorkOrderDetailScreen(
        token: widget.token,
        id: item.id,
        code: item.code,
        api: _api,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Work Order dari Laravel'),
        actions: [
          IconButton(
            tooltip: 'Muat ulang',
            onPressed: _busy ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
            color: AppColors.primarySoft,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('DATA ASLI · HANYA BACA',
                    style: AppText.caption.copyWith(color: AppColors.primary)),
                const SizedBox(height: 4),
                Text('WO sesuai hak akses POSKO • Total: $_total',
                    style: AppText.body),
                const SizedBox(height: 10),
                Wrap(spacing: 8, children: [
                  ChoiceChip(
                    label: const Text('Aktif'),
                    selected: _scope == 'active',
                    onSelected: _busy ? null : (_) => _setScope('active'),
                  ),
                  ChoiceChip(
                    label: const Text('Riwayat'),
                    selected: _scope == 'history',
                    onSelected: _busy ? null : (_) => _setScope('history'),
                  ),
                ]),
              ],
            ),
          ),
          Expanded(
            child: _busy && _items.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : _items.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(24),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(_error == null
                                  ? Icons.assignment_turned_in_outlined
                                  : Icons.wifi_off_rounded, size: 40),
                              const SizedBox(height: 12),
                              Text(
                                _error ?? 'Belum ada Work Order pada filter ini.',
                                textAlign: TextAlign.center,
                                style: AppText.body,
                              ),
                              const SizedBox(height: 12),
                              OutlinedButton(
                                onPressed: _load,
                                child: const Text('Coba lagi'),
                              ),
                            ],
                          ),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView(
                          padding: const EdgeInsets.all(16),
                          children: [
                            if (_error != null) ...[
                              Text(_error!, style: const TextStyle(color: AppColors.danger)),
                              const SizedBox(height: 8),
                            ],
                            for (final item in _items) ...[
                              Card(
                                margin: EdgeInsets.zero,
                                child: InkWell(
                                  onTap: () => _open(item),
                                  child: Padding(
                                    padding: const EdgeInsets.all(14),
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(item.code, style: AppText.caption),
                                        const SizedBox(height: 4),
                                        Text(item.targetLabel,
                                            style: AppText.titleSmall),
                                        const SizedBox(height: 6),
                                        Text('${item.workType} · ${item.feeder}',
                                            style: AppText.body),
                                        const SizedBox(height: 4),
                                        Text('Section: ${item.section}',
                                            style: AppText.caption),
                                        const SizedBox(height: 8),
                                        Wrap(spacing: 8, runSpacing: 6, children: [
                                          Chip(label: Text(item.statusLabel)),
                                          if (item.isOverdue)
                                            const Chip(label: Text('Terlambat')),
                                        ]),
                                        if (item.dueAt != null)
                                          Text('Batas: ${_formatWhen(item.dueAt)}',
                                              style: AppText.caption),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(height: 10),
                            ],
                            if (_page < _lastPage)
                              OutlinedButton(
                                onPressed: _busy ? null : () => _load(more: true),
                                child: Text(_busy ? 'Memuat...' : 'Muat halaman berikutnya'),
                              ),
                            if (_busy)
                              const Padding(
                                padding: EdgeInsets.all(12),
                                child: Center(child: CircularProgressIndicator()),
                              ),
                          ],
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}

class _LiveWorkOrderDetailScreen extends StatefulWidget {
  const _LiveWorkOrderDetailScreen({
    required this.token,
    required this.id,
    required this.code,
    required this.api,
  });
  final String token;
  final String id;
  final String code;
  final WorkOrderReadApi api;

  @override
  State<_LiveWorkOrderDetailScreen> createState() =>
      _LiveWorkOrderDetailScreenState();
}

class _LiveWorkOrderDetailScreenState
    extends State<_LiveWorkOrderDetailScreen> {
  LiveWorkOrderDetail? _detail;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final detail = await widget.api.detail(widget.token, widget.id);
      if (mounted) setState(() => _detail = detail);
    } on WorkOrderReadError catch (error) {
      if (mounted) setState(() => _error = error.message);
    } on Exception {
      if (mounted) setState(() => _error = 'Gagal memuat detail WO.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openMaps(LiveWorkOrder item) async {
    if (!item.hasCoordinates) return;
    final url = Uri.https('www.google.com', '/maps/search/', {
      'api': '1',
      'query': '${item.latitude},${item.longitude}',
    });
    try {
      final opened = await launchUrl(url, mode: LaunchMode.externalApplication);
      if (!opened && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Google Maps tidak dapat dibuka.')),
        );
      }
    } on Exception {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Google Maps tidak dapat dibuka.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = _detail;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail WO (API)'),
        actions: [
          IconButton(
            tooltip: 'Muat ulang',
            onPressed: _busy ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: _busy && detail == null
          ? const Center(child: CircularProgressIndicator())
          : detail == null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_error ?? 'Detail WO tidak tersedia.'),
                        const SizedBox(height: 12),
                        OutlinedButton(onPressed: _load, child: const Text('Coba lagi')),
                      ],
                    ),
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    if (_error != null) ...[
                      Text(_error!, style: const TextStyle(color: AppColors.danger)),
                      const SizedBox(height: 8),
                    ],
                    _Section(
                      title: detail.item.code,
                      children: [
                        _Field('Target', detail.item.targetLabel),
                        _Field('Jenis pekerjaan', detail.item.workType),
                        _Field('Tim', detail.item.teamType),
                        _Field('Status', detail.item.statusLabel),
                        _Field('Penyulang', detail.item.feeder),
                        _Field('Section', detail.item.section),
                        _Field('Tanggal kerja', detail.item.workDate ?? '-'),
                        _Field('Batas waktu', _formatWhen(detail.item.dueAt)),
                        _Field('Catatan', detail.item.notes ?? '-'),
                      ],
                    ),
                    const SizedBox(height: 12),
                    _Section(
                      title: 'Lokasi',
                      children: [
                        Text(detail.item.hasCoordinates
                            ? '${detail.item.latitude}, ${detail.item.longitude}'
                            : 'Koordinat tidak tersedia.'),
                        if (detail.item.hasCoordinates)
                          TextButton.icon(
                            onPressed: () => _openMaps(detail.item),
                            icon: const Icon(Icons.map_outlined),
                            label: const Text('Buka Google Maps'),
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    _Entries(
                      title: 'Laporan pelaksanaan',
                      entries: detail.reports,
                      primaryKey: 'report_type',
                      secondaryKey: 'verification_status',
                    ),
                    const SizedBox(height: 12),
                    _Entries(
                      title: 'Pengajuan reschedule',
                      entries: detail.reschedules,
                      primaryKey: 'reason_code',
                      secondaryKey: 'status',
                    ),
                    const SizedBox(height: 12),
                    _Entries(
                      title: 'Eskalasi',
                      entries: detail.escalations,
                      primaryKey: 'target_team_type',
                      secondaryKey: 'status',
                    ),
                    const SizedBox(height: 12),
                    _Entries(
                      title: 'Kendala',
                      entries: detail.issues,
                      primaryKey: 'issue_type',
                      secondaryKey: 'status',
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Layar ini hanya membaca data Laravel. Mulai kerja, '
                      'laporan selesai, dan reschedule belum diaktifkan.',
                      style: AppText.caption,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children});
  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Card(
    margin: EdgeInsets.zero,
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: AppText.titleSmall),
          const SizedBox(height: 10),
          ...children,
        ],
      ),
    ),
  );
}

class _Field extends StatelessWidget {
  const _Field(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 9),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: AppText.caption),
        Text(value, style: AppText.body),
      ],
    ),
  );
}

class _Entries extends StatelessWidget {
  const _Entries({
    required this.title,
    required this.entries,
    required this.primaryKey,
    required this.secondaryKey,
  });
  final String title;
  final List<Map<String, dynamic>> entries;
  final String primaryKey;
  final String secondaryKey;

  @override
  Widget build(BuildContext context) => _Section(
    title: '$title (${entries.length})',
    children: entries.isEmpty
        ? [const Text('Belum ada catatan.')]
        : [
            for (final row in entries)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  '${row[primaryKey] ?? '-'} · ${row[secondaryKey] ?? '-'}',
                  style: AppText.body,
                ),
              ),
          ],
  );
}

String _formatWhen(String? value) {
  if (value == null) return '-';
  final dt = DateTime.tryParse(value);
  if (dt == null) return value;
  final local = dt.toLocal();
  String two(int number) => number.toString().padLeft(2, '0');
  return '${two(local.day)}/${two(local.month)}/${local.year} '
      '${two(local.hour)}:${two(local.minute)}';
}
