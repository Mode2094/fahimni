import 'dart:async';

import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../core/app_state.dart';
import '../core/screenshot_guard.dart';

/// Source for the protected player.
class VideoSource {
  const VideoSource._(
    this.streamUrl,
    this.thumbnailUrl,
    this.watermarkEnabled,
    this.watermarkText,
    this.videoId,
    this.resumeSeconds,
  );

  final String streamUrl;
  final String? thumbnailUrl;
  final bool watermarkEnabled;
  final String? watermarkText;

  /// معرّف الفيديو على الخادم — يُستخدم لحفظ/استرجاع موضع المشاهدة.
  final String? videoId;

  /// آخر موضع مشاهدة محفوظ للطالب (ثوانٍ) — 0 يعني البدء من البداية.
  final int resumeSeconds;

  /// Signed, short-lived, user-bound stream (uploaded videos).
  factory VideoSource.stream({
    required String streamUrl,
    bool watermarkEnabled = false,
    String? watermarkText,
    String? videoId,
    int resumeSeconds = 0,
  }) =>
      VideoSource._(streamUrl, null, watermarkEnabled, watermarkText, videoId, resumeSeconds);

  /// Provider (Bunny Stream) signed HLS playback.
  factory VideoSource.provider({
    required String streamUrl,
    String? thumbnailUrl,
    bool watermarkEnabled = false,
    String? watermarkText,
    String? videoId,
    int resumeSeconds = 0,
  }) =>
      VideoSource._(streamUrl, thumbnailUrl, watermarkEnabled, watermarkText, videoId, resumeSeconds);
}

/// Protected video player.
///
/// - Plays the short-lived signed URL (expires server-side; cannot be
///   replayed by another user/device).
/// - Overlays the tenant's moving watermark (student name + optional id) as
///   configured by the platform — light, non-intrusive.
/// - Screenshot protection is active app-wide via FLAG_SECURE / iOS blur.
/// - Saves watch position (light periodic ping + on pause/exit) so a student
///   can resume from where they stopped, even on another device.
class VideoPlayerScreen extends StatefulWidget {
  const VideoPlayerScreen({super.key, required this.title, required this.source, this.state});

  final String title;
  final VideoSource source;

  /// يُستخدم لحفظ موضع المشاهدة عبر API (اختياري — بدونه يعمل المشغّل بلا حفظ).
  final AppState? state;

  @override
  State<VideoPlayerScreen> createState() => _VideoPlayerScreenState();
}

class _VideoPlayerScreenState extends State<VideoPlayerScreen>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  VideoPlayerController? _controller;
  bool _initialized = false;
  String? _error;

  late final AnimationController _watermarkCtrl;
  late final Animation<Offset> _watermarkPos;

  Timer? _saveTimer;
  int _lastSaved = 0;
  int? _resumedFrom;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _watermarkCtrl = AnimationController(vsync: this, duration: const Duration(seconds: 14))..repeat(reverse: true);
    _watermarkPos = Tween<Offset>(
      begin: const Offset(0.08, 0.25),
      end: const Offset(0.6, 0.75),
    ).animate(CurvedAnimation(parent: _watermarkCtrl, curve: Curves.easeInOut));

    // iOS: pause protected video the moment the user screenshots/records.
    // Android is already enforced by FLAG_SECURE.
    ScreenshotGuard.watch(
      () => _controller?.pause(),
      (recording) {
        if (recording) _controller?.pause();
      },
    );
    _init();
  }

  Future<void> _init() async {
    try {
      final controller = VideoPlayerController.networkUrl(Uri.parse(widget.source.streamUrl));
      await controller.initialize();
      controller.setLooping(false);

      // الاستكمال: نقفز إلى آخر موضع محفوظ (إن وُجد) ثم نبدأ التشغيل.
      final resume = widget.source.resumeSeconds;
      if (resume > 0 && resume < controller.value.duration.inSeconds - 3) {
        await controller.seekTo(Duration(seconds: resume));
        _resumedFrom = resume;
      }

      controller.addListener(() {
        // حفظ عند الإيقاف الطبيعي (لا عند نهاية التمرير).
        if (!controller.value.isPlaying && controller.value.isInitialized) {
          _savePosition();
        }
      });

      controller.play();
      if (!mounted) return;
      setState(() {
        _controller = controller;
        _initialized = true;
      });

      _saveTimer = Timer.periodic(const Duration(seconds: 10), (_) {
        final c = _controller;
        if (c != null && c.value.isPlaying && !c.value.isCompleted) _savePosition();
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = 'تعذر تشغيل الفيديو — قد تكون الجلسة منتهية. ارجع وافتح الدرس مجدداً.');
      }
    }
  }

  /// حفظ موضع المشاهدة — فشل الحفظ لا يجب أن يعطّل التشغيل أبداً.
  Future<void> _savePosition({bool force = false}) async {
    final c = _controller;
    final s = widget.state;
    final videoId = widget.source.videoId;
    if (c == null || s == null || videoId == null || !c.value.isInitialized) return;

    final pos = c.value.position.inSeconds;
    final dur = c.value.duration.inSeconds;
    if (pos <= 0) return;
    if (!force && (pos - _lastSaved).abs() < 2) return;
    _lastSaved = pos;

    try {
      await s.api.post('/mobile/video-position', body: {
        'video_id': videoId,
        'position_seconds': pos,
        'duration_seconds': dur,
      });
    } catch (_) {
      // لا نُعطّل التشغيل عند فشل الحفظ.
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused || state == AppLifecycleState.inactive) {
      _savePosition(force: true);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _saveTimer?.cancel();
    _savePosition(force: true);
    _watermarkCtrl.dispose();
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(widget.title, style: const TextStyle(fontSize: 16), overflow: TextOverflow.ellipsis),
      ),
      body: Center(
        child: _error != null
            ? Padding(
                padding: const EdgeInsets.all(24),
                child: Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70)),
              )
            : !_initialized
                ? const CircularProgressIndicator(color: Colors.white)
                : _buildPlayer(),
      ),
    );
  }

  Widget _buildPlayer() {
    final c = _controller!;
    final size = c.value.size;
    final aspect = size.width > 0 && size.height > 0 ? size.width / size.height : 16 / 9;

    return GestureDetector(
      onTap: () {
        setState(() {
          c.value.isPlaying ? c.pause() : c.play();
        });
      },
      child: AspectRatio(
        aspectRatio: aspect,
        child: Stack(
          fit: StackFit.expand,
          children: [
            VideoPlayer(c),
            // Play/pause indicator
            Center(
              child: c.value.isPlaying
                  ? const SizedBox.shrink()
                  : const Icon(Icons.play_circle_fill, size: 72, color: Colors.white70),
            ),
            // Moving watermark (light, non-intrusive, per teacher config)
            if (widget.source.watermarkEnabled)
              AnimatedBuilder(
                animation: _watermarkCtrl,
                builder: (context, _) {
                  final pos = _watermarkPos.value;
                  return Align(
                    alignment: Alignment(pos.dx * 2 - 1, pos.dy * 2 - 1),
                    child: Opacity(
                      opacity: 0.35,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        color: Colors.black54,
                        child: Text(
                          widget.source.watermarkText ?? '',
                          style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ),
                  );
                },
              ),
            // شريط الاستكمال
            if (_resumedFrom != null)
              Positioned(
                top: 8,
                left: 0,
                right: 0,
                child: Center(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.75), borderRadius: BorderRadius.circular(20)),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          'تم الاستكمال من ${_fmt(_resumedFrom!)}',
                          style: const TextStyle(color: Colors.white, fontSize: 11),
                        ),
                        const SizedBox(width: 8),
                        GestureDetector(
                          onTap: () async {
                            await c.seekTo(Duration.zero);
                            c.play();
                            _lastSaved = 0;
                            setState(() => _resumedFrom = null);
                          },
                          child: const Text(
                            'البدء من البداية',
                            style: TextStyle(color: Colors.tealAccent, fontSize: 11, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            // Progress bar
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: VideoProgressIndicator(c, allowScrubbing: true, colors: const VideoProgressColors(playedColor: Colors.tealAccent, backgroundColor: Colors.white24, bufferedColor: Colors.white38)),
            ),
          ],
        ),
      ),
    );
  }

  static String _fmt(int seconds) {
    final s = seconds < 0 ? 0 : seconds;
    final h = s ~/ 3600;
    final m = (s % 3600) ~/ 60;
    final r = s % 60;
    String two(int n) => n.toString().padLeft(2, '0');
    return h > 0 ? '$h:${two(m)}:${two(r)}' : '$m:${two(r)}';
  }
}
