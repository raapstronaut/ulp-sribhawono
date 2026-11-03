import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/screens/beranda_screen.dart';
import 'package:mobile/screens/daftar_wo_screen.dart';
import 'package:mobile/screens/detail_wo_screen.dart';
import 'package:mobile/theme/app_theme.dart';

Future<void> pumpScreen(
  WidgetTester tester,
  Widget home, {
  Size size = const Size(360, 1600),
}) async {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = size;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: home));
}

void main() {
  testWidgets('menampilkan enam WO dengan jumlah per filter', (tester) async {
    await pumpScreen(tester, const DaftarWoScreen());

    expect(find.text('Semua 6'), findsOneWidget);
    expect(find.text('Aktif 4'), findsOneWidget);
    expect(find.text('Selesai 2'), findsOneWidget);
    expect(find.textContaining('Tiang '), findsNWidgets(6));
  });

  testWidgets('filter menyaring daftar WO', (tester) async {
    await pumpScreen(tester, const DaftarWoScreen());

    await tester.tap(find.text('Selesai 2'));
    await tester.pump();
    expect(find.textContaining('Tiang '), findsNWidgets(2));
    expect(find.text('Tiang JP-045'), findsNothing);

    await tester.tap(find.text('Aktif 4'));
    await tester.pump();
    expect(find.textContaining('Tiang '), findsNWidgets(4));
    expect(find.text('Tiang JP-045'), findsOneWidget);
  });

  testWidgets('menampilkan pesan bila filter kosong', (tester) async {
    await pumpScreen(tester, const DaftarWoScreen(items: []));

    expect(find.text('Tidak ada WO pada filter ini.'), findsOneWidget);
  });

  testWidgets('kartu WO membuka detail WO tersebut', (tester) async {
    await pumpScreen(tester, const DaftarWoScreen());

    await tester.tap(find.text('Tiang JP-052'));
    await tester.pumpAndSettle();

    expect(find.byType(DetailWoScreen), findsOneWidget);
    expect(find.text('WO-APKUK-20261024-0004'), findsOneWidget);
  });

  testWidgets('beranda membuka daftar WO dan tab Beranda kembali', (
    tester,
  ) async {
    await pumpScreen(tester, const BerandaScreen());

    await tester.tap(find.text('Pekerjaan'));
    await tester.pumpAndSettle();
    expect(find.byType(DaftarWoScreen), findsOneWidget);

    await tester.tap(find.text('Beranda'));
    await tester.pumpAndSettle();
    expect(find.byType(DaftarWoScreen), findsNothing);
    expect(find.byType(BerandaScreen), findsOneWidget);
  });

  testWidgets('tidak overflow pada HP kecil', (tester) async {
    await pumpScreen(
      tester,
      const DaftarWoScreen(),
      size: const Size(320, 568),
    );

    expect(tester.takeException(), isNull);
  });
}
