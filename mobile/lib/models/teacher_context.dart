import 'dart:convert';

/// Teacher branding/config coming from the SAME landing-page source as the
/// web (TeacherSiteController::sitePayload) — never a second content system.
class SiteConfig {
  const SiteConfig({
    this.id,
    this.slug,
    this.name,
    this.logoUrl,
    this.primaryColor,
    this.accentColor,
    this.email,
    this.phone,
    this.siteConfig = const {},
  });

  final String? id;
  final String? slug;
  final String? name;
  final String? logoUrl;
  final String? primaryColor;
  final String? accentColor;
  final String? email;
  final String? phone;
  final Map<String, dynamic> siteConfig;

  String get heroTitle {
    final slides = siteConfig['hero_slides'];
    if (slides is List && slides.isNotEmpty) {
      final first = slides.first;
      if (first is Map) return first['title']?.toString() ?? name ?? 'منصتي';
    }
    return name ?? 'منصتي';
  }

  String get heroSubtitle {
    final slides = siteConfig['hero_slides'];
    if (slides is List && slides.isNotEmpty) {
      final first = slides.first;
      if (first is Map) return first['subtitle']?.toString() ?? '';
    }
    return '';
  }

  factory SiteConfig.fromJson(Map<String, dynamic> json) => SiteConfig(
        id: json['id']?.toString(),
        slug: json['slug']?.toString(),
        name: json['name']?.toString(),
        logoUrl: json['logo_url']?.toString(),
        primaryColor: json['primary_color']?.toString(),
        accentColor: json['accent_color']?.toString(),
        email: json['email']?.toString(),
        phone: json['phone']?.toString(),
        siteConfig: (json['site_config'] as Map?)?.cast<String, dynamic>() ?? const {},
      );
}

/// The validated Teacher Context that binds the app to one teacher.
/// Stored securely on-device for speed, but ALWAYS re-validated by the
/// backend before any protected resource is served.
class TeacherContext {
  const TeacherContext({
    required this.id,
    required this.slug,
    required this.name,
    required this.teacherCode,
    this.site,
    this.appConfig = const {},
    this.validatedAt,
  });

  final String id;
  final String slug;
  final String name;
  final String teacherCode;
  final SiteConfig? site;
  final Map<String, dynamic> appConfig;
  final DateTime? validatedAt;

  String toJsonString() => jsonEncode({
        'id': id,
        'slug': slug,
        'name': name,
        'teacher_code': teacherCode,
        'site': site == null
            ? null
            : jsonEncode({
                'name': site!.name,
                'slug': site!.slug,
                'logo_url': site!.logoUrl,
                'primary_color': site!.primaryColor,
                'accent_color': site!.accentColor,
                'email': site!.email,
                'phone': site!.phone,
                'site_config': site!.siteConfig,
              }),
        'app_config': appConfig,
        'validated_at': validatedAt?.toIso8601String(),
      });

  factory TeacherContext.fromJsonString(String raw) {
    final json = jsonDecode(raw) as Map<String, dynamic>;
    final siteJson = json['site'];
    return TeacherContext(
      id: json['id'] as String,
      slug: json['slug'] as String,
      name: json['name'] as String,
      teacherCode: json['teacher_code'] as String,
      site: siteJson == null
          ? null
          : SiteConfig.fromJson(jsonDecode(siteJson as String) as Map<String, dynamic>),
      appConfig: (json['app_config'] as Map?)?.cast<String, dynamic>() ?? const {},
      validatedAt: json['validated_at'] != null
          ? DateTime.tryParse(json['validated_at'] as String)
          : null,
    );
  }

  static TeacherContext fromValidateResponse(Map<String, dynamic> teacher) {
    final teacherId = teacher['teacher']['id'].toString();
    final slug = teacher['teacher']['slug'].toString();
    final name = teacher['teacher']['name'].toString();
    final code = teacher['teacher']['teacher_code'].toString();
    final site = teacher['site'] as Map<String, dynamic>?;
    final app = teacher['app'] as Map<String, dynamic>?;

    return TeacherContext(
      id: teacherId,
      slug: slug,
      name: name,
      teacherCode: code,
      site: site == null ? null : SiteConfig.fromJson(site),
      appConfig: app?.cast<String, dynamic>() ?? const {},
      validatedAt: DateTime.now(),
    );
  }
}

class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    this.avatarUrl,
    this.locale = 'ar',
  });

  final String id;
  final String name;
  final String email;
  final String? avatarUrl;
  final String locale;

  factory User.fromJson(Map<String, dynamic> json) => User(
        id: json['id'].toString(),
        name: json['name']?.toString() ?? '',
        email: json['email']?.toString() ?? '',
        avatarUrl: json['avatar_url']?.toString(),
        locale: json['locale']?.toString() ?? 'ar',
      );
}
