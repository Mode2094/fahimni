import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';

import 'package:http/http.dart' as http;

import '../models/api_response.dart';
import 'config.dart';

/// Thin HTTP client for the SHAHEAD LMS backend.
///
/// Attaches the Bearer token, the validated Teacher Context (X-Tenant-Id)
/// and a stable device id to every request. The server is always the source
/// of truth — the app never trusts a locally cached context for access.
class ApiClient {
  ApiClient({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  String? _token;
  String? _tenantId;
  String? _deviceId;

  static final String generatedDeviceId = _makeDeviceId();

  static String _makeDeviceId() {
    try {
      final f = File('/dev/urandom');
      if (f.existsSync()) {
        final bytes = f.openSync().readSync(8);
        final id = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
        return 'flutter-$id';
      }
    } catch (_) {}
    final rand = DateTime.now().microsecondsSinceEpoch;
    return 'flutter-$rand';
  }

  void setAuth(String? token, String? tenantId, String? deviceId) {
    _token = token;
    _tenantId = tenantId;
    _deviceId = deviceId ?? _deviceId;
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        if (_token case final token?) 'Authorization': 'Bearer $token',
        if (_tenantId case final tenantId?) 'X-Tenant-Id': tenantId,
        if (_deviceId case final deviceId?) 'X-Device-Id': deviceId,
      };

  Future<ApiResponse> get(String path,
      {Map<String, String>? query}) async {
    var uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }
    try {
      final res = await _client
          .get(uri, headers: _headers)
          .timeout(_timeout, onTimeout: () => throw _timeoutError(path));
      return ApiResponse.fromStatus(res.statusCode, res.body);
    } catch (e, st) {
      debugPrint('API GET $path FAILED: $e');
      debugPrint('$st');
      rethrow;
    }
  }

  Future<ApiResponse> post(String path, {Object? body}) async {
    try {
      final res = await _client
          .post(
            Uri.parse('${AppConfig.apiBaseUrl}$path'),
            headers: {..._headers, 'Content-Type': 'application/json'},
            body: body == null ? null : jsonEncode(body),
          )
          .timeout(_timeout, onTimeout: () => throw _timeoutError(path));
      return ApiResponse.fromStatus(res.statusCode, res.body);
    } catch (e, st) {
      debugPrint('API POST $path FAILED: $e');
      debugPrint('$st');
      rethrow;
    }
  }

  Future<ApiResponse> put(String path, {Object? body}) async {
    try {
      final res = await _client
          .put(
            Uri.parse('${AppConfig.apiBaseUrl}$path'),
            headers: {..._headers, 'Content-Type': 'application/json'},
            body: body == null ? null : jsonEncode(body),
          )
          .timeout(_timeout, onTimeout: () => throw _timeoutError(path));
      return ApiResponse.fromStatus(res.statusCode, res.body);
    } catch (e, st) {
      debugPrint('API PUT $path FAILED: $e');
      debugPrint('$st');
      rethrow;
    }
  }

  static const Duration _timeout = Duration(seconds: 12);

  TimeoutException _timeoutError(String path) =>
      TimeoutException('Request to $path timed out');

  void dispose() => _client.close();
}
