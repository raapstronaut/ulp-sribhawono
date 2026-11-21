import 'package:flutter/material.dart';

import '../services/posko_auth.dart';
import 'login_screen.dart';

/// Temporary pilot screen: shows live API identity / roster, NOT mock WO data.
class PoskoSessionScreen extends StatefulWidget {
  const PoskoSessionScreen({
    super.key,
    required this.session,
    required this.auth,
  });

  final PoskoSession session;
  final PoskoAuth auth;

  @override
  State<PoskoSessionScreen> createState() => _PoskoSessionScreenState();
}

class _PoskoSessionScreenState extends State<PoskoSessionScreen> {
  bool _loggingOut = false;
  String? _error;

  Future<void> _logout() async {
    if (_loggingOut) return;
    setState(() {
      _loggingOut = true;
      _error = null;
    });
    try {
      await widget.auth.logout(widget.session);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute<void>(builder: (_) => LoginScreen(auth: widget.auth)),
        (_) => false,
      );
    } on PoskoAuthError catch (e) {
      if (mounted) setState(() => _error = e.message);
    } on Exception {
      if (mounted) {
        setState(
          () => _error = 'Logout gagal. Coba lagi saat jaringan tersedia.',
        );
      }
    } finally {
      if (mounted) setState(() => _loggingOut = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    return Scaffold(
      appBar: AppBar(title: const Text('Verifikasi akun POSKO')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const Text(
              'Login Laravel berhasil',
              style: TextStyle(fontSize: 23, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'Layar pilot API. Data WO dan PASILA belum ditampilkan di sini.',
            ),
            const SizedBox(height: 20),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Akun: ${session.username}'),
                    Text('Tim: ${session.teamName}'),
                    Text('Petugas aktif: ${session.personnelCount}'),
                    Text(
                      'Roster lengkap: ${session.rosterComplete ? 'Ya' : 'Tidak'}',
                    ),
                    Text(
                      session.rotationConfigured
                          ? 'Rotasi: aktif'
                          : 'Rotasi: belum berlaku / belum terkonfigurasi',
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'Daftar pasangan dari server',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            if (session.crews.isEmpty)
              const Text('Belum ada data pasangan yang bisa ditampilkan.'),
            for (final crew in session.crews)
              Card(
                child: ListTile(
                  title: Text(
                    (crew['label'] ?? 'Tim ${crew['number']}').toString(),
                  ),
                  subtitle: Text(
                    crew['is_complete'] == true
                        ? '2 petugas terdaftar'
                        : 'Susunan pasangan belum lengkap',
                  ),
                ),
              ),
            if (_error != null) ...[
              const SizedBox(height: 16),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _loggingOut ? null : _logout,
              child: Text(_loggingOut ? 'Keluar...' : 'Logout dari server'),
            ),
            const SizedBox(height: 12),
            const Text(
              'Sesi pilot hanya tersimpan di memori. Menutup aplikasi berarti perlu login ulang. '
              'Logout melalui tombol di atas agar token dicabut dari server.',
              style: TextStyle(fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}
