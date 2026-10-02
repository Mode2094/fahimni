import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../models/course.dart';
import '../models/teacher_context.dart';
import 'course_detail_screen.dart';

/// Home tab: branded hero header + my enrolled courses + teacher
/// announcements. Every call goes through the authenticated mobile API
/// (server re-validates the teacher context + membership).
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.state});

  final AppState state;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  List<EnrolledCourse> _courses = [];
  List<Announcement> _announcements = [];
  SiteConfig? _site;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await widget.state.api.get('/mobile/home');
      if (!res.ok) {
        setState(() {
          _loading = false;
          _error = res.message ?? 'فشل التحميل';
        });
        return;
      }
      final data = res.data!;
      final site = data['site'] as Map<String, dynamic>?;
      final enrolled = (data['enrolled_courses'] as List? ?? [])
          .map((e) => EnrolledCourse.fromJson(e as Map<String, dynamic>))
          .toList();
      final ann = (data['announcements'] as List? ?? [])
          .map((e) => Announcement.fromJson(e as Map<String, dynamic>))
          .toList();

      if (!mounted) return;
      setState(() {
        _site = site == null ? null : SiteConfig.fromJson(site);
        _courses = enrolled;
        _announcements = ann;
        _loading = false;
      });
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'تعذر الاتصال بالخادم';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);
    final site = _site ?? widget.state.teacher?.site;
    final user = widget.state.user;

    return Scaffold(
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: _loading
              ? const LoadingView()
              : _error != null
                  ? ErrorView(message: _error!, onRetry: _load)
                  : RefreshIndicator(
                      onRefresh: _load,
                      color: AppColors.primary,
                      child: ListView(
                        padding: EdgeInsets.zero,
                        children: [
                          _buildHero(locale, site, user),
                          Padding(
                            padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                SectionHeader(
                                  title: L10n.tr(locale, 'كورساتي', 'My courses'),
                                  action: _courses.isEmpty ? null : () {},
                                ),
                                if (_courses.isEmpty)
                                  EmptyState(
                                    icon: Icons.menu_book_outlined,
                                    title: L10n.tr(locale, 'لم تسجل في أي كورس بعد', 'You have not enrolled in any course yet'),
                                    subtitle: L10n.tr(locale, 'تصفح كورسات المعلم من تبويب الكورسات', 'Browse the teacher\'s courses from the Courses tab'),
                                  )
                                else
                                  ..._courses.map((ec) => Padding(
                                        padding: const EdgeInsets.only(bottom: 14),
                                        child: _EnrolledCourseCard(
                                          course: ec.course,
                                          enrolledAt: ec.enrolledAt,
                                          onTap: () => Navigator.of(context).push(
                                            MaterialPageRoute(
                                              builder: (_) => CourseDetailScreen(state: widget.state, courseId: ec.course.id),
                                            ),
                                          ),
                                        ),
                                      )),
                                if (_announcements.isNotEmpty) ...[
                                  SectionHeader(title: L10n.tr(locale, 'إعلانات المعلم', 'Announcements')),
                                  ..._announcements.map((a) => Padding(
                                        padding: const EdgeInsets.only(bottom: 12),
                                        child: _AnnouncementCard(announcement: a),
                                      )),
                                ],
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
        ),
      ),
    );
  }

  Widget _buildHero(Locale locale, SiteConfig? site, User? user) {
    final brand = Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 44),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [
            brand,
            brand.withValues(alpha: 0.82),
            AppColors.primaryDeep.withValues(alpha: 0.9),
          ],
        ),
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(34)),
        boxShadow: AppShadows.floating,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      L10n.tr(locale, 'أهلاً بك', 'Welcome'),
                      style: const TextStyle(color: Colors.white70, fontSize: 14, fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      user?.name ?? L10n.tr(locale, 'طالب', 'Student'),
                      style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              if (site?.logoUrl != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(14),
                  child: Image.network(site!.logoUrl!, width: 44, height: 44, errorBuilder: (_, __, ___) => const SizedBox()),
                ),
            ],
          ),
          const SizedBox(height: 22),
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(AppRadius.lg),
              border: Border.all(color: Colors.white.withValues(alpha: 0.2)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  site?.name ?? L10n.tr(locale, 'منصتي التعليمية', 'My academy'),
                  style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800),
                ),
                if (site?.heroSubtitle.isNotEmpty ?? false) ...[
                  const SizedBox(height: 6),
                  Text(
                    site!.heroSubtitle,
                    style: const TextStyle(color: Colors.white70, fontSize: 13, height: 1.5),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _EnrolledCourseCard extends StatelessWidget {
  const _EnrolledCourseCard({required this.course, required this.onTap, this.enrolledAt});

  final Course course;
  final VoidCallback onTap;
  final DateTime? enrolledAt;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SoftCard(
      onTap: onTap,
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [AppColors.primaryBright, AppColors.primaryDeep],
              ),
              borderRadius: BorderRadius.circular(18),
            ),
            child: const Icon(Icons.menu_book, color: Colors.white, size: 26),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  course.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.textPrimary),
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    const StatusChip(
                      label: 'مسجل',
                      color: AppColors.success,
                      icon: Icons.check_circle,
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '${course.lessonsCount} ${course.lessonsCount == 1 ? 'درس' : 'دروس'}',
                      style: TextStyle(color: isDark ? AppColors.darkTextSecondary : AppColors.textSecondary, fontSize: 12),
                    ),
                  ],
                ),
              ],
            ),
          ),
          Icon(Icons.chevron_left, color: isDark ? AppColors.darkTextSecondary : AppColors.textTertiary),
        ],
      ),
    );
  }
}

class _AnnouncementCard extends StatelessWidget {
  const _AnnouncementCard({required this.announcement});

  final Announcement announcement;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SoftCard(
      padding: const EdgeInsets.all(18),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: AppColors.accentSoft,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.campaign_outlined, color: AppColors.accent, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(announcement.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: AppColors.textPrimary)),
                const SizedBox(height: 4),
                Text(
                  announcement.body,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: isDark ? AppColors.darkTextSecondary : AppColors.textSecondary, fontSize: 13, height: 1.5),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
