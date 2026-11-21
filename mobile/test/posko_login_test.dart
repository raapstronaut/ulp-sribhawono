import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/screens/login_screen.dart';
import 'package:mobile/screens/posko_session_screen.dart';

import 'support/fake_posko_auth.dart';

void main() {
  testWidgets('login API palsu untuk tes menampilkan data bootstrap server', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(auth: FakePoskoAuth())),
    );
    await tester.enterText(find.byType(TextFormField).at(0), 'posko_jabung');
    await tester.enterText(
      find.byType(TextFormField).at(1),
      'password-only-in-test',
    );
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.byType(PoskoSessionScreen), findsOneWidget);
    expect(find.textContaining('POSKO JABUNG'), findsWidgets);
    expect(find.text('Petugas aktif: 8'), findsOneWidget);
    expect(find.text('Roster lengkap: Ya'), findsOneWidget);
  });

  testWidgets('kredensial salah tidak bisa membuka halaman sesi', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(auth: FakePoskoAuth(shouldFail: true))),
    );
    await tester.enterText(find.byType(TextFormField).at(0), 'posko_jabung');
    await tester.enterText(find.byType(TextFormField).at(1), 'wrong-password');
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();
    expect(find.text('Username atau kata sandi salah.'), findsOneWidget);
    expect(find.byType(PoskoSessionScreen), findsNothing);
  });
}
