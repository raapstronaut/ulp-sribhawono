import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mobile/services/posko_auth.dart';

void main() {
  test('login HTTP 401 menampilkan kesalahan kredensial', () async {
    final client = MockClient((request) async {
      expect(request.method, 'POST');
      expect(request.url.path, '/api/v1/mobile/auth/login');

      return http.Response(
        '{"message":"Unauthenticated."}',
        401,
        headers: {'content-type': 'application/json'},
      );
    });

    addTearDown(client.close);

    final auth = HttpPoskoAuth(
      client: client,
      baseUrl: 'https://example.test/api/v1/mobile',
    );

    await expectLater(
      auth.login('posko_jabung', 'wrong-password'),
      throwsA(
        isA<PoskoAuthError>().having(
          (error) => error.message,
          'message',
          'Username atau kata sandi salah.',
        ),
      ),
    );
  });
}
