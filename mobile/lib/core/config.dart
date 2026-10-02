import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';

/// Build/runtime API configuration.
///
/// Priority order:
///   1. --dart-define=API_BASE_URL=... (production / CI)
///   2. In-app connection override (persisted — set from the Teacher Code
///      screen's connection dialog, e.g. a real device's LAN IP).
///   3. Platform-aware dev default:
///        - Android emulator  → http://10.0.2.2:8080/api/v1 (host loopback)
///        - Web / iOS sim     → http://localhost:8080/api/v1
///
/// No build should ever silently point at the non-resolving placeholder.
class AppConfig {
  static const String _defineUrl = String.fromEnvironment('API_BASE_URL');

  static String? _override;

  static String get apiBaseUrl {
    if (_override != null && _override!.isNotEmpty) return _override!;
    if (_defineUrl.isNotEmpty) return _defineUrl;
    return defaultApiBaseUrl;
  }

  /// Runtime override (persisted) — takes priority over the build define so
  /// a real device can point at the host's LAN IP without rebuilding.
  static void setOverride(String? url) {
    final trimmed = url?.trim() ?? '';
    _override = trimmed.isEmpty ? null : _withoutTrailingSlash(trimmed);
  }

  static String? get override => _override;

  static String get defaultApiBaseUrl {
    if (!kIsWeb && Platform.isAndroid) {
      return 'http://10.0.2.2:8080/api/v1';
    }
    return 'http://localhost:8080/api/v1';
  }

  static String _withoutTrailingSlash(String url) =>
      url.endsWith('/') ? url.substring(0, url.length - 1) : url;

  static const String deepLinkScheme = 'shahead';

  static const String devicePlatform = 'android';

  /// How long a validated teacher context stays cached before the app is
  /// forced to re-validate it against the backend.
  static const Duration contextRevalidationTtl = Duration(hours: 12);
}
