import '../../data/models/listing.dart';

/// Presentation helpers.
///
/// Deliberately hand-rolled rather than `intl`: the app needs exactly two
/// number formats and a relative-time string, and Tanzanian price display has
/// a convention (`1.2M`, `850K`) that no locale package produces.
abstract final class Fmt {
  /// A price, in full: "TZS 180,000 / month".
  ///
  /// Returns "Price on request" when the amount is null. That is a real state
  /// on this marketplace — not a missing value to render as 0, which would read
  /// as free.
  static String price(Price? price, {bool showUnit = true}) {
    if (price == null || price.onRequest) return 'Price on request';
    final String amount = thousands(price.amount!);
    final String base = '${price.currency} $amount';
    if (!showUnit || price.unit == null) return base;
    return '$base / ${unitLabel(price.unit!)}';
  }

  /// A price for a dense card: "TZS 1.2M".
  ///
  /// Abbreviated because the full figure wraps a two-column card on a 360px
  /// phone, and a wrapped price is worse than a rounded one.
  static String priceCompact(Price? price) {
    if (price == null || price.onRequest) return 'On request';
    final int amount = price.amount!;
    final String value = switch (amount) {
      >= 1000000000 => '${_trim(amount / 1000000000)}B',
      >= 1000000 => '${_trim(amount / 1000000)}M',
      >= 1000 => '${_trim(amount / 1000)}K',
      _ => amount.toString(),
    };
    return '${price.currency} $value';
  }

  static String _trim(double value) {
    // One decimal, and only when it says something: "1.2M" is useful, "2.0M"
    // is noise.
    final String text = value.toStringAsFixed(value < 10 ? 1 : 0);
    return text.endsWith('.0') ? text.substring(0, text.length - 2) : text;
  }

  static String unitLabel(String unit) => switch (unit) {
        'monthly' => 'month',
        'daily' => 'day',
        'weekly' => 'week',
        'yearly' => 'year',
        'hourly' => 'hour',
        'per_sqm' => 'm²',
        'per_acre' => 'acre',
        _ => unit.replaceAll('_', ' '),
      };

  /// 1234567 → "1,234,567".
  static String thousands(num value) {
    final String digits = value.round().abs().toString();
    final StringBuffer out = StringBuffer(value < 0 ? '-' : '');
    for (int i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) out.write(',');
      out.write(digits[i]);
    }
    return out.toString();
  }

  /// 12400 → "12.4k". For view counts, where the exact number is not the point.
  static String compactCount(int value) {
    if (value < 1000) return value.toString();
    if (value < 1000000) return '${_trim(value / 1000)}k';
    return '${_trim(value / 1000000)}M';
  }

  /// "2 hours ago", "3 days ago", "12 Aug 2026".
  ///
  /// Switches to an absolute date after a month: "37 days ago" is a worse
  /// answer than the date itself.
  static String relativeTime(DateTime? at) {
    if (at == null) return '';
    final Duration diff = DateTime.now().toUtc().difference(at.toUtc());

    if (diff.isNegative) return 'Soon';
    if (diff.inMinutes < 1) return 'Just now';
    if (diff.inMinutes < 60) {
      return '${diff.inMinutes} min ago';
    }
    if (diff.inHours < 24) {
      return diff.inHours == 1 ? '1 hour ago' : '${diff.inHours} hours ago';
    }
    if (diff.inDays == 1) return 'Yesterday';
    if (diff.inDays < 30) return '${diff.inDays} days ago';
    return date(at);
  }

  static const List<String> _months = <String>[
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static const List<String> _weekdays = <String>[
    'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
  ];

  /// "12 Aug 2026".
  static String date(DateTime at) {
    final DateTime local = at.toLocal();
    return '${local.day} ${_months[local.month - 1]} ${local.year}';
  }

  /// "Tue 12 Aug" — for a booking, where the weekday is what the customer
  /// actually plans around.
  static String dateWithWeekday(DateTime at) {
    final DateTime local = at.toLocal();
    return '${_weekdays[local.weekday - 1]} ${local.day} ${_months[local.month - 1]}';
  }

  /// Parses the API's `yyyy-MM-dd` and formats it, without shifting timezone.
  ///
  /// A booking date is a calendar date in the SPECIALIST's timezone, not an
  /// instant. Running it through `toLocal()` would move a 00:00 UTC date back a
  /// day for anyone west of Greenwich.
  static String apiDate(String yyyyMmDd) {
    final List<String> parts = yyyyMmDd.split('-');
    if (parts.length != 3) return yyyyMmDd;
    final int? year = int.tryParse(parts[0]);
    final int? month = int.tryParse(parts[1]);
    final int? day = int.tryParse(parts[2]);
    if (year == null || month == null || day == null) return yyyyMmDd;
    if (month < 1 || month > 12) return yyyyMmDd;
    final int weekday = DateTime(year, month, day).weekday;
    return '${_weekdays[weekday - 1]} $day ${_months[month - 1]}';
  }

  /// "Mon" for a weekday key from the opening-hours map.
  static String dayLabel(String key) => switch (key) {
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
        _ => key,
      };

  /// The ordered week, for rendering opening hours in a sane order rather than
  /// the map's insertion order (which arrives alphabetical: fri, mon, sat…).
  static const List<String> weekOrder = <String>[
    'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun',
  ];

  /// A card-sized label for an attribute the INDEX resource sent.
  ///
  /// The listing index sends `attributes` as a bare code→value map with no
  /// names or units — only the detail resource carries those. Rendered raw,
  /// a property card reads "1 · 180 · 1", which is meaningless.
  ///
  /// This is PRESENTATION ONLY and mirrors what the web card does with the same
  /// data. It drives nothing: filters are still built entirely from
  /// `GET /categories/{slug}/attributes`, so a vertical an administrator adds
  /// tomorrow filters correctly whether or not its codes appear here — it just
  /// falls through to the humanised code.
  static String attributeLabel(String code, String value) {
    final String unit = switch (code) {
      'beds' || 'bedrooms' => value == '1' ? 'bed' : 'beds',
      'bathrooms' || 'baths' => value == '1' ? 'bath' : 'baths',
      'sqft' || 'area_sqft' => 'sqft',
      'sqm' || 'area_sqm' => 'm²',
      'plot_size' => 'acres',
      'parkings' || 'parking' => value == '1' ? 'parking' : 'parkings',
      'balconies' => value == '1' ? 'balcony' : 'balconies',
      'doors' => value == '1' ? 'doors' : 'doors',
      'mileage' => 'km',
      'year' || 'year_of_manufacture' => '',
      'engine_cc' => 'cc',
      'ram' => 'GB RAM',
      'storage' => 'GB',
      'screen_size' => '"',
      // Anything the backend invents next: the code itself, made readable,
      // rather than a bare number or a blank.
      _ => code.replaceAll('_', ' '),
    };

    return unit.isEmpty ? value : '$value $unit';
  }

  const Fmt._();
}
