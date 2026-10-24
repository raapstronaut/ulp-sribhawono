import 'package:flutter/material.dart';

import '../theme/app_text.dart';

/// Sementara: tujuan setelah login. Isi mengikuti artboard
/// "2 · Beranda Shift" dan dikerjakan pada langkah berikutnya.
class BerandaScreen extends StatelessWidget {
  const BerandaScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Beranda')),
      body: const Center(
        child: Text('Layar Beranda menyusul', style: AppText.body),
      ),
    );
  }
}
