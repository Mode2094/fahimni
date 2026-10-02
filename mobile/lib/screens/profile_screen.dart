import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../models/api_response.dart';
import '../models/course.dart';

/// Profile tab: the authenticated student + teacher context, enrolled
/// courses, logout, and the protected "change teacher" action.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.state});

  final AppState state;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  List<EnrolledCourse> _courses = [];
  bool _confirmingChange = false;

  @override
  void initState() {
    super.initState();
    _loadCourses();
  }

  Future<void> _loadCourses() async {
    try {
      final ApiResponse res = await widget.state.api.get('/mobile/home');
      if (res.ok) {
        final enrolled = (res.data?['enrolled_courses'] as List? ?? [])
            .map((e) => EnrolledCourse.fromJson(e as Map<String, dynamic>))
            .toList();
        if (mounted) setState(() => _courses = enrolled);
      }
    } catch (_) {}
  }

  Future<void> _logout() async {
    await widget.state.logout();
    if (mounted) setState(() {});
  }

  Future<void> _changeTeacher() async {
    final locale = Localizations.localeOf(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(L10n.tr(locale, 'تغيير المعلم', 'Change teacher')),
        content: Text(
          L10n.tr(
            locale,
            'سيؤدي هذا إلى مسح الجلسة الحالية والعودة إلى شاشة كود المعلم. هل أنت متأكد؟',
            'This clears the current session and returns to the teacher-code screen. Are you sure?',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(
              L10n.tr(locale, 'إلغاء', 'Cancel'),
              style: const TextStyle(color: AppColors.textSecondary),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('تغيير', style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800)),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() => _confirmingChange = true);
    await widget.state.changeTeacher();
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);
    final user = widget.state.user;
    final teacher = widget.state.teacher;

    return Scaffold(
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            children: [
              Text(
                L10n.tr(locale, 'حسابي', 'Profile'),
                style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
              ),
              const SizedBox(height: 18),

              // ── Profile header ────────────────────────────────
              SoftCard(
                padding: const EdgeInsets.all(20),
                child: Row(
                  children: [
                    Container(
                      width: 64,
                      height: 64,
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [AppColors.primaryBright, AppColors.primaryDeep],
                        ),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: user?.avatarUrl != null
                          ? ClipRRect(
                              borderRadius: BorderRadius.circular(20),
                              child: Image.network(user!.avatarUrl!, width: 64, height: 64, fit: BoxFit.cover),
                            )
                          : const Icon(Icons.person, size: 34, color: Colors.white),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            user?.name ?? '',
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            user?.email ?? '',
                            style: const TextStyle(color: AppColors.textSecondary, fontSize: 13),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 16),

              // ── Teacher context ───────────────────────────────
              SoftCard(
                padding: const EdgeInsets.all(18),
                child: Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: AppColors.primarySoft,
                        borderRadius: BorderRadius.circular(15),
                      ),
                      child: const Icon(Icons.school_outlined, color: AppColors.primary, size: 24),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            L10n.tr(locale, 'المعلم الحالي', 'Current teacher'),
                            style: const TextStyle(color: AppColors.textTertiary, fontSize: 12, fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            teacher?.site?.name ?? teacher?.name ?? '',
                            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.textPrimary),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            '${L10n.tr(locale, 'كود المعلم:', 'Teacher code:')} ${teacher?.teacherCode ?? ''}',
                            style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 16),

              // ── Enrolled courses count ────────────────────────
              SoftCard(
                padding: const EdgeInsets.all(18),
                child: Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: AppColors.accentSoft,
                        borderRadius: BorderRadius.circular(15),
                      ),
                      child: const Icon(Icons.menu_book_outlined, color: AppColors.accent, size: 24),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Text(
                        L10n.tr(locale, 'كورساتي', 'My courses'),
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.textPrimary),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                      decoration: BoxDecoration(
                        color: AppColors.primarySoft,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                      ),
                      child: Text(
                        '${_courses.length}',
                        style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800, fontSize: 15),
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 28),

              // ── Actions ───────────────────────────────────────
              PrimaryButton(
                label: L10n.tr(locale, 'تسجيل الخروج', 'Logout'),
                onPressed: _logout,
                icon: Icons.logout,
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: _confirmingChange ? null : _changeTeacher,
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(52),
                  foregroundColor: AppColors.danger,
                  side: BorderSide(color: AppColors.danger.withValues(alpha: 0.4)),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
                ),
                icon: const Icon(Icons.swap_horiz, size: 20),
                label: Text(
                  L10n.tr(locale, 'تغيير المعلم', 'Change teacher'),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
