import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models/teacher_context.dart';

/// Secure on-device persistence.
///
///  - Teacher Context + token + user live in the OS keystore/keychain
///    (flutter_secure_storage).
///  - The cached context is a SPEED optimization only. Every protected call
///    re-validates against the backend; cache is never the source of truth.
class SecureStore {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(),
  );

  static const _kTeacher = 'shahead.teacher_context';
  static const _kToken = 'shahead.token';
  static const _kUser = 'shahead.user';

  Future<TeacherContext?> readTeacherContext() async {
    try {
      final raw = await _storage.read(key: _kTeacher);
      if (raw == null) return null;
      return TeacherContext.fromJsonString(raw);
    } catch (_) {
      return null;
    }
  }

  Future<void> saveTeacherContext(TeacherContext ctx) async {
    try {
      await _storage.write(key: _kTeacher, value: ctx.toJsonString());
    } catch (_) {
      // Web/edge cases: never crash the app because persistence failed.
    }
  }

  Future<String?> readToken() async {
    try {
      return await _storage.read(key: _kToken);
    } catch (_) {
      return null;
    }
  }

  Future<void> saveToken(String token) async {
    try {
      await _storage.write(key: _kToken, value: token);
    } catch (_) {}
  }

  Future<Map<String, dynamic>?> readUser() async {
    try {
      final raw = await _storage.read(key: _kUser);
      if (raw == null) return null;
      final decoded = jsonDecode(raw);
      if (decoded is Map<String, dynamic>) return decoded;
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<void> saveUser(User user) async {
    try {
      await _storage.write(
        key: _kUser,
        value: jsonEncode({
          'id': user.id,
          'name': user.name,
          'email': user.email,
          'avatar_url': user.avatarUrl,
          'locale': user.locale,
        }),
      );
    } catch (_) {}
  }

  /// Clear the session but keep the teacher context (logout).
  Future<void> clearAuth() async {
    try {
      await _storage.delete(key: _kToken);
      await _storage.delete(key: _kUser);
    } catch (_) {}
  }

  /// Clear everything (change teacher).
  Future<void> clearAll() async {
    try {
      await _storage.deleteAll();
      final prefs = await SharedPreferences.getInstance();
      await prefs.clear();
    } catch (_) {}
  }

  /// Stable device id persisted per browser/device. Without this the app
  /// would register a "new device" on every reload and trip the teacher's
  /// device-policy limit.
  Future<String> getOrCreateDeviceId() async {
    const key = 'shahead.device_id';
    try {
      final prefs = await SharedPreferences.getInstance();
      final existing = prefs.getString(key);
      if (existing != null && existing.isNotEmpty) return existing;

      final rand = DateTime.now().microsecondsSinceEpoch;
      final id = 'flutter-$rand';
      await prefs.setString(key, id);
      return id;
    } catch (_) {
      return 'flutter-${DateTime.now().microsecondsSinceEpoch}';
    }
  }

  /// Persisted API base-URL override (set from the in-app connection dialog
  /// so a real device can point at the host's LAN IP without rebuilding).
  Future<String?> readApiBaseUrl() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getString('shahead.api_base_url');
    } catch (_) {
      return null;
    }
  }

  Future<void> saveApiBaseUrl(String url) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('shahead.api_base_url', url);
    } catch (_) {}
  }
}
