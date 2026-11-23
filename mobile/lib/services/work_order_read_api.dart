import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

/// Read-only Work Order API for the POSKO pilot. No POST/PUT/DELETE methods.
class WorkOrderReadError implements Exception {
  const WorkOrderReadError(this.message);
  final String message;

  @override
  String toString() => message;
}

class LiveWorkOrder {
  const LiveWorkOrder({
    required this.id,
    required this.code,
    required this.status,
    required this.statusLabel,
    required this.workType,
    required this.teamType,
    required this.targetLabel,
    required this.feeder,
    required this.section,
    required this.isOverdue,
    this.workDate,
    this.dueAt,
    this.latitude,
    this.longitude,
    this.notes,
  });

  final String id;
  final String code;
  final String status;
  final String statusLabel;
  final String workType;
  final String teamType;
  final String targetLabel;
  final String feeder;
  final String section;
  final bool isOverdue;
  final String? workDate;
  final String? dueAt;
  final double? latitude;
  final double? longitude;
  final String? notes;

  bool get hasCoordinates =>
      latitude != null &&
      longitude != null &&
      latitude!.abs() <= 90 &&
      longitude!.abs() <= 180;

  factory LiveWorkOrder.fromJson(Map<String, dynamic> value) {
    final target = _asMap(value['target']);
    final penyulang = _asMap(value['penyulang']);
    return LiveWorkOrder(
      id: _label(value['id']),
      code: _label(value['code']),
      status: _label(value['status']),
      statusLabel: _label(value['status_label'], fallback: _label(value['status'])),
      workType: _label(value['work_type']),
      teamType: _label(value['team_type']),
      targetLabel: _label(target['label']),
      feeder: _label(penyulang['name']),
      section: _label(value['section_name']),
      isOverdue: value['is_overdue'] == true,
      workDate: _optional(value['work_date']),
      dueAt: _optional(value['due_at']),
      latitude: _asDouble(target['latitude']),
      longitude: _asDouble(target['longitude']),
      notes: _optional(value['notes']),
    );
  }
}

class LiveWorkOrderPage {
  const LiveWorkOrderPage({
    required this.items,
    required this.page,
    required this.lastPage,
    required this.total,
  });
  final List<LiveWorkOrder> items;
  final int page;
  final int lastPage;
  final int total;
}

class LiveWorkOrderDetail {
  const LiveWorkOrderDetail({
    required this.item,
    required this.reports,
    required this.reschedules,
    required this.escalations,
    required this.issues,
  });
  final LiveWorkOrder item;
  final List<Map<String, dynamic>> reports;
  final List<Map<String, dynamic>> reschedules;
  final List<Map<String, dynamic>> escalations;
  final List<Map<String, dynamic>> issues;

  factory LiveWorkOrderDetail.fromJson(Map<String, dynamic> json) =>
      LiveWorkOrderDetail(
        item: LiveWorkOrder.fromJson(json),
        reports: _asListOfMaps(json['reports']),
        reschedules: _asListOfMaps(json['reschedules']),
        escalations: _asListOfMaps(json['escalations']),
        issues: _asListOfMaps(json['issues']),
      );
}

class WorkOrderReadApi {
  WorkOrderReadApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? const String.fromEnvironment('ULP_API_BASE_URL');

  final http.Client _client;
  final String _baseUrl;

  Uri _uri(String path, [Map<String, String>? query]) {
    final root = _baseUrl.trim().replaceFirst(RegExp(r'/+$'), '');
    if (root.isEmpty) {
      throw const WorkOrderReadError(
        'Alamat API belum diatur. Gunakan --dart-define=ULP_API_BASE_URL=...',
      );
    }
    final uri = Uri.tryParse('$root/$path');
    if (uri == null || !uri.hasAuthority ||
        (uri.scheme != 'http' && uri.scheme != 'https')) {
      throw const WorkOrderReadError('Alamat API Laravel tidak valid.');
    }
    if (kReleaseMode && uri.scheme != 'https') {
      throw const WorkOrderReadError('Aplikasi rilis harus menggunakan HTTPS.');
    }
    return query == null ? uri : uri.replace(queryParameters: query);
  }

  Future<Map<String, dynamic>> _read(Uri url, String token) async {
    try {
      final response = await _client.get(url, headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      }).timeout(const Duration(seconds: 15));

      if (response.statusCode == 401) {
        throw const WorkOrderReadError('Sesi berakhir. Logout lalu login kembali.');
      }
      if (response.statusCode == 403 || response.statusCode == 404) {
        throw const WorkOrderReadError('Data tidak tersedia untuk akun POSKO ini.');
      }
      if (response.statusCode >= 500) {
        throw const WorkOrderReadError('Server Laravel sedang mengalami masalah.');
      }
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw WorkOrderReadError('Permintaan gagal (HTTP ${response.statusCode}).');
      }

      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is! Map<String, dynamic>) {
        throw const WorkOrderReadError('Format respons Work Order tidak sesuai.');
      }
      return decoded;
    } on WorkOrderReadError {
      rethrow;
    } on TimeoutException {
      throw const WorkOrderReadError('Koneksi timeout. Coba muat ulang.');
    } on http.ClientException {
      throw const WorkOrderReadError(
        'Tidak bisa menghubungi Laravel. Periksa server, alamat API, dan CORS jika memakai Edge.',
      );
    } on FormatException {
      throw const WorkOrderReadError('Server tidak mengirim JSON yang valid.');
    } on Exception {
      throw const WorkOrderReadError('Gagal membaca Work Order dari Laravel.');
    }
  }

  Future<LiveWorkOrderPage> feed(
    String token, {
    required String scope,
    int page = 1,
  }) async {
    if (scope != 'active' && scope != 'history') {
      throw const WorkOrderReadError('Filter pekerjaan tidak valid.');
    }
    final json = await _read(_uri('work-feed', {
      'scope': scope,
      'page': '$page',
    }), token);
    final raw = json['data'];
    if (raw is! List) {
      throw const WorkOrderReadError('Daftar pekerjaan tidak tersedia.');
    }
    final meta = _asMap(json['meta']);
    return LiveWorkOrderPage(
      items: raw.map((entry) => LiveWorkOrder.fromJson(
        _asMap(entry),
      )).toList(growable: false),
      page: _asInt(meta['page'], page),
      lastPage: _asInt(meta['last_page'], page),
      total: _asInt(meta['total'], raw.length),
    );
  }

  Future<LiveWorkOrderDetail> detail(String token, String id) async {
    if (!RegExp(r'^[0-9a-fA-F-]{36}$').hasMatch(id)) {
      throw const WorkOrderReadError('ID pekerjaan tidak valid.');
    }
    final json = await _read(_uri('work-orders/$id'), token);
    final raw = json['data'];
    if (raw is! Map<String, dynamic>) {
      throw const WorkOrderReadError('Detail pekerjaan tidak tersedia.');
    }
    return LiveWorkOrderDetail.fromJson(raw);
  }

  void close() => _client.close();
}

Map<String, dynamic> _asMap(Object? value) => value is Map
    ? Map<String, dynamic>.from(value)
    : <String, dynamic>{};

List<Map<String, dynamic>> _asListOfMaps(Object? value) => value is List
    ? value.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : <Map<String, dynamic>>[];

String _label(Object? value, {String fallback = '-'}) =>
    (value == null || value.toString().trim().isEmpty)
        ? fallback
        : value.toString();

String? _optional(Object? value) =>
    (value == null || value.toString().trim().isEmpty) ? null : value.toString();

double? _asDouble(Object? value) => value is num
    ? value.toDouble()
    : double.tryParse(value?.toString() ?? '');

int _asInt(Object? value, int fallback) => value is num
    ? value.toInt()
    : int.tryParse(value?.toString() ?? '') ?? fallback;
