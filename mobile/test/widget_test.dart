import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/main.dart';
import 'package:mobile/screens/beranda_screen.dart';

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
    expect(find.byType(BerandaScreen), findsNothing);
  });

  testWidgets('login dengan isian lengkap membuka beranda', (tester) async {
    await tester.pumpWidget(const UlpSribhawonoApp());

    await tester.enterText(
      find.byType(TextFormField).at(0),
      'posko.sribhawono',
    );
    await tester.enterText(find.byType(TextFormField).at(1), 'rahasia');
    await tester.tap(find.text('Masuk'));
    await tester.pumpAndSettle();

    expect(find.byType(BerandaScreen), findsOneWidget);
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
