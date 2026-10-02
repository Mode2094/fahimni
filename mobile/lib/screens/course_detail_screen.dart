import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../models/course.dart';
import 'lesson_screen.dart';

/// Course detail: gradient header + sections/lessons with server-computed
/// lock state (enrollment, drip, free preview). Tapping a locked lesson
/// explains why.
class CourseDetailScreen extends StatefulWidget {
  const CourseDetailScreen({super.key, required this.state, required this.courseId});

  final AppState state;
  final String courseId;

  @override
  State<CourseDetailScreen> createState() => _CourseDetailScreenState();
}

class _CourseDetailScreenState extends State<CourseDetailScreen> {
  Course? _course;
  List<Section> _sections = [];
  bool _enrolled = false;
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
      final res = await widget.state.api.get('/mobile/courses/${widget.courseId}');
      if (!res.ok) {
        setState(() {
          _loading = false;
          _error = res.message ?? 'فشل التحميل';
        });
        return;
      }
      final data = res.data!;
      if (!mounted) return;
      setState(() {
        _course = Course.fromJson(data['course'] as Map<String, dynamic>);
        _enrolled = data['enrolled'] == true;
        _sections = (data['sections'] as List? ?? [])
            .map((e) => Section.fromJson(e as Map<String, dynamic>))
            .toList();
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
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      body: AppBackground(
        child: CustomScrollView(
          slivers: [
            SliverAppBar(
              pinned: true,
              expandedHeight: 210,
              backgroundColor: isDark ? AppColors.darkBackground : AppColors.background,
              foregroundColor: Colors.white,
              flexibleSpace: FlexibleSpaceBar(
                background: Container(
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [AppColors.primaryBright, AppColors.primaryDeep],
                    ),
                    borderRadius: BorderRadius.vertical(bottom: Radius.circular(34)),
                  ),
                  child: Stack(
                    children: [
                      Positioned(
                        right: -22,
                        top: -30,
                        child: Icon(Icons.menu_book, size: 180, color: Colors.white.withValues(alpha: 0.1)),
                      ),
                      Positioned(
                        left: -30,
                        bottom: -40,
                        child: Icon(Icons.auto_stories_outlined, size: 150, color: Colors.white.withValues(alpha: 0.08)),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 40),
              sliver: SliverList(
                delegate: SliverChildListDelegate([
                  if (_loading)
                    const Padding(padding: EdgeInsets.only(top: 120), child: LoadingView())
                  else if (_error != null)
                    ErrorView(message: _error!, onRetry: _load)
                  else ...[
                    if (_course != null) ...[
                      Text(_course!.title, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
                      if ((_course!.description ?? '').isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Text(
                          _course!.description!,
                          style: TextStyle(color: isDark ? AppColors.darkTextSecondary : AppColors.textSecondary, height: 1.6),
                        ),
                      ],
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          StatusChip(label: _course!.priceLabel, color: AppColors.primary, icon: Icons.payments_outlined),
                          const SizedBox(width: 8),
                          StatusChip(label: '${_course!.lessonsCount} دروس', color: AppColors.accent, icon: Icons.play_circle_outline),
                        ],
                      ),
                      if (!_enrolled) ...[
                        const SizedBox(height: 14),
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: AppColors.accentSoft,
                            borderRadius: BorderRadius.circular(AppRadius.md),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.lock_outline, color: AppColors.accent, size: 20),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  L10n.tr(locale, 'سجل في هذا الكورس للوصول إلى الدروس الكاملة', 'Enroll in this course to access the full lessons'),
                                  style: const TextStyle(color: AppColors.accent, fontWeight: FontWeight.w700, fontSize: 13),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ],
                    const SizedBox(height: 12),
                    if (_sections.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 40),
                        child: EmptyState(
                          icon: Icons.menu_book_outlined,
                          title: 'لا توجد دروس في هذا الكورس بعد',
                        ),
                      )
                    else
                      ..._sections.map((section) => _SectionBlock(
                            section: section,
                            enrolled: _enrolled,
                            onLessonTap: (lesson) {
                              if (lesson.locked) {
                                _showLocked(context, lesson);
                                return;
                              }
                              Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => LessonScreen(state: widget.state, lessonId: lesson.id, courseId: widget.courseId),
                                ),
                              );
                            },
                          )),
                  ],
                ]),
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _showLocked(BuildContext context, Lesson lesson) {
    final locale = Localizations.localeOf(context);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          lesson.unlockAt != null
              ? L10n.tr(locale, 'هذا الدرس مقفل حتى ', 'This lesson unlocks on ') + lesson.unlockAt!.toIso8601String().split('T').first
              : L10n.tr(locale, 'هذا الدرس مقفل. سجل في الكورس أولاً.', 'This lesson is locked. Enroll in the course first.'),
        ),
      ),
    );
  }
}

class _SectionBlock extends StatelessWidget {
  const _SectionBlock({required this.section, required this.enrolled, required this.onLessonTap});

  final Section section;
  final bool enrolled;
  final ValueChanged<Lesson> onLessonTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 18, bottom: 10),
          child: Row(
            children: [
              Container(
                width: 6,
                height: 22,
                decoration: BoxDecoration(
                  color: AppColors.primary,
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(section.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: AppColors.textPrimary)),
              ),
              Text(
                '${section.lessons.length}',
                style: TextStyle(color: AppColors.textTertiary, fontSize: 13, fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ),
        ...section.lessons.asMap().entries.map((entry) {
          final lesson = entry.value;
          final locked = lesson.locked;
          return Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: SoftCard(
              onTap: () => onLessonTap(lesson),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
              color: isDark ? AppColors.darkSurfaceElevated : AppColors.surface,
              child: Row(
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    decoration: BoxDecoration(
                      gradient: locked
                          ? null
                          : const LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: [AppColors.primaryBright, AppColors.primaryDeep],
                            ),
                      color: locked ? AppColors.surfaceMuted : null,
                      borderRadius: BorderRadius.circular(15),
                    ),
                    child: Icon(
                      locked ? Icons.lock_outline : (lesson.lessonType == 'video' ? Icons.play_arrow : Icons.description_outlined),
                      color: locked ? AppColors.textTertiary : Colors.white,
                      size: 22,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          lesson.title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: 14,
                            color: locked ? AppColors.textTertiary : AppColors.textPrimary,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            if (lesson.isFreePreview)
                              const StatusChip(label: 'معاينة مجانية', color: AppColors.accent, icon: Icons.visibility_outlined)
                            else if (lesson.durationMinutes != null)
                              Text(
                                '${lesson.durationMinutes} د',
                                style: TextStyle(color: AppColors.textTertiary, fontSize: 12),
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  if (locked)
                    const Icon(Icons.lock_outline, size: 18, color: AppColors.textTertiary)
                  else
                    const Icon(Icons.chevron_left, color: AppColors.textTertiary, size: 22),
                ],
              ),
            ),
          );
        }),
      ],
    );
  }
}
