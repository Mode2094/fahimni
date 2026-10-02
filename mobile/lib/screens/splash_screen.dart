import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/theme.dart';
import '../core/widgets.dart';

/// Branded splash — boots the app state (restore teacher context + token
/// from secure storage), then routes to the correct gate.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key, required this.onReady});

  final ValueChanged<AppState> onReady;

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 800));
    _fade = CurvedAnimation(parent: _ctrl, curve: Curves.easeOut);
    _slide = Tween<Offset>(begin: const Offset(0, 0.08), end: Offset.zero)
        .animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeOutCubic));
    _ctrl.forward();
    _boot();
  }

  Future<void> _boot() async {
    final state = AppState();
    await state.bootstrap();
    if (!mounted) return;
    widget.onReady(state);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);
    return Scaffold(
      body: AppBackground(
        child: Center(
          child: FadeTransition(
            opacity: _fade,
            child: SlideTransition(
              position: _slide,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  BrandMark(size: 108),
                  const SizedBox(height: 26),
                  const Text(
                    'Tadreesly',
                    style: TextStyle(fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: 0.5, color: AppColors.textPrimary),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    L10n.tr(locale, 'منصة التعلم الذكية', 'Smart Learning Platform'),
                    style: TextStyle(color: AppColors.textSecondary, fontSize: 14, fontWeight: FontWeight.w500),
                  ),
                  const SizedBox(height: 36),
                  const SizedBox(
                    width: 26,
                    height: 26,
                    child: CircularProgressIndicator(strokeWidth: 2.6, color: AppColors.primary),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
