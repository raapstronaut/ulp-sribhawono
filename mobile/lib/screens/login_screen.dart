import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_colors.dart';
import '../theme/app_text.dart';
import '../theme/app_theme.dart';
import '../services/posko_auth.dart';
import 'posko_session_screen.dart';

/// Artboard desain "1 · Login Tim".
///
/// Login pilot memakai API Laravel. Setelah berhasil, tampilkan identitas
/// dan roster dari server; Beranda mock tidak dianggap data operasional.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.auth});

  final PoskoAuth? auth;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _hidePassword = true;
  bool _busy = false;
  String? _error;
  late final PoskoAuth _auth;

  @override
  void initState() {
    super.initState();
    _auth = widget.auth ?? HttpPoskoAuth();
  }

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy || !_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final session = await _auth.login(
        _usernameController.text,
        _passwordController.text,
      );
      if (!mounted) return;
      _passwordController.clear();
      Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(
          builder: (_) => PoskoSessionScreen(session: session, auth: _auth),
        ),
      );
    } on PoskoAuthError catch (error) {
      if (mounted) setState(() => _error = error.message);
    } on Exception {
      if (mounted) {
        setState(() => _error = 'Login gagal. Periksa koneksi dan coba lagi.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      // Ikon status bar putih karena header berwarna biru.
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        body: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const _Header(),
              // Kartu login naik 40 px menimpa header, seperti di desain.
              Transform.translate(
                offset: const Offset(0, -40),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSizes.screenPadding,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _buildForm(),
                      const SizedBox(height: 16),
                      const _OfflineInfo(),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.only(bottom: 24, top: 8),
            child: Text(
              'Versi 1.0.0 · PLN ULP Sribhawono',
              textAlign: TextAlign.center,
              style: AppText.caption.copyWith(
                color: AppColors.textMuted,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildForm() {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
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
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text('Masuk akun tim', style: AppText.title),
            const SizedBox(height: 4),
            Text(
              'Petugas pelaksana dipilih saat melapor pekerjaan.',
              style: AppText.body.copyWith(
                fontSize: 13,
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 18),
            const _FieldLabel('Username tim'),
            const SizedBox(height: 6),
            TextFormField(
              controller: _usernameController,
              textInputAction: TextInputAction.next,
              autocorrect: false,
              style: AppText.body.copyWith(fontSize: 15),
              decoration: const InputDecoration(
                hintText: 'contoh: posko.sribhawono',
                prefixIcon: Icon(Icons.person_outline_rounded, size: 20),
              ),
              validator: (value) => (value == null || value.trim().isEmpty)
                  ? 'Username tim wajib diisi'
                  : null,
            ),
            const SizedBox(height: 18),
            const _FieldLabel('Kata sandi'),
            const SizedBox(height: 6),
            TextFormField(
              controller: _passwordController,
              obscureText: _hidePassword,
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => _submit(),
              style: AppText.body.copyWith(fontSize: 15),
              decoration: InputDecoration(
                hintText: 'Kata sandi',
                prefixIcon: const Icon(Icons.lock_outline_rounded, size: 20),
                suffixIcon: IconButton(
                  tooltip: _hidePassword
                      ? 'Tampilkan kata sandi'
                      : 'Sembunyikan kata sandi',
                  icon: Icon(
                    _hidePassword
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                    size: 20,
                  ),
                  onPressed: () =>
                      setState(() => _hidePassword = !_hidePassword),
                ),
              ),
              validator: (value) => (value == null || value.isEmpty)
                  ? 'Kata sandi wajib diisi'
                  : null,
            ),
            const SizedBox(height: 18),
            if (_error != null) ...[
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
              const SizedBox(height: 12),
            ],
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(_busy ? 'Menghubungkan...' : 'Masuk'),
                  const SizedBox(width: 8),
                  if (_busy)
                    const SizedBox(
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  else
                    const Icon(Icons.arrow_forward_rounded, size: 20),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.primary,
      padding: EdgeInsets.fromLTRB(
        24,
        MediaQuery.paddingOf(context).top + 48,
        24,
        72,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: AppColors.accent,
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Icon(
              Icons.bolt_rounded,
              size: 32,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 16),
          Text(
            'ULP SRIBHAWONO',
            style: AppText.overline.copyWith(
              color: AppColors.accentLabel,
              letterSpacing: 13 * 0.08,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Operasional Lapangan',
            style: AppText.display.copyWith(color: Colors.white),
          ),
          const SizedBox(height: 4),
          Text(
            'POSKO · HARSUS · INSPEKSI · ROW',
            style: AppText.body.copyWith(color: AppColors.onPrimaryMuted),
          ),
        ],
      ),
    );
  }
}

class _FieldLabel extends StatelessWidget {
  const _FieldLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: AppText.body.copyWith(
        fontSize: 13,
        fontWeight: FontWeight.w600,
        color: AppColors.textLabel,
      ),
    );
  }
}

class _OfflineInfo extends StatelessWidget {
  const _OfflineInfo();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.primarySoft,
        borderRadius: BorderRadius.circular(AppSizes.radiusControl),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.wifi_rounded, size: 20, color: AppColors.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Login pertama butuh internet. Setelah itu pekerjaan tetap bisa dicatat saat offline.',
              style: AppText.body.copyWith(
                fontSize: 13,
                height: 18 / 13,
                color: const Color(0xFF0A3A6E),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
