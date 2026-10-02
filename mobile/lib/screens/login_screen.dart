import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/google_auth.dart';
import '../core/theme.dart';
import '../core/widgets.dart';

/// Student auth inside the validated teacher context — mirrors the web
/// student panel: login (email+password / Google) OR register a new account
/// (name / email / phone / password) / Google. The teacher code defines the
/// context; the backend enforces membership on every path.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.state, required this.onChangeTeacher});

  final AppState state;
  final VoidCallback onChangeTeacher;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  int _tab = 0; // 0 = login, 1 = register
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _confirm = TextEditingController();
  String? _error;
  bool _busy = false;
  bool _obscure = true;
  bool _googleBusy = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _name.dispose();
    _phone.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });

    final String? error;
    if (_tab == 0) {
      error = await widget.state.login(_email.text, _password.text);
    } else {
      if (_password.text != _confirm.text) {
        setState(() {
          _busy = false;
          _error = L10n.tr(
            Localizations.localeOf(context),
            'كلمة المرور غير متطابقة',
            'Passwords do not match',
          );
        });
        return;
      }
      error = await widget.state.register(
        name: _name.text,
        email: _email.text,
        phone: _phone.text,
        password: _password.text,
        confirm: _confirm.text,
      );
    }

    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = error;
    });
  }

  Future<void> _google() async {
    setState(() {
      _googleBusy = true;
      _error = null;
    });
    final helper = GoogleAuthHelper();
    final idToken = await helper.signIn();
    if (idToken.startsWith('تعذر') || idToken.startsWith('تم إلغاء')) {
      if (mounted) setState(() {
        _googleBusy = false;
        _error = idToken;
      });
      return;
    }
    final error = await widget.state.googleLogin(
      idToken,
      name: _name.text.trim().isEmpty ? null : _name.text.trim(),
      phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
    );
    if (!mounted) return;
    setState(() {
      _googleBusy = false;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);
    final theme = Theme.of(context);
    final teacher = widget.state.teacher;
    final academyName = teacher?.site?.name ?? teacher?.name ?? 'منصتي';

    return Scaffold(
      body: AppBackground(
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(child: BrandMark(size: 72, imageUrl: teacher?.site?.logoUrl)),
                    const SizedBox(height: 16),
                    Text(
                      academyName,
                      textAlign: TextAlign.center,
                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
                    ),
                    const SizedBox(height: 18),

                    // ── Tabs: login / register ─────────────────────
                    Container(
                      padding: const EdgeInsets.all(5),
                      decoration: BoxDecoration(
                        color: theme.brightness == Brightness.dark ? AppColors.darkSurfaceElevated : AppColors.surface,
                        borderRadius: BorderRadius.circular(AppRadius.pill),
                        boxShadow: AppShadows.cardSoft,
                      ),
                      child: Row(
                        children: [
                          Expanded(
                            child: _TabButton(
                              label: L10n.tr(locale, 'تسجيل الدخول', 'Login'),
                              selected: _tab == 0,
                              onTap: () => setState(() => _tab = 0),
                            ),
                          ),
                          Expanded(
                            child: _TabButton(
                              label: L10n.tr(locale, 'حساب جديد', 'Register'),
                              selected: _tab == 1,
                              onTap: () => setState(() => _tab = 1),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),

                    // ── Google button (both tabs, like web) ────────
                    OutlinedButton.icon(
                      onPressed: _googleBusy ? null : _google,
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size.fromHeight(52),
                        backgroundColor: theme.brightness == Brightness.dark ? AppColors.darkSurfaceElevated : AppColors.surface,
                        side: BorderSide(color: AppColors.surfaceMuted),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.pill)),
                      ),
                      icon: _googleBusy
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.g_mobiledata, size: 26, color: Color(0xFF4285F4)),
                      label: Text(
                        _tab == 0
                            ? L10n.tr(locale, 'الدخول عبر Google', 'Continue with Google')
                            : L10n.tr(locale, 'التسجيل عبر Google', 'Sign up with Google'),
                        style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                      ),
                    ),

                    const SizedBox(height: 16),
                    Row(
                      children: [
                        const Expanded(child: Divider()),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12),
                          child: Text(
                            _tab == 0
                                ? L10n.tr(locale, 'أو بالبريد الإلكتروني', 'or with email')
                                : L10n.tr(locale, 'أو سجل بالبريد الإلكتروني', 'or sign up with email'),
                            style: TextStyle(color: AppColors.textTertiary, fontSize: 12),
                          ),
                        ),
                        const Expanded(child: Divider()),
                      ],
                    ),
                    const SizedBox(height: 16),

                    // ── Register extra fields ──────────────────────
                    if (_tab == 1) ...[
                      TextField(
                        controller: _name,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          hintText: L10n.tr(locale, 'الاسم الكامل', 'Full name'),
                          prefixIcon: const Icon(Icons.person_outline),
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _phone,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          hintText: L10n.tr(locale, 'رقم الهاتف', 'Phone number'),
                          prefixIcon: const Icon(Icons.phone_outlined),
                        ),
                      ),
                      const SizedBox(height: 12),
                    ],

                    TextField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textDirection: TextDirection.ltr,
                      autocorrect: false,
                      textInputAction: TextInputAction.next,
                      decoration: InputDecoration(
                        hintText: 'email@example.com',
                        prefixIcon: const Icon(Icons.mail_outline),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _password,
                      obscureText: _obscure,
                      decoration: InputDecoration(
                        hintText: L10n.tr(locale, 'كلمة المرور', 'Password'),
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(_obscure ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                    ),
                    if (_tab == 1) ...[
                      const SizedBox(height: 12),
                      TextField(
                        controller: _confirm,
                        obscureText: _obscure,
                        textInputAction: TextInputAction.done,
                        decoration: InputDecoration(
                          hintText: L10n.tr(locale, 'تأكيد كلمة المرور', 'Confirm password'),
                          prefixIcon: const Icon(Icons.lock_outline),
                        ),
                        onSubmitted: (_) => _submit(),
                      ),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(
                        _error!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: AppColors.danger, fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ],
                    const SizedBox(height: 20),
                    PrimaryButton(
                      label: _tab == 0 ? L10n.tr(locale, 'دخول', 'Login') : L10n.tr(locale, 'إنشاء الحساب', 'Create account'),
                      loading: _busy,
                      onPressed: _busy ? null : _submit,
                      icon: _tab == 0 ? Icons.login : Icons.person_add_alt,
                    ),
                    const SizedBox(height: 16),
                    Center(
                      child: TextButton.icon(
                        onPressed: widget.onChangeTeacher,
                        icon: const Icon(Icons.swap_horiz, size: 18),
                        label: Text(
                          L10n.tr(locale, 'تغيير المعلم', 'Change teacher'),
                          style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _TabButton extends StatelessWidget {
  const _TabButton({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOut,
      decoration: BoxDecoration(
        gradient: selected
            ? const LinearGradient(colors: [AppColors.primaryBright, AppColors.primaryDeep])
            : null,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        boxShadow: selected ? AppShadows.button : null,
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(AppRadius.pill),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 13),
            child: Text(
              label,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: selected ? Colors.white : AppColors.textSecondary,
                fontWeight: FontWeight.w800,
                fontSize: 15,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
