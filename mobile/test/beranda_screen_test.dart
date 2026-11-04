import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/mock/mock_beranda.dart';
import 'package:mobile/screens/beranda_screen.dart';
import 'package:mobile/screens/detail_wo_screen.dart';
import 'package:mobile/theme/app_theme.dart';

Future<void> pumpBeranda(
  WidgetTester tester, {
  BerandaData data = mockBeranda,
  Size size = const Size(360, 800),
}) async {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = size;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.light(),
      home: BerandaScreen(data: data),
    ),
  );
}

void main() {
  testWidgets('menampilkan shift, progres, dan tugas berikutnya', (
    tester,
  ) async {
    await pumpBeranda(tester);

    expect(find.text('Sribhawono'), findsOneWidget);
    expect(find.text('Shift A · Pagi'), findsOneWidget);
    expect(find.text('/ 6 selesai'), findsOneWidget);
    expect(find.text('Selesai 2'), findsOneWidget);
    expect(find.text('Dikerjakan 1'), findsOneWidget);
    expect(find.text('Belum 3'), findsOneWidget);
    expect(find.text('Tiang JP-045'), findsOneWidget);
    expect(find.text('4 sisa di shift ini'), findsOneWidget);
    expect(find.text('1 laporan menunggu sinkronisasi'), findsOneWidget);
  });

  testWidgets('banner sinkron hilang jika tidak ada antrean', (tester) async {
    const data = BerandaData(
      teamName: 'Sribhawono',
      shiftLabel: 'Shift A · Pagi',
      shiftSchedule: 'Sabtu, 24 Okt · 08:00–16:00',
      woTarget: 6,
      woDone: 0,
      woInProgress: 0,
      pendingSyncCount: 0,
      nextTask: null,
      pasilaSummary: 'Sesi siap · 0 temuan',
    );

    await pumpBeranda(tester, data: data);

    expect(find.textContaining('menunggu sinkronisasi'), findsNothing);
    expect(find.text('Belum ada tugas pada shift ini.'), findsOneWidget);
  });

  testWidgets('tombol lanjutkan membuka detail tugas berikutnya', (
    tester,
  ) async {
    await pumpBeranda(tester);

    // Font tes lebih lebar dari font asli, jadi tombol bisa di bawah layar.
    await tester.ensureVisible(find.text('Lanjutkan pekerjaan'));
    await tester.pump();
    await tester.tap(find.text('Lanjutkan pekerjaan'));
    await tester.pumpAndSettle();

    expect(find.byType(DetailWoScreen), findsOneWidget);
    expect(find.text('Tiang JP-045'), findsOneWidget);
  });

  testWidgets('menu yang belum dibuat memberi tahu belum tersedia', (
    tester,
  ) async {
    await pumpBeranda(tester);

    await tester.tap(find.byTooltip('Notifikasi'));
    await tester.pump();

    expect(find.text('Notifikasi belum tersedia'), findsOneWidget);
  });

  testWidgets('tidak overflow pada HP kecil', (tester) async {
    await pumpBeranda(tester, size: const Size(320, 568));

    expect(tester.takeException(), isNull);
  });
}
