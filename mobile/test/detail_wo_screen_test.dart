import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/mock/mock_work_orders.dart';
import 'package:mobile/screens/detail_wo_screen.dart';
import 'package:mobile/theme/app_theme.dart';

final activeWo = mockWorkOrders.firstWhere((wo) => wo.pole == 'JP-045');
final finishedWo = mockWorkOrders.firstWhere((wo) => wo.pole == 'JP-041');

Future<void> pumpDetail(
  WidgetTester tester,
  WorkOrderItem item, {
  Future<bool> Function(Uri uri)? openUrl,
  Size size = const Size(360, 1600),
}) async {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = size;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.light(),
      home: DetailWoScreen(item: item, openUrl: openUrl ?? (_) async => true),
    ),
  );
}

void main() {
  testWidgets('menampilkan data WO dan riwayat', (tester) async {
    await pumpDetail(tester, activeWo);

    expect(find.text('WO-APKUK-20261024-0003'), findsOneWidget);
    expect(find.text('Tiang JP-045'), findsOneWidget);
    expect(find.text('Shift A · Urutan 3'), findsOneWidget);
    expect(find.text('JAIPONG'), findsOneWidget);
    expect(find.text('REC JABO'), findsOneWidget);
    expect(
      find.text('Laporan terakhir: terpasang 2 bh, kurang 3 bh'),
      findsOneWidget,
    );
    expect(find.text('-5.21099, 105.46007'), findsOneWidget);
    expect(find.text('Mulai dikerjakan'), findsOneWidget);
    expect(find.text('Ditugaskan'), findsOneWidget);
  });

  testWidgets('buka Google Maps memakai koordinat tiang', (tester) async {
    Uri? opened;
    await pumpDetail(
      tester,
      activeWo,
      openUrl: (uri) async {
        opened = uri;
        return true;
      },
    );

    await tester.tap(find.text('Buka Google Maps'));
    await tester.pump();

    expect(opened?.host, 'www.google.com');
    expect(opened?.path, '/maps/search/');
    expect(opened?.queryParameters['api'], '1');
    expect(opened?.queryParameters['query'], '-5.21099,105.46007');
  });

  testWidgets('memberi tahu bila Google Maps gagal dibuka', (tester) async {
    await pumpDetail(tester, activeWo, openUrl: (_) async => false);

    await tester.tap(find.text('Buka Google Maps'));
    await tester.pump();
    await tester.pump();

    expect(find.text('Google Maps tidak dapat dibuka'), findsOneWidget);
  });

  testWidgets('tetap aman bila Android menolak membuka tautan', (tester) async {
    await pumpDetail(
      tester,
      activeWo,
      openUrl: (_) async => throw PlatformException(code: 'ACTIVITY_NOT_FOUND'),
    );

    await tester.tap(find.text('Buka Google Maps'));
    await tester.pump();
    await tester.pump();

    expect(find.text('Google Maps tidak dapat dibuka'), findsOneWidget);
  });

  testWidgets('salin koordinat ke clipboard', (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String;
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    await pumpDetail(tester, activeWo);

    await tester.tap(find.byTooltip('Salin koordinat'));
    await tester.pump();
    await tester.pump();

    expect(copied, '-5.21099, 105.46007');
    expect(find.text('Koordinat disalin'), findsOneWidget);
  });

  testWidgets('WO aktif punya tiga tindakan', (tester) async {
    await pumpDetail(tester, activeWo);

    expect(find.text('Laporkan selesai'), findsOneWidget);
    expect(find.text('Reschedule'), findsOneWidget);
    expect(find.text('Sudah lengkap'), findsOneWidget);

    await tester.tap(find.text('Laporkan selesai'));
    await tester.pump();
    expect(find.text('Form laporan selesai belum tersedia'), findsOneWidget);
  });

  testWidgets('WO selesai tidak punya tindakan', (tester) async {
    await pumpDetail(tester, finishedWo);

    expect(find.text('Laporkan selesai'), findsNothing);
    expect(find.text('Reschedule'), findsNothing);
    expect(find.text('Sudah lengkap'), findsNothing);
    expect(find.text('Laporan selesai'), findsOneWidget);
  });

  testWidgets('tidak overflow pada HP kecil', (tester) async {
    await pumpDetail(tester, activeWo, size: const Size(320, 568));

    expect(tester.takeException(), isNull);
  });
}
