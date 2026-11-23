import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/services/work_order_read_api.dart';

void main() {
  test('work feed uses authenticated GET and maps backend response', () async {
    final api = WorkOrderReadApi(
      baseUrl: 'http://127.0.0.1:8000/api/v1/mobile',
      client: MockClient((http.Request request) async {
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/mobile/work-feed');
        expect(request.url.queryParameters['scope'], 'active');
        expect(request.url.queryParameters['page'], '1');
        expect(request.headers['Authorization'], 'Bearer fake-test-token');
        return http.Response(jsonEncode({
          'data': [
            {
              'id': '11111111-1111-1111-1111-111111111111',
              'code': 'WO-TEST-01',
              'status': 'ASSIGNED',
              'status_label': 'Ditugaskan',
              'work_type': 'PEMASANGAN_APKUK',
              'team_type': 'POSKO',
              'is_overdue': false,
              'section_name': 'REC JABO',
              'penyulang': {'name': 'JAIPONG'},
              'target': {
                'label': 'Tiang JP-045',
                'latitude': -5.21,
                'longitude': 105.46,
              },
            },
          ],
          'meta': {'page': 1, 'last_page': 1, 'total': 1},
        }), 200);
      }),
    );

    final page = await api.feed('fake-test-token', scope: 'active');
    expect(page.total, 1);
    expect(page.items.single.code, 'WO-TEST-01');
    expect(page.items.single.targetLabel, 'Tiang JP-045');
    expect(page.items.single.feeder, 'JAIPONG');
    expect(page.items.single.hasCoordinates, isTrue);
    api.close();
  });

  test('expired token returns a safe user-facing error', () async {
    final api = WorkOrderReadApi(
      baseUrl: 'http://127.0.0.1:8000/api/v1/mobile',
      client: MockClient((request) async => http.Response('', 401)),
    );
    await expectLater(
      api.feed('invalid-token', scope: 'active'),
      throwsA(isA<WorkOrderReadError>()),
    );
    api.close();
  });
}
