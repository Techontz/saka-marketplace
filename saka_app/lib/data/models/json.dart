/// Defensive readers for API payloads.
///
/// Every model in this app parses through these rather than casting directly.
/// The reason is concrete and was found in the live API, not imagined: a
/// seller's `rating_avg` arrives as the STRING `"0.00"` from `auth/me` and as
/// the NUMBER `0` from the listing resource, because one path is a raw Eloquent
/// decimal cast and the other has been through a resource. A direct
/// `as double` crashes on one of those two responses.
///
/// A marketplace app must not white-screen because a decimal came back quoted.
library;

/// A JSON object, or an empty map if the value is not one.
Map<String, dynamic> asMap(dynamic value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return value.cast<String, dynamic>();
  return const <String, dynamic>{};
}

/// A JSON array of objects.
///
/// Note the `[]`-vs-`{}` problem this also solves: PHP serialises an empty
/// associative array as `[]`, so `media.variants` arrives as a LIST when there
/// are no variants and as an OBJECT when there are. Callers use [asMap] for it
/// and get an empty map either way.
List<Map<String, dynamic>> asMapList(dynamic value) {
  if (value is! List) return const <Map<String, dynamic>>[];
  return <Map<String, dynamic>>[
    for (final dynamic item in value)
      if (item is Map) item.cast<String, dynamic>(),
  ];
}

List<String> asStringList(dynamic value) {
  if (value is! List) return const <String>[];
  return <String>[
    for (final dynamic item in value)
      if (item != null) item.toString(),
  ];
}

String? asString(dynamic value) {
  if (value == null) return null;
  if (value is String) return value.isEmpty ? null : value;
  return value.toString();
}

String asStringOr(dynamic value, String fallback) => asString(value) ?? fallback;

int? asInt(dynamic value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is double) return value.round();
  if (value is String) return int.tryParse(value) ?? double.tryParse(value)?.round();
  return null;
}

int asIntOr(dynamic value, int fallback) => asInt(value) ?? fallback;

double? asDouble(dynamic value) {
  if (value == null) return null;
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}

double asDoubleOr(dynamic value, double fallback) => asDouble(value) ?? fallback;

/// Accepts `true`, `1` and `"1"` — Laravel casts booleans differently depending
/// on whether the column went through an Eloquent cast or a raw query.
bool asBool(dynamic value, {bool fallback = false}) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  if (value is String) {
    final String v = value.toLowerCase();
    if (v == 'true' || v == '1') return true;
    if (v == 'false' || v == '0') return false;
  }
  return fallback;
}

/// ISO-8601 from the API, always UTC-normalised.
///
/// The API emits `+00:00` offsets; parsing without normalising leaves a local
/// DateTime whose comparisons against `DateTime.now().toUtc()` are silently
/// wrong by the device's offset — three hours, in Tanzania.
DateTime? asDate(dynamic value) {
  final String? raw = asString(value);
  if (raw == null) return null;
  return DateTime.tryParse(raw)?.toUtc();
}
