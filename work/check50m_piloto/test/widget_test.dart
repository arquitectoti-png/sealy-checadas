import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:promosoluciones/main.dart';

void main() {
  testWidgets('renders login screen', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues({});

    await tester.pumpWidget(const PromosolucionesApp());
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Promosoluciones'), findsOneWidget);
    expect(find.text('Ingreso personal'), findsOneWidget);
  });

  test('parses isolated App Review session flags', () {
    final user = AppUser.fromJson({
      'id': -1,
      'full_name': 'Apple App Review',
      'role': 'staff',
      'is_app_review': true,
    });
    final bootstrap = MobileBootstrap.fromJson({
      'active_stores': [
        {
          'id': -1,
          'chain': 'Promosoluciones',
          'name': 'Ubicacion virtual de revision',
          'latitude': 0,
          'longitude': 0,
          'allowed_radius_meters': 50,
          'timezone': 'America/Mexico_City',
        }
      ],
      'today_checks': [],
      'requires_location_verification': true,
      'demo_mode': true,
    });

    expect(user.isAppReview, isTrue);
    expect(bootstrap.demoMode, isTrue);
    expect(bootstrap.activeStores.single.allowedRadiusMeters, 50);
  });
}
