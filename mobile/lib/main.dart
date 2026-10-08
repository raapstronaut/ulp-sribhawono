import 'package:flutter/material.dart';

void main() => runApp(const UlpSribhawonoApp());

class UlpSribhawonoApp extends StatelessWidget {
  const UlpSribhawonoApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'ULP Sribhawono',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: Colors.blue)),
      home: Scaffold(
        appBar: AppBar(title: const Text('ULP Sribhawono')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.engineering_outlined, size: 56),
                SizedBox(height: 16),
                Text('Aplikasi Operasional Lapangan',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                SizedBox(height: 12),
                Text('POSKO • HARSUS • INSPEKSI • ROW', textAlign: TextAlign.center),
                SizedBox(height: 16),
                Text('Fondasi aplikasi. Login tim, pekerjaan, kamera, GPS, dan sinkronisasi offline masih dalam pengembangan.',
                    textAlign: TextAlign.center),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
