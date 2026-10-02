import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// Screenshot / screen-capture protection via a first-party platform channel
/// (no third-party plugin — keeps the surface small and auditable).
///
/// Android: sets FLAG_SECURE on the window — the OS blocks screenshots and
/// screen recording of the app content.
/// iOS: the native side blurs the app on screenshot / screen-capture and
/// reports capture events here so the UI can react.
/// External cameras cannot be blocked by software — out of scope, never claimed.
class ScreenshotGuard {
  static const MethodChannel _channel = MethodChannel('shahead/security');

  static bool _enabled = false;

  static Future<void> enable() async {
    if (_enabled) return;
    try {
      await _channel.invokeMethod('preventScreenshotOn');
      _enabled = true;
      debugPrint('screen-protection: enabled');
    } catch (e) {
      debugPrint('screen-protection: failed to enable — $e');
    }
  }

  /// iOS-only: receives screenshot / screen-record events.
  static void watch(void Function() onScreenshot, void Function(bool) onRecord) {
    _channel.setMethodCallHandler((call) async {
      switch (call.method) {
        case 'onScreenshot':
          onScreenshot();
        case 'onScreenRecord':
          final captured = call.arguments as bool? ?? false;
          onRecord(captured);
      }
      return null;
    });
  }

  static Future<void> disable() async {
    try {
      await _channel.invokeMethod('preventScreenshotOff');
    } catch (_) {}
    _enabled = false;
  }
}
