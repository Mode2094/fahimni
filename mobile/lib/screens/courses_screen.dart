import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../models/course.dart';
import 'course_detail_screen.dart';

/// All published courses of the current teacher, with the student's
/// enrollment state. Sourced from the same backend as the web.
class CoursesScreen extends StatefulWidget {
  const CoursesScreen({super.key, required this.state});

  final AppState state;

  @override
  State<CoursesScreen> createState() => _CoursesScreenState();
}

class _CoursesScreenState extends State<CoursesScreen> {
  List<Course> _courses = [];
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
      final res = await widget.state.api.get('/mobile/courses');
      if (!res.ok) {
        setState(() {
          _loading = false;
          _error = res.message ?? 'فشل التحميل';
        });
        return;
      }
      final list = (res.data?['courses'] as List? ?? [])
          .map((e) => Course.fromJson(e as Map<String, dynamic>))
          .toList();
      if (!mounted) return;
      setState(() {
        _courses = list;
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
    return Scaffold(
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        L10n.tr(locale, 'الكورسات', 'Courses'),
                        style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                      ),
                    ),
                    IconButton(
                      onPressed: _load,
                      icon: const Icon(Icons.refresh, color: AppColors.primary),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: _loading
                    ? const LoadingView()
                    : _error != null
                        ? ErrorView(message: _error!, onRetry: _load)
                        : _courses.isEmpty
                            ? EmptyState(
                                icon: Icons.menu_book_outlined,
                                title: L10n.tr(locale, 'لا توجد كورسات بعد', 'No courses yet'),
                                subtitle: L10n.tr(locale, 'عد لاحقاً — المعلم يضيف محتوى جديد', 'Check back soon — your teacher is adding content'),
                              )
                            : RefreshIndicator(
                                onRefresh: _load,
                                color: AppColors.primary,
                                child: ListView.separated(
                                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
                                  itemCount: _courses.length,
                                  separatorBuilder: (_, __) => const SizedBox(height: 14),
                                  itemBuilder: (context, i) => _CourseCard(
                                    course: _courses[i],
                                    onTap: () => Navigator.of(context).push(
                                      MaterialPageRoute(
                                        builder: (_) => CourseDetailScreen(state: widget.state, courseId: _courses[i].id),
                                      ),
                                    ),
                                  ),
                                ),
                              ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CourseCard extends StatelessWidget {
  const _CourseCard({required this.course, required this.onTap});

  final Course course;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SoftCard(
      onTap: onTap,
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Gradient cover header with course identity
          Container(
            height: 92,
            width: double.infinity,
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [AppColors.primaryBright, AppColors.primaryDeep],
              ),
              borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.md)),
            ),
            child: Stack(
              children: [
                Positioned(
                  right: -14,
                  top: -18,
                  child: Icon(Icons.menu_book, size: 90, color: Colors.white.withValues(alpha: 0.12)),
                ),
                Positioned(
                  left: 16,
                  bottom: 12,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      borderRadius: BorderRadius.circular(AppRadius.pill),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.play_circle_outline, size: 15, color: Colors.white),
                        const SizedBox(width: 5),
                        Text(
                          '${course.lessonsCount} ${course.lessonsCount == 1 ? 'درس' : 'دروس'}',
                          style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        course.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppColors.textPrimary),
                      ),
                    ),
                    if (course.isEnrolled) ...[
                      const SizedBox(width: 8),
                      const StatusChip(label: 'مسجل', color: AppColors.success, icon: Icons.check_circle),
                    ],
                  ],
                ),
                if ((course.shortDescription ?? '').isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Text(
                    course.shortDescription!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: isDark ? AppColors.darkTextSecondary : AppColors.textSecondary, fontSize: 13, height: 1.5),
                  ),
                ],
                const SizedBox(height: 12),
                Row(
                  children: [
                    const Icon(Icons.schedule, size: 15, color: AppColors.textTertiary),
                    const SizedBox(width: 5),
                    Text(
                      '${course.sectionsCount} ${course.sectionsCount == 1 ? 'قسم' : 'أقسام'}',
                      style: TextStyle(color: AppColors.textTertiary, fontSize: 12),
                    ),
                    const Spacer(),
                    Text(
                      course.priceLabel,
                      style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800, fontSize: 14),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
