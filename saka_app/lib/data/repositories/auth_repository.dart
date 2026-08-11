import '../../app/config/app_config.dart';
import '../../core/network/api_client.dart';
import '../models/json.dart';
import '../models/user.dart';

class AuthRepository {
  AuthRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<AuthSession> login({
    required String email,
    required String password,
  }) {
    return _api.post<AuthSession>(
      '/auth/login',
      body: <String, dynamic>{
        'email': email.trim(),
        'password': password,
        // Lets the user recognise this device in "signed out everywhere".
        'device_name': AppConfig.deviceName,
      },
      parse: (dynamic body) {
        final AuthSession? session = AuthSession.tryParse(body);
        if (session == null) throw StateError('login response not parseable');
        return session;
      },
    );
  }

  Future<AuthSession> register({
    required String firstName,
    required String lastName,
    required String email,
    required String password,
    required String passwordConfirmation,
    String? phone,
  }) {
    return _api.post<AuthSession>(
      '/auth/register',
      body: <String, dynamic>{
        'first_name': firstName.trim(),
        'last_name': lastName.trim(),
        'email': email.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
        if (phone != null && phone.trim().isNotEmpty) 'phone': phone.trim(),
        'device_name': AppConfig.deviceName,
      },
      parse: (dynamic body) {
        final AuthSession? session = AuthSession.tryParse(body);
        if (session == null) {
          throw StateError('register response not parseable');
        }
        return session;
      },
    );
  }

  /// Mints a fresh token and revokes the old one, server-side.
  Future<AuthSession> refresh() {
    return _api.post<AuthSession>(
      '/auth/refresh',
      body: <String, dynamic>{'device_name': AppConfig.deviceName},
      parse: (dynamic body) {
        final AuthSession? session = AuthSession.tryParse(body);
        if (session == null) throw StateError('refresh response not parseable');
        return session;
      },
    );
  }

  Future<AppUser> me() {
    return _api.get<AppUser>(
      '/auth/me',
      // Never deduplicated or cached: this is the call that decides whether the
      // stored token is still good, and a shared in-flight future could hand a
      // stale answer to the session-restore path.
      deduplicate: false,
      parse: (dynamic body) {
        final AppUser? user = AppUser.tryParse(asMap(body)['data']);
        if (user == null) throw StateError('me response not parseable');
        return user;
      },
    );
  }

  Future<void> forgotPassword(String email) {
    return _api.post<void>(
      '/auth/forgot-password',
      body: <String, dynamic>{'email': email.trim()},
      parse: (_) {},
    );
  }

  Future<void> resetPassword({
    required String token,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) {
    return _api.post<void>(
      '/auth/reset-password',
      body: <String, dynamic>{
        'token': token,
        'email': email.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
      parse: (_) {},
    );
  }

  /// Best-effort: the local session is cleared whether or not this succeeds.
  /// A user who taps "sign out" on a dead connection must still be signed out
  /// on the device in front of them.
  Future<void> logout() {
    return _api.delete<void>('/auth/logout', parse: (_) {});
  }

  Future<void> logoutEverywhere() {
    return _api.delete<void>('/auth/logout-all', parse: (_) {});
  }

  Future<void> requestPhoneOtp(String phone) {
    return _api.post<void>(
      '/auth/phone/request-otp',
      body: <String, dynamic>{'phone': phone.trim()},
      parse: (_) {},
    );
  }

  Future<void> verifyPhoneOtp({required String phone, required String code}) {
    return _api.post<void>(
      '/auth/phone/verify-otp',
      body: <String, dynamic>{'phone': phone.trim(), 'code': code.trim()},
      parse: (_) {},
    );
  }
}
