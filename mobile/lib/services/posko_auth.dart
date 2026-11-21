import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'package:flutter/foundation.dart';

class PoskoAuthError implements Exception {
  const PoskoAuthError(this.message);
  final String message;
  @override
  String toString() => message;
}

class PoskoSession {
  const PoskoSession({
    required this.token,
    required this.account,
    required this.bootstrap,
  });

  // Token only lives in memory in this pilot. Never print or persist plaintext.
  final String token;
  final Map<String, dynamic> account;
  final Map<String, dynamic> bootstrap;

  String get username => (account['username'] ?? '-').toString();
  String get teamName {
    final team = bootstrap['team'];
    if (team is Map) return (team['name'] ?? '-').toString();
    return '-';
  }

  int get personnelCount {
    final members = bootstrap['personnel'];
    return members is List ? members.length : 0;
  }

  bool get rosterComplete {
    final roster = bootstrap['posko_crews'];
    return roster is Map && roster['roster_complete'] == true;
  }

  bool get rotationConfigured {
    final roster = bootstrap['posko_crews'];
    if (roster is! Map) return false;
    final rotation = roster['rotation'];
    return rotation is Map && rotation['configured'] == true;
  }

  List<Map<String, dynamic>> get crews {
    final roster = bootstrap['posko_crews'];
    if (roster is! Map || roster['crews'] is! List) return [];
    return (roster['crews'] as List).whereType<Map<String, dynamic>>().toList(
      growable: false,
    );
  }
}

abstract class PoskoAuth {
  Future<PoskoSession> login(String username, String password);
  Future<void> logout(PoskoSession session);
}

/// First-stage Android pilot: real Laravel login, /me and /bootstrap.
/// No offline login or persistent token yet. HTTP allowed ONLY in debug builds.
class HttpPoskoAuth implements PoskoAuth {
  HttpPoskoAuth({http.Client? client, String? baseUrl})
    : _client = client ?? http.Client(),
      _baseUrl = baseUrl ?? const String.fromEnvironment('ULP_API_BASE_URL');

  final String _baseUrl;
  final http.Client _client;

  Uri _url(String path) {
    if (_baseUrl.isEmpty) {
      throw const PoskoAuthError(
        'Alamat API belum diatur. Jalankan Flutter dengan --dart-define=ULP_API_BASE_URL=...',
      );
    }
    final url = Uri.tryParse(
      '${_baseUrl.replaceFirst(RegExp(r"/+$"), '')}/$path',
    );
    if (url == null ||
        !url.hasAuthority ||
        (url.scheme != 'http' && url.scheme != 'https')) {
      throw const PoskoAuthError('Alamat API tidak valid.');
    }
    if (kReleaseMode && url.scheme != 'https') {
      throw const PoskoAuthError('Aplikasi rilis harus memakai HTTPS.');
    }
    return url;
  }

  Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
  }) async {
    final url = _url(path);
    try {
      final headers = <String, String>{'Accept': 'application/json'};
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
      if (body != null) {
        headers['Content-Type'] = 'application/json';
      }

      late final http.Response response;
      if (method == 'GET') {
        response = await _client
            .get(url, headers: headers)
            .timeout(const Duration(seconds: 12));
      } else if (method == 'POST') {
        response = await _client
            .post(
              url,
              headers: headers,
              body: body == null ? null : jsonEncode(body),
            )
            .timeout(const Duration(seconds: 12));
      } else {
        throw const PoskoAuthError('Metode API tidak didukung.');
      }

      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is! Map<String, dynamic>) {
        throw const PoskoAuthError('Respons server tidak sesuai format API.');
      }
      if (response.statusCode < 200 || response.statusCode >= 300) {
        String message = (decoded['message'] ?? 'Permintaan gagal.').toString();
        final errors = decoded['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) {
            message = first.first.toString();
          }
        }
        if (response.statusCode == 401) {
          message = path == 'auth/login'
              ? 'Username atau kata sandi salah.'
              : 'Sesi tidak valid. Silakan login ulang.';
        }
        throw PoskoAuthError(message);
      }
      return decoded;
    } on PoskoAuthError {
      rethrow;
    } on TimeoutException {
      throw const PoskoAuthError(
        'Server tidak merespons. Periksa koneksi lalu coba lagi.',
      );
    } on http.ClientException {
      throw const PoskoAuthError(
        'Koneksi Laravel gagal. Periksa server, jaringan, dan jika lewat browser, pengaturan CORS.',
      );
    } on FormatException {
      throw const PoskoAuthError('Server tidak mengirim JSON yang valid.');
    } on Exception {
      throw const PoskoAuthError(
        'Koneksi API gagal. Periksa konfigurasi server.',
      );
    }
  }

  Map<String, dynamic> _data(Map<String, dynamic> response) {
    final data = response['data'];
    if (data is! Map<String, dynamic>) {
      throw const PoskoAuthError('Respons API tidak memiliki data yang valid.');
    }
    return data;
  }

  Future<void> _revoke(String token) async {
    try {
      await _request('POST', 'auth/logout', token: token);
    } on Exception {
      // Best effort: server may be unavailable. Never log or display the token.
    }
  }

  @override
  Future<PoskoSession> login(String username, String password) async {
    final login = _data(
      await _request(
        'POST',
        'auth/login',
        body: {
          'username': username.trim(),
          'password': password,
          'device_name': 'ULP-Sribawono-Flutter-Pilot',
        },
      ),
    );
    final token = login['token'];
    if (token is! String || token.isEmpty) {
      throw const PoskoAuthError('Token login tidak ditemukan.');
    }

    try {
      final account = login['account'];
      if (account is! Map<String, dynamic> || account['role'] != 'POSKO') {
        throw const PoskoAuthError('Aplikasi uji ini khusus akun POSKO.');
      }
      final team = account['team'];
      if (team is! Map || team['posko_id'] == null) {
        throw const PoskoAuthError('Akun belum terhubung ke POSKO yang valid.');
      }

      final me = _data(await _request('GET', 'me', token: token));
      if (me['role'] != 'POSKO' || me['id'] != account['id']) {
        throw const PoskoAuthError('Identitas akun tidak cocok dengan server.');
      }
      final bootstrap = _data(await _request('GET', 'bootstrap', token: token));
      final bootstrapTeam = bootstrap['team'];
      if (bootstrapTeam is! Map || bootstrapTeam['id'] != team['id']) {
        throw const PoskoAuthError(
          'Data tim awal tidak cocok dengan akun login.',
        );
      }
      return PoskoSession(token: token, account: me, bootstrap: bootstrap);
    } on Exception {
      await _revoke(token);
      rethrow;
    }
  }

  @override
  Future<void> logout(PoskoSession session) async {
    await _request('POST', 'auth/logout', token: session.token);
  }
}
