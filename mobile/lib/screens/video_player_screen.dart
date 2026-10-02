import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../core/screenshot_guard.dart';

/// Source for the protected player.
class VideoSource {
  const VideoSource._(this.streamUrl, this.thumbnailUrl, this.watermarkEnabled, this.watermarkText);

  final String streamUrl;
  final String? thumbnailUrl;
  final bool watermarkEnabled;
  final String? watermarkText;

  /// Signed, short-lived, user-bound stream (uploaded videos).
  factory VideoSource.stream({required String streamUrl, bool watermarkEnabled = false, String? watermarkText}) =>
      VideoSource._(streamUrl, null, watermarkEnabled, watermarkText);

  /// Provider (Bunny Stream) signed HLS playback.
  factory VideoSource.provider({required String streamUrl, String? thumbnailUrl, bool watermarkEnabled = false, String? watermarkText}) =>
      VideoSource._(streamUrl, thumbnailUrl, watermarkEnabled, watermarkText);
}

/// Protected video player.
///
/// - Plays the short-lived signed URL (expires server-side; cannot be
///   replayed by another user/device).
/// - Overlays the tenant's moving watermark (student name + optional id) as
///   configured by the platform — light, non-intrusive.
/// - Screenshot protection is active app-wide via FLAG_SECURE / iOS blur.
class VideoPlayerScreen extends StatefulWidget {
  const VideoPlayerScreen({super.key, required this.title, required this.source});

  final String title;
  final VideoSource source;

  @override
  State<VideoPlayerScreen> createState() => _VideoPlayerScreenState();
}

class _VideoPlayerScreenState extends State<VideoPlayerScreen>
    with SingleTickerProviderStateMixin {
  VideoPlayerController? _controller;
  bool _initialized = false;
  String? _error;

  late final AnimationController _watermarkCtrl;
  late final Animation<Offset> _watermarkPos;

  @override
  void initState() {
    super.initState();
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
      controller.play();
      if (!mounted) return;
      setState(() {
        _controller = controller;
        _initialized = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = 'تعذر تشغيل الفيديو — قد تكون الجلسة منتهية. ارجع وافتح الدرس مجدداً.');
      }
    }
  }

  @override
  void dispose() {
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
}
