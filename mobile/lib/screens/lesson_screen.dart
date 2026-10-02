import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';
import '../models/course.dart';
import 'video_player_screen.dart';

/// Lesson contents. Videos arrive as SHORT-LIVED, SIGNED playback URLs bound
/// to this user+device (never permanent public links) with the tenant's
/// watermark config. Text and file content render inline.
class LessonScreen extends StatefulWidget {
  const LessonScreen({super.key, required this.state, required this.lessonId, required this.courseId});

  final AppState state;
  final String lessonId;
  final String courseId;

  @override
  State<LessonScreen> createState() => _LessonScreenState();
}

class _LessonScreenState extends State<LessonScreen> {
  List<LessonContent> _contents = [];
  String _lessonTitle = '';
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
      final res = await widget.state.api.get('/mobile/lessons/${widget.lessonId}');
      if (!res.ok) {
        setState(() {
          _loading = false;
          _error = res.message ?? 'فشل تحميل الدرس';
        });
        return;
      }
      final data = res.data!;
      final lesson = data['lesson'] as Map<String, dynamic>?;
      final contents = (data['contents'] as List? ?? [])
          .map((e) => LessonContent.fromJson(e as Map<String, dynamic>))
          .toList();
      if (!mounted) return;
      setState(() {
        _lessonTitle = lesson?['title']?.toString() ?? '';
        _contents = contents;
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
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 4, 20, 4),
                child: Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.arrow_back, color: AppColors.textPrimary),
                      onPressed: () => Navigator.of(context).maybePop(),
                    ),
                    Expanded(
                      child: Text(
                        _lessonTitle,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: _loading
                    ? const LoadingView()
                    : _error != null
                        ? ErrorView(message: _error!, onRetry: _load)
                        : _contents.isEmpty
                            ? EmptyState(
                                icon: Icons.inbox_outlined,
                                title: L10n.tr(locale, 'لا يوجد محتوى في هذا الدرس', 'No content in this lesson'),
                              )
                            : ListView.separated(
                                padding: const EdgeInsets.all(20),
                                itemCount: _contents.length,
                                separatorBuilder: (_, __) => const SizedBox(height: 16),
                                itemBuilder: (context, i) => _ContentCard(content: _contents[i], state: widget.state),
                              ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ContentCard extends StatelessWidget {
  const _ContentCard({required this.content, required this.state});

  final LessonContent content;
  final AppState state;

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);

    if (content.contentType == 'video' && content.video != null) {
      final video = content.video!;
      final type = video['type']?.toString();
      final streamUrl = video['stream_url']?.toString();
      final watermark = video['watermark'] as Map<String, dynamic>?;

      if (type == 'stream' && streamUrl != null) {
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(content.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.textPrimary)),
            const SizedBox(height: 8),
            _VideoTile(
              title: content.title,
              streamUrl: streamUrl,
              watermarkEnabled: watermark?['enabled'] == true,
              watermarkText: watermark?['text']?.toString(),
            ),
          ],
        );
      }

      if (type == 'provider') {
        final status = video['status']?.toString();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(content.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.textPrimary)),
            const SizedBox(height: 8),
            SoftCard(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(colors: [AppColors.primaryBright, AppColors.primaryDeep]),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(Icons.videocam, color: Colors.white, size: 22),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      status == 'ready'
                          ? L10n.tr(locale, 'الفيديو محمي — التشغيل عبر التطبيق', 'Protected video — playback in app')
                          : L10n.tr(locale, 'الفيديو قيد المعالجة حالياً', 'Video is being processed'),
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                  ),
                  if (status == 'ready')
                    IconButton(
                      icon: const Icon(Icons.play_circle_fill, size: 36, color: AppColors.primary),
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => VideoPlayerScreen(
                            title: content.title,
                            source: VideoSource.provider(
                              streamUrl: video['stream_url']?.toString() ?? '',
                              thumbnailUrl: video['thumbnail_url']?.toString(),
                              watermarkEnabled: (video['watermark'] as Map?)?['enabled'] == true,
                              watermarkText: (video['watermark'] as Map?)?['text']?.toString(),
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        );
      }
    }

    if (content.contentType == 'text' && content.text != null) {
      return SoftCard(
        padding: const EdgeInsets.all(18),
        child: SelectableText(
          content.text!['body']?.toString() ?? '',
          style: const TextStyle(fontSize: 15, height: 1.8, color: AppColors.textPrimary),
        ),
      );
    }

    if (content.contentType == 'file' && content.file != null) {
      final file = content.file!;
      return SoftCard(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: AppColors.primarySoft,
                borderRadius: BorderRadius.circular(14),
              ),
              child: const Icon(Icons.insert_drive_file_outlined, color: AppColors.primary, size: 22),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(file['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  Text(
                    '${((file['size_bytes'] as num?) ?? 0) ~/ 1024} KB',
                    style: TextStyle(color: AppColors.textTertiary, fontSize: 12),
                  ),
                ],
              ),
            ),
            if (file['is_downloadable'] == true && file['url'] != null)
              IconButton(
                icon: const Icon(Icons.download, color: AppColors.primary),
                onPressed: () {
                  Clipboard.setData(ClipboardData(text: file['url'].toString()));
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم نسخ رابط الملف')));
                },
              ),
          ],
        ),
      );
    }

    return const SizedBox.shrink();
  }
}

class _VideoTile extends StatelessWidget {
  const _VideoTile({
    required this.title,
    required this.streamUrl,
    required this.watermarkEnabled,
    this.watermarkText,
  });

  final String title;
  final String streamUrl;
  final bool watermarkEnabled;
  final String? watermarkText;

  @override
  Widget build(BuildContext context) {
    return SoftCard(
      padding: EdgeInsets.zero,
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.md)),
        leading: Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(
            gradient: const LinearGradient(colors: [AppColors.primaryBright, AppColors.primaryDeep]),
            borderRadius: BorderRadius.circular(15),
          ),
          child: const Icon(Icons.play_arrow, color: Colors.white, size: 28),
        ),
        title: const Text('مشاهدة الفيديو', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
        subtitle: Text(
          watermarkEnabled ? 'حماية: علامة مائية' : 'فيديو محمي',
          style: const TextStyle(color: AppColors.textTertiary, fontSize: 12),
        ),
        trailing: const Icon(Icons.chevron_left, color: AppColors.textTertiary),
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => VideoPlayerScreen(
              title: title,
              source: VideoSource.stream(streamUrl: streamUrl, watermarkEnabled: watermarkEnabled, watermarkText: watermarkText),
            ),
          ),
        ),
      ),
    );
  }
}
