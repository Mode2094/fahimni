import 'package:flutter/foundation.dart';

import '../models/api_response.dart';
import '../models/teacher_context.dart';
import 'api_client.dart';
import 'config.dart';
import 'secure_store.dart';

/// Central app state. Holds the validated Teacher Context + auth token and
/// exposes the navigation gate:
///   no teacher  -> TeacherCodeScreen (cannot be bypassed)
///   teacher, no auth -> LoginScreen
///   teacher + auth -> Home shell
///
/// Server-authoritative: cached context is only used for UX speed; every
/// protected call still sends the token + X-Tenant-Id and the backend
/// re-validates membership.
class AppState extends ChangeNotifier {
  AppState({ApiClient? api, SecureStore? store})
      : api = api ?? ApiClient(),
        store = store ?? SecureStore();

  final ApiClient api;
  final SecureStore store;

  TeacherContext? teacher;
  String? token;
  User? user;
  bool bootstrapped = false;
  bool busy = false;

  /// True when the most recent network call failed to reach the server
  /// (timeout / DNS / connection refused) — lets the UI offer connection
  /// settings instead of just a generic error.
  bool lastErrorWasConnection = false;

  bool get hasTeacher => teacher != null;
  bool get hasAuth => token != null && user != null;

  String? _deviceId;
  String get deviceId => _deviceId ?? ApiClient.generatedDeviceId;

  Future<void> bootstrap() async {
    if (bootstrapped) return;

    // Apply any persisted API base-URL override before the first request.
    final savedUrl = await store.readApiBaseUrl();
    if (savedUrl != null && savedUrl.isNotEmpty) {
      AppConfig.setOverride(savedUrl);
    }

    // Stable per-device id (persisted) — required so the device-policy limit
    // isn't tripped on every reload.
    _deviceId = await store.getOrCreateDeviceId();

    teacher = await store.readTeacherContext();
    token = await store.readToken();
    final userRaw = await store.readUser();
    user = userRaw != null ? User.fromJson(userRaw) : null;

    api.setAuth(token, teacher?.id, _deviceId);
    bootstrapped = true;
    notifyListeners();
  }

  /// Validate a teacher code against the backend. On success the app becomes
  /// bound to that teacher. On failure returns a localized message.
  Future<String?> validateTeacherCode(String code) async {
    busy = true;
    notifyListeners();
    try {
      final res = await api.post('/mobile/teacher-code/validate', body: {
        'teacher_code': code.trim().toUpperCase(),
      });

      if (!res.ok) {
        return _validationError(res);
      }

      final teacherPayload = res.data?['teacher'] as Map<String, dynamic>?;
      if (teacherPayload == null) return 'استجابة غير صالحة من الخادم';

      teacher = TeacherContext.fromValidateResponse(teacherPayload);
      // If the app was already bound to a DIFFERENT teacher, a fresh login
      // is required — contexts must never mix.
      if (_previousTenantId != null && teacher!.id != _previousTenantId) {
        token = null;
        user = null;
        api.setAuth(null, null, deviceId);
        await store.clearAuth();
      }
      _previousTenantId = teacher!.id;
      api.setAuth(token, teacher!.id, deviceId);
      await store.saveTeacherContext(teacher!);
      notifyListeners();
      return null;
    } catch (e) {
      lastErrorWasConnection = true;
      return _connectionError(e);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  String? _previousTenantId;

  /// Scoped login: the teacher code is re-validated by the backend and the
  /// student membership is enforced server-side.
  Future<String?> login(String email, String password) async {
    busy = true;
    notifyListeners();
    try {
      final res = await api.post('/mobile/auth/login', body: {
        'teacher_code': teacher?.teacherCode ?? '',
        'email': email.trim().toLowerCase(),
        'password': password,
      });

      if (!res.ok) {
        return res.message ?? 'تعذر تسجيل الدخول';
      }

      token = res.data?['token']?.toString();
      final userJson = res.data?['user'] as Map<String, dynamic>?;
      if (userJson != null) user = User.fromJson(userJson);

      api.setAuth(token, teacher?.id, deviceId);
      if (token != null) await store.saveToken(token!);
      if (user != null) await store.saveUser(user!);
      notifyListeners();
      return null;
    } catch (e) {
      lastErrorWasConnection = true;
      return _connectionError(e);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  /// Logout clears the session but KEEPS the teacher context — the student
  /// returns to the login screen of the same teacher.
  Future<void> logout() async {
    token = null;
    user = null;
    api.setAuth(null, teacher?.id, deviceId);
    await store.clearAuth();
    notifyListeners();
  }

  /// Student self-registration inside the teacher context (same pattern as
  /// web student signup). The new account is attached to the teacher as an
  /// active student by the backend.
  Future<String?> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String confirm,
  }) async {
    busy = true;
    notifyListeners();
    try {
      final res = await api.post('/mobile/auth/register', body: {
        'teacher_code': teacher?.teacherCode ?? '',
        'name': name,
        'email': email.trim().toLowerCase(),
        'phone': phone,
        'password': password,
        'password_confirmation': confirm,
      });

      if (!res.ok) {
        return res.reason == 'account_exists'
            ? 'هذا البريد مسجل مسبقاً — سجل دخولك بدلاً من ذلك.'
            : (res.message ?? 'تعذر إنشاء الحساب');
      }

      token = res.data?['token']?.toString();
      final userJson = res.data?['user'] as Map<String, dynamic>?;
      if (userJson != null) user = User.fromJson(userJson);

      api.setAuth(token, teacher?.id, deviceId);
      if (token != null) await store.saveToken(token!);
      if (user != null) await store.saveUser(user!);
      notifyListeners();
      return null;
    } catch (e) {
      lastErrorWasConnection = true;
      return _connectionError(e);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  /// Google (Gmail) sign-in inside the teacher context. The backend verifies
  /// the ID token server-side and attaches the account as a student.
  Future<String?> googleLogin(String idToken, {String? name, String? phone}) async {
    busy = true;
    notifyListeners();
    try {
      final res = await api.post('/mobile/auth/google', body: {
        'teacher_code': teacher?.teacherCode ?? '',
        'id_token': idToken,
        if (name != null && name.isNotEmpty) 'name': name,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
      });

      if (!res.ok) {
        return res.message ?? 'تعذر تسجيل الدخول بجوجل';
      }

      token = res.data?['token']?.toString();
      final userJson = res.data?['user'] as Map<String, dynamic>?;
      if (userJson != null) user = User.fromJson(userJson);

      api.setAuth(token, teacher?.id, deviceId);
      if (token != null) await store.saveToken(token!);
      if (user != null) await store.saveUser(user!);
      notifyListeners();
      return null;
    } catch (e) {
      lastErrorWasConnection = true;
      return _connectionError(e);
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  /// Change teacher: fully clears context + auth and returns to the code
  /// screen. Protected from accidental use by explicit confirmation in UI.
  Future<void> changeTeacher() async {
    token = null;
    user = null;
    teacher = null;
    api.setAuth(null, null, deviceId);
    await store.clearAll();
    notifyListeners();
  }

  /// Builds a diagnostic connection error: which URL was tried + why it
  /// failed, so a real device can be pointed at the right host.
  String _connectionError(Object e) {
    var detail = '$e'.replaceAll('\n', ' ').trim();
    if (detail.length > 90) detail = detail.substring(0, 90);
    if (detail.contains('timed out')) {
      detail = 'انتهت مهلة الاتصال — الخادم لا يستجيب';
    } else if (detail.contains('Failed host lookup') || detail.contains('SocketException')) {
      detail = 'تعذر إيجاد الخادم (DNS)';
    } else if (detail.contains('Connection refused')) {
      detail = 'الاتصال مرفوض — تحقق من الرابط والمنفذ';
    }
    return 'تعذر الوصول إلى الخادم\n${AppConfig.apiBaseUrl}\n$detail';
  }

  String? _validationError(ApiResponse res) {
    final reason = res.reason;
    return switch (reason) {
      'code_not_found' => 'كود المعلم غير صحيح. تأكد من الكود ثم حاول مجدداً.',
      'teacher_inactive' => 'حساب المعلم غير مفعل.',
      'feature_disabled' => 'تطبيق الطلاب — قيد التطوير.',
      'subscription_inactive' => 'اشتراك المعلم غير نشط حالياً.',
      'plan_denied' => 'تطبيق الطلاب غير متاح في خطة هذا المعلم.',
      _ => res.message ?? 'تعذر التحقق من الكود.',
    };
  }

  Future<void> applyDeepLinkCode(String code) async {
    final c = code.trim().toUpperCase();
    if (c.isEmpty || teacher?.teacherCode == c) return;
    await validateTeacherCode(c);
  }
}
