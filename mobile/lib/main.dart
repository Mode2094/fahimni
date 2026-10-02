import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:app_links/app_links.dart';

import 'core/app_state.dart';
import 'core/config.dart';
import 'core/screenshot_guard.dart';
import 'core/theme.dart';
import 'screens/courses_screen.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/profile_screen.dart';
import 'screens/splash_screen.dart';
import 'screens/teacher_code_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Android: FLAG_SECURE — block screenshots & screen recording.
  // iOS: blur app content on capture + event listeners (see ScreenshotGuard).
  ScreenshotGuard.enable();
  runApp(const ShaheadStudentApp());
}

class ShaheadStudentApp extends StatefulWidget {
  const ShaheadStudentApp({super.key});

  @override
  State<ShaheadStudentApp> createState() => _ShaheadStudentAppState();
}

class _ShaheadStudentAppState extends State<ShaheadStudentApp> {
  AppState? _state;
  Locale _locale = const Locale('ar');
  Brightness _brightness = Brightness.light;
  String? _deepLinkCode;

  @override
  void initState() {
    super.initState();
    _watchDeepLinks();
  }

  @override
  void dispose() {
    _state?.removeListener(_onStateChanged);
    super.dispose();
  }

  /// The root must rebuild whenever AppState changes (teacher validated,
  /// login success, logout, change-teacher) so navigation happens
  /// automatically — no manual reload.
  void _onStateChanged() {
    if (mounted) setState(() {});
  }

  void _onReady(AppState state) {
    state.addListener(_onStateChanged);
    setState(() => _state = state);
  }

  void _watchDeepLinks() {
    final appLinks = AppLinks();
    appLinks.uriLinkStream.listen((uri) {
      final code = _extractCode(uri);
      if (code != null) {
        setState(() => _deepLinkCode = code);
      }
    });
  }

  String? _extractCode(Uri uri) {
    if (uri.scheme == AppConfig.deepLinkScheme && uri.host == 'teacher') {
      return uri.pathSegments.isNotEmpty ? uri.pathSegments.first : null;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final state = _state;
    if (state == null) {
      return MaterialApp(
        debugShowCheckedModeBanner: false,
        title: 'Tadreesly',
        theme: AppTheme.build(null, locale: _locale, brightness: _brightness),
        locale: _locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: SplashScreen(onReady: _onReady),
      );
    }

    // ── Gate #1: teacher code (cannot be bypassed) ──────────────
    Widget next;
    if (!state.hasTeacher) {
      next = TeacherCodeScreen(
        state: state,
        prefillCode: _deepLinkCode,
      );
    } else if (!state.hasAuth) {
      // ── Gate #2: login (scoped to the teacher) ────────────────
      next = LoginScreen(
        state: state,
        onChangeTeacher: () async {
          await state.changeTeacher();
          setState(() {});
        },
      );
    } else {
      // ── Main app: home shell ─────────────────────────────────
      next = _MainShell(state: state);
    }

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Tadreesly',
      theme: AppTheme.build(state.teacher, locale: _locale, brightness: _brightness),
      locale: _locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: AnimatedSwitcher(
        duration: const Duration(milliseconds: 250),
        child: KeyedSubtree(
          key: ValueKey(
            state.hasTeacher ? (state.hasAuth ? 'main' : 'login') : 'code',
          ),
          child: next,
        ),
      ),
    );
  }
}

class _MainShell extends StatefulWidget {
  const _MainShell({required this.state});

  final AppState state;

  @override
  State<_MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<_MainShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    widget.state.addListener(_onState);
  }

  void _onState() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    widget.state.removeListener(_onState);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final locale = Localizations.localeOf(context);
    final tabs = [
      HomeScreen(state: widget.state),
      CoursesScreen(state: widget.state),
      ProfileScreen(state: widget.state),
    ];

    return Scaffold(
      body: IndexedStack(index: _index, children: tabs),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.home_outlined),
            selectedIcon: const Icon(Icons.home),
            label: L10n.tr(locale, 'الرئيسية', 'Home'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.menu_book_outlined),
            selectedIcon: const Icon(Icons.menu_book),
            label: L10n.tr(locale, 'الكورسات', 'Courses'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.person_outline),
            selectedIcon: const Icon(Icons.person),
            label: L10n.tr(locale, 'حسابي', 'Profile'),
          ),
        ],
      ),
    );
  }
}
