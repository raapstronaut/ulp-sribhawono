import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/main.dart';

void main() {
  testWidgets('menampilkan informasi fondasi aplikasi tim lapangan', (tester) async {
    await tester.pumpWidget(const UlpSribhawonoApp());
    expect(find.text('ULP Sribhawono'), findsOneWidget);
    expect(find.text('Aplikasi Operasional Lapangan'), findsOneWidget);
    expect(find.text('POSKO • HARSUS • INSPEKSI • ROW'), findsOneWidget);
  });
}
