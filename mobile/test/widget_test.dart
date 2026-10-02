import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:shahead_student_app/core/app_state.dart';
import 'package:shahead_student_app/screens/teacher_code_screen.dart';

void main() {
  testWidgets('TeacherCodeScreen is a hard gate — empty submit shows an error', (tester) async {
    final state = AppState();
    // Bootstrap reads from secure storage which is a plugin — in tests the
    // platform channel is absent, so we bypass it by marking bootstrapped.
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: TeacherCodeScreen(state: state),
      ),
    );

    // The gate is shown and cannot be skipped.
    expect(find.text('أدخل كود المعلم للمتابعة'), findsOneWidget);

    // Submitting an empty code must not navigate anywhere — it shows an error.
    await tester.tap(find.text('متابعة'));
    await tester.pump();
    expect(find.text('أدخل كود المعلم أولاً'), findsOneWidget);
    expect(find.text('أدخل كود المعلم للمتابعة'), findsOneWidget);
  });
}
