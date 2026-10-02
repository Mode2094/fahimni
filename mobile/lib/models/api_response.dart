import 'dart:convert';

/// Normalized API response: status + decoded JSON (or null on parse failure).
class ApiResponse {
  ApiResponse._(this.status, this.data);

  final int status;
  final Map<String, dynamic>? data;

  bool get ok => status >= 200 && status < 300;

  String? get message => data?['message'] as String?;

  String? get reason => data?['reason'] as String?;

  static ApiResponse fromStatus(int status, String body) {
    Map<String, dynamic>? decoded;
    try {
      final json = jsonDecode(body);
      if (json is Map<String, dynamic>) decoded = json;
    } catch (_) {}
    return ApiResponse._(status, decoded);
  }
}
