import 'package:google_sign_in/google_sign_in.dart';

/// Wraps native Google Sign-In (v7 API) and returns a Google ID token that
/// the backend verifies server-side — the client is never trusted.
///
/// Configuration required per platform before this works in production:
///  - Android: google-services.json with an OAuth web client id (serverClientId).
///  - iOS: reversed client id / GoogleService-Info.plist.
///  - Web: a Google Cloud web client id (passed via `clientId`).
/// Until configured, signIn() returns a clear "not configured" message
/// instead of crashing, so the app stays usable.
class GoogleAuthHelper {
  GoogleAuthHelper({String? clientId, String? serverClientId})
      : _clientId = clientId,
        _serverClientId = serverClientId;

  final String? _clientId;
  final String? _serverClientId;
  static bool _initialized = false;

  /// Returns a Google idToken on success, or a user-facing error message.
  Future<String> signIn() async {
    try {
      if (!_initialized) {
        await GoogleSignIn.instance.initialize(
          clientId: _clientId,
          serverClientId: _serverClientId,
        );
        _initialized = true;
      }
      final account = await GoogleSignIn.instance.authenticate();
      final idToken = account.authentication.idToken;
      if (idToken == null || idToken.isEmpty) {
        return 'تعذر الحصول على توكن جوجل — تأكد من إعدادات Google في التطبيق';
      }
      return idToken;
    } catch (_) {
      return 'تسجيل الدخول بجوجل غير مهيأ على هذا التطبيق بعد';
    }
  }
}
