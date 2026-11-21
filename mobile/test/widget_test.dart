import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/main.dart';
import 'package:mobile/screens/login_screen.dart';
import 'package:mobile/screens/posko_session_screen.dart';

import 'support/fake_posko_auth.dart';

void main() {
  testWidgets('aplikasi dibuka di layar login tim', (tester) async {
    await tester.pumpWidget(const UlpSribhawonoApp());

    expect(find.text('Operasional Lapangan'), findsOneWidget);
    expect(find.text('Masuk akun tim'), findsOneWidget);
    expect(find.text('Masuk'), findsOneWidget);
  });

  testWidgets('login menolak isian kosong', (tester) async {
    await tester.pumpWidget(const UlpSribhawonoApp());

    await tester.tap(find.text('Masuk'));
    await tester.pump();

    expect(find.text('Username tim wajib diisi'), findsOneWidget);
    expect(find.text('Kata sandi wajib diisi'), findsOneWidget);
    expect(find.byType(PoskoSessionScreen), findsNothing);
  });

  testWidgets('login baru membuka sesi setelah autentikasi', (tester) async {
    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(auth: FakePoskoAuth())),
    );

    await tester.enterText(find.byType(TextFormField).at(0), 'posko_jabung');
    await tester.enterText(
      find.byType(TextFormField).at(1),
      'fake-password-test',
    );
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();

    expect(find.byType(PoskoSessionScreen), findsOneWidget);
  });
  testWidgets('tombol mata menampilkan kata sandi', (tester) async {
    await tester.pumpWidget(const UlpSribhawonoApp());

    EditableText passwordField() =>
        tester.widget<EditableText>(find.byType(EditableText).at(1));

    expect(passwordField().obscureText, isTrue);
    await tester.tap(find.byTooltip('Tampilkan kata sandi'));
    await tester.pump();
    expect(passwordField().obscureText, isFalse);
  });
}
