import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/app_state.dart';
import '../core/config.dart';
import '../core/theme.dart';
import '../core/widgets.dart';

/// THE GATE. The student cannot reach login, home or any other screen before
/// entering a valid teacher code. The code is validated by the backend
/// (platform toggle + teacher active + subscription + plan feature) and the
/// returned payload binds the app to that teacher (Teacher Context).
class TeacherCodeScreen extends StatefulWidget {
  const TeacherCodeScreen({super.key, required this.state, this.prefillCode});

  final AppState state;
  final String? prefillCode;

  @override
  State<TeacherCodeScreen> createState() => _TeacherCodeScreenState();
}

class _TeacherCodeScreenState extends State<TeacherCodeScreen> with SingleTickerProviderStateMixin {
  late final TextEditingController _controller;
  String? _error;
  bool _validating = false;
  late final AnimationController _enter;
  late final Animation<Offset> _slide;
  late final Animation<double> _fade;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.prefillCode ?? '');
    _enter = AnimationController(vsync: this, duration: const Duration(milliseconds: 560))..forward();
    _slide = Tween<Offset>(begin: const Offset(0, 0.06), end: Offset.zero)
        .animate(CurvedAnimation(parent: _enter, curve: Curves.easeOutCubic));
    _fade = CurvedAnimation(parent: _enter, curve: Curves.easeOut);
    if ((widget.prefillCode ?? '').isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _submit());
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    _enter.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final code = _controller.text.trim();
    if (code.isEmpty) {
      setState(() => _error = L10n.tr(Localizations.localeOf(context), 'أدخل كود المعلم أولاً', 'Enter the teacher code first'));
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() {
      _validating = true;
      _error = null;
    });
    final error = await widget.state.validateTeacherCode(code);
    if (!mounted) return;
    setState(() {
      _validating = false;
      _error = error;
    });
    if (error != null && widget.state.lastErrorWasConnection) {
      // Directly open the connection settings — the fastest path to fix a
      // real device pointing at the wrong host.
      final locale = Localizations.localeOf(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(L10n.tr(locale, 'تعذر الوصول للخادم — اضبط رابط الاتصال', 'Cannot reach the server — set the connection URL')),
          duration: const Duration(seconds: 3),
        ),
      );
      await Future<void>.delayed(const Duration(milliseconds: 400));
      if (mounted) await _openConnectionDialog();
    }
  }

  /// Connection settings: lets a real device point the app at the host's
  /// LAN IP (or a production domain) without rebuilding. Persisted locally.
  Future<void> _openConnectionDialog() async {
    final locale = Localizations.localeOf(context);
    final controller = TextEditingController(text: AppConfig.apiBaseUrl);

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(L10n.tr(locale, 'إعدادات الاتصال', 'Connection settings')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              L10n.tr(locale, 'رابط الخادم (API)', 'Server URL (API)'),
              style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.textPrimary),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: controller,
              textDirection: TextDirection.ltr,
              autocorrect: false,
              decoration: const InputDecoration(
                hintText: 'https://lms.example.com/api/v1',
              ),
            ),
            const SizedBox(height: 8),
            Text(
              L10n.tr(locale, 'مثال لجهاز حقيقي على نفس الشبكة: http://192.168.1.5:8080/api/v1 — وامسحه للرجوع للافتراضي.', 'For a real device on the same network use e.g. http://192.168.1.5:8080/api/v1 — clear it to reset to default.'),
              style: const TextStyle(color: AppColors.textTertiary, fontSize: 12, height: 1.5),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(L10n.tr(locale, 'إلغاء', 'Cancel'), style: const TextStyle(color: AppColors.textSecondary)),
          ),
          TextButton(
            onPressed: () async {
              AppConfig.setOverride(controller.text);
              await widget.state.store.saveApiBaseUrl(controller.text.trim());
              if (context.mounted) Navigator.pop(context);
            },
            child: const Text('حفظ', style: TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);

    return Scaffold(
      body: AppBackground(
        child: SafeArea(
          child: Stack(
            children: [
              Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(28),
                  child: FadeTransition(
                    opacity: _fade,
                    child: SlideTransition(
                      position: _slide,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 400),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Center(child: BrandMark(size: 96)),
                            const SizedBox(height: 24),
                            const Text(
                              'Tadreesly',
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              L10n.tr(locale, 'أدخل كود المعلم للمتابعة', 'Enter the teacher code to continue'),
                              textAlign: TextAlign.center,
                              style: const TextStyle(color: AppColors.textSecondary, fontSize: 14),
                            ),
                            const SizedBox(height: 28),
                            SoftCard(
                              padding: const EdgeInsets.all(20),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  TextField(
                                    controller: _controller,
                                    textAlign: TextAlign.center,
                                    textDirection: TextDirection.ltr,
                                    style: const TextStyle(fontWeight: FontWeight.w800, letterSpacing: 3, fontSize: 20, color: AppColors.textPrimary),
                                    textCapitalization: TextCapitalization.characters,
                                    autocorrect: false,
                                    enableSuggestions: false,
                                    inputFormatters: [
                                      FilteringTextInputFormatter.allow(RegExp('[A-Za-z0-9-]')),
                                      LengthLimitingTextInputFormatter(12),
                                    ],
                                    decoration: InputDecoration(
                                      hintText: 'SHA-XXXXXX',
                                      hintStyle: TextStyle(color: AppColors.textTertiary, letterSpacing: 1.5, fontSize: 16),
                                      errorText: _error,
                                    ),
                                    onSubmitted: (_) => _submit(),
                                  ),
                                  const SizedBox(height: 16),
                                  PrimaryButton(
                                    label: L10n.tr(locale, 'متابعة', 'Continue'),
                                    loading: _validating,
                                    onPressed: _validating ? null : _submit,
                                    icon: Icons.arrow_forward,
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 20),
                            Text(
                              L10n.tr(locale, 'تطبيق رسمي للطلاب فقط — لا يمكنك تجاوز هذه الشاشة', 'Official student app — you cannot skip this step'),
                              textAlign: TextAlign.center,
                              style: TextStyle(color: AppColors.textTertiary, fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              Positioned(
                top: 8,
                left: 8,
                child: IconButton(
                  onPressed: _openConnectionDialog,
                  tooltip: L10n.tr(locale, 'إعدادات الاتصال', 'Connection settings'),
                  icon: const Icon(Icons.settings_outlined, color: AppColors.textSecondary),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
