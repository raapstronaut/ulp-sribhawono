import 'package:flutter/material.dart';

import 'screens/login_screen.dart';
import 'theme/app_theme.dart';

void main() => runApp(const UlpSribhawonoApp());

class UlpSribhawonoApp extends StatelessWidget {
  const UlpSribhawonoApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'ULP Sribhawono',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      home: const LoginScreen(),
    );
  }
}
