/// Build-time configuration.
///
/// Everything here comes from `--dart-define`, never from a checked-in secret
/// and never from a runtime lookup. `String.fromEnvironment` is a const
/// expression, so an unused branch is tree-shaken out of the release binary
/// entirely — the development host strings do not ship.
abstract final class AppConfig {
  /// The API ORIGIN — no path. `/api/v1` is appended by [apiBaseUrl], matching
  /// how the three web clients do it (`lib/api/http.ts`). A value ending in
  /// `/api/v1` would produce `/api/v1/api/v1/...` and 404 everything.
  ///
  ///   flutter run --dart-define=SAKA_API_ORIGIN=http://10.0.2.2:8000
  ///
  /// The default is production. That ordering is on purpose: a release built
  /// without the define points at production rather than at a laptop that is
  /// not there, which is the failure that ships.
  static const String apiOrigin = String.fromEnvironment(
    'SAKA_API_ORIGIN',
    defaultValue: 'https://api.saka.africa',
  );

  static String get apiBaseUrl => '$apiOrigin/api/v1';

  /// The public web origin. Used to build share links, so a listing shared from
  /// the app opens the real page for someone without the app installed.
  static const String webOrigin = String.fromEnvironment(
    'SAKA_WEB_ORIGIN',
    defaultValue: 'https://saka.africa',
  );

  /// Verbose request logging. Off unless explicitly asked for, and asserted
  /// against release below so it can never be switched on in a store build.
  static const bool enableNetworkLogs = bool.fromEnvironment(
    'SAKA_NETWORK_LOGS',
  );

  static const String appName = 'SAKA';

  /// Shown in About. The web footer carries the same credit.
  static const String developerName = 'TechOn Software LLC';
  static const String developerUrl = 'https://www.techon.co.tz';

  /// Sent as `device_name` when minting a token, so a user can tell their
  /// sessions apart in "signed out everywhere".
  static const String deviceName = 'saka-mobile';

  /// True when the origin is plainly a developer machine. Used by [assertSafe]
  /// and by the debug banner — never to change behaviour silently.
  static bool get isLocalOrigin =>
      apiOrigin.contains('localhost') ||
      apiOrigin.contains('127.0.0.1') ||
      apiOrigin.contains('10.0.2.2') ||
      apiOrigin.startsWith('http://');

  /// Fails the build's first frame in release if it was compiled against a
  /// development origin or with logging on. A release APK quietly pointing at
  /// `10.0.2.2` is indistinguishable from a broken server to everyone except
  /// the person who built it.
  static void assertSafe() {
    const bool isRelease = bool.fromEnvironment('dart.vm.product');
    if (!isRelease) return;

    if (isLocalOrigin) {
      throw StateError(
        'Release build points at a development API origin ($apiOrigin). '
        'Pass --dart-define=SAKA_API_ORIGIN=https://api.saka.africa',
      );
    }
    if (enableNetworkLogs) {
      throw StateError('Release build has SAKA_NETWORK_LOGS enabled.');
    }
  }

  const AppConfig._();
}
