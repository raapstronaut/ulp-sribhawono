import 'package:mobile/services/posko_auth.dart';

class FakePoskoAuth implements PoskoAuth {
  FakePoskoAuth({this.shouldFail = false});
  final bool shouldFail;

  @override
  Future<PoskoSession> login(String username, String password) async {
    if (shouldFail) {
      throw const PoskoAuthError('Username atau kata sandi salah.');
    }
    return PoskoSession(
      token: 'fake-token-never-used-on-network',
      account: {
        'id': 1,
        'username': username,
        'role': 'POSKO',
        'team': {'id': 'test-team', 'posko_id': 'test-posko'},
      },
      bootstrap: {
        'team': {'id': 'test-team', 'name': 'POSKO JABUNG'},
        'personnel': List.generate(8, (i) => {'id': '$i'}),
        'posko_crews': {
          'roster_complete': true,
          'rotation': {'configured': false},
          'crews': [
            {
              'number': 1,
              'label': 'Tim 1 - Petugas A & Petugas B',
              'is_complete': true,
            },
          ],
        },
      },
    );
  }

  @override
  Future<void> logout(PoskoSession session) async {}
}
