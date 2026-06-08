import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:sealy/main.dart';

void main() {
  testWidgets('renders login screen', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});

    await tester.pumpWidget(const SealyApp());
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Sealy'), findsOneWidget);
    expect(find.text('Ingreso personal'), findsOneWidget);
  });
}
