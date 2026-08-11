import 'package:flutter_test/flutter_test.dart';

import 'package:saka_app/core/utils/formatters.dart';

void main() {
  test('formats a Tanzanian price compactly', () {
    expect(Fmt.thousands(1234567), '1,234,567');
  });
}
