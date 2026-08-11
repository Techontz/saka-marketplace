import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Credentials only.
///
/// Keychain on iOS, EncryptedSharedPreferences on Android. Nothing else in the
/// app may write here and nothing here is ever logged: the session token is a
/// bearer credential that can read a user's inquiries, post reviews in their
/// name and close their account.
///
/// Explicitly NOT stored: passwords (never held past the login call) and any
/// identity-document field. NIDA numbers are never sent to the client by the
/// API in the first place, and this app must not become the first place a copy
/// exists on a phone.
class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                // Not `..._always`: the token should be unreadable while the
                // device is locked, and should not migrate to a new phone in a
                // backup — a stolen backup should not be a live session.
                accessibility: KeychainAccessibility.first_unlock_this_device,
              ),
            );

  final FlutterSecureStorage _storage;

  static const String _kToken = 'saka.session.token';
  static const String _kExpiresAt = 'saka.session.expires_at';

  /// Cached in memory after the first read.
  ///
  /// The auth interceptor needs the token on every single request; going
  /// through a platform channel each time would put a hop on the hot path of
  /// every API call. The disk copy stays authoritative — this is a read cache,
  /// written on the same code paths that write disk.
  String? _cachedToken;
  DateTime? _cachedExpiry;
  bool _loaded = false;

  Future<void> load() async {
    if (_loaded) return;
    _cachedToken = await _storage.read(key: _kToken);
    final String? raw = await _storage.read(key: _kExpiresAt);
    _cachedExpiry = raw == null ? null : DateTime.tryParse(raw);
    _loaded = true;
  }

  String? get token => _cachedToken;

  DateTime? get expiresAt => _cachedExpiry;

  bool get hasSession => _cachedToken != null && _cachedToken!.isNotEmpty;

  /// True once the stored token is close enough to expiry to be worth
  /// refreshing proactively.
  ///
  /// The five-minute skew matters: a token that expires mid-request produces a
  /// 401 the user experiences as being thrown out of the app, and device clocks
  /// in the field are not reliably correct.
  bool get isNearExpiry {
    final DateTime? at = _cachedExpiry;
    if (at == null) return false;
    return DateTime.now().toUtc().isAfter(
          at.toUtc().subtract(const Duration(minutes: 5)),
        );
  }

  Future<void> save({required String token, DateTime? expiresAt}) async {
    _cachedToken = token;
    _cachedExpiry = expiresAt;
    _loaded = true;
    await _storage.write(key: _kToken, value: token);
    if (expiresAt != null) {
      await _storage.write(
        key: _kExpiresAt,
        value: expiresAt.toUtc().toIso8601String(),
      );
    } else {
      await _storage.delete(key: _kExpiresAt);
    }
  }

  Future<void> clear() async {
    _cachedToken = null;
    _cachedExpiry = null;
    _loaded = true;
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kExpiresAt);
  }
}
