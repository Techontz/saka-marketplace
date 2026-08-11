import 'dart:async';

import 'package:get/get.dart';

import '../../core/errors/api_exception.dart';
import '../../core/network/api_client.dart';
import '../../core/storage/cache_store.dart';
import '../../core/storage/secure_store.dart';
import '../../data/models/user.dart';
import '../../data/repositories/auth_repository.dart';

/// Who is signed in, for the life of the process.
///
/// The only permanent controller in the app. Everything else is created by a
/// binding when its screen opens and disposed when it closes; the session is
/// the one piece of state that genuinely outlives every screen.
class AuthController extends GetxController {
  AuthController({
    required AuthRepository repository,
    required SecureStore secureStore,
    required CacheStore cache,
    required ApiClient api,
  })  : _repository = repository,
        _secure = secureStore,
        _cache = cache,
        _api = api;

  final AuthRepository _repository;
  final SecureStore _secure;
  final CacheStore _cache;
  final ApiClient _api;

  final Rxn<AppUser> _user = Rxn<AppUser>();
  final RxBool _isRestoring = true.obs;
  final RxBool _isBusy = false.obs;

  StreamSubscription<void>? _expirySubscription;

  AppUser? get user => _user.value;
  bool get isSignedIn => _user.value != null;

  /// The observable itself, for controllers that must REACT to sign-in and
  /// sign-out rather than read the current value.
  ///
  /// Exposed deliberately: `ever(controller.isSignedIn.obs, …)` wraps a plain
  /// bool in a brand-new Rx that nothing ever updates, so the worker silently
  /// never fires. Handing out the real observable makes that mistake
  /// impossible.
  Rxn<AppUser> get userRx => _user;

  /// True until the stored token has been checked. The shell shows the app
  /// immediately regardless — browsing is open to guests, so blocking the whole
  /// UI on a session check would make every cold start slower for no reason.
  bool get isRestoring => _isRestoring.value;

  bool get isBusy => _isBusy.value;
  bool get isVendor => _user.value?.isVendor ?? false;
  bool get canPublish => _user.value?.canPublishListings ?? false;

  @override
  void onInit() {
    super.onInit();

    // A 401 anywhere in the app funnels here, so the session is torn down in
    // exactly one place no matter which repository hit it.
    _expirySubscription = _api.onSessionExpired.listen((_) => _forceSignOut());

    unawaited(restore());
  }

  @override
  void onClose() {
    _expirySubscription?.cancel();
    super.onClose();
  }

  /// Re-establish the session from the stored token, at launch.
  ///
  /// Never throws. A launch-time failure means "browse as a guest", not an
  /// error dialog over the home screen — a user opening the app to look at
  /// listings does not need to be told their token expired.
  Future<void> restore() async {
    _isRestoring.value = true;
    try {
      await _secure.load();
      if (!_secure.hasSession) return;

      // Refresh proactively when the token is within five minutes of expiry,
      // rather than letting a request 401 mid-journey.
      if (_secure.isNearExpiry) {
        try {
          final AuthSession session = await _repository.refresh();
          await _secure.save(
            token: session.token,
            expiresAt: session.expiresAt,
          );
          _user.value = session.user;
          return;
        } on Object {
          // Fall through to `me()`: the token may still be valid even if the
          // refresh endpoint was unreachable.
        }
      }

      _user.value = await _repository.me();
    } on ApiException catch (error) {
      // Only an auth failure clears the token. A timeout at launch on a bad
      // connection must not sign the user out of their own phone.
      if (error.isAuthProblem) await _clearSession();
    } on Object {
      // Ignored, deliberately: launch continues as a guest.
    } finally {
      _isRestoring.value = false;
    }
  }

  Future<void> signIn({
    required String email,
    required String password,
  }) async {
    _isBusy.value = true;
    try {
      final AuthSession session = await _repository.login(
        email: email,
        password: password,
      );
      await _secure.save(token: session.token, expiresAt: session.expiresAt);
      _user.value = session.user;
    } finally {
      _isBusy.value = false;
    }
  }

  Future<void> register({
    required String firstName,
    required String lastName,
    required String email,
    required String password,
    required String passwordConfirmation,
    String? phone,
  }) async {
    _isBusy.value = true;
    try {
      final AuthSession session = await _repository.register(
        firstName: firstName,
        lastName: lastName,
        email: email,
        password: password,
        passwordConfirmation: passwordConfirmation,
        phone: phone,
      );
      await _secure.save(token: session.token, expiresAt: session.expiresAt);
      _user.value = session.user;
    } finally {
      _isBusy.value = false;
    }
  }

  Future<void> signOut() async {
    _isBusy.value = true;
    try {
      // Best effort. The local session is cleared either way in `finally` —
      // a user who taps "sign out" on a dead connection must still end up
      // signed out on the device in front of them.
      await _repository.logout();
    } on Object {
      // Ignored.
    } finally {
      await _clearSession();
      _isBusy.value = false;
    }
  }

  Future<void> signOutEverywhere() async {
    _isBusy.value = true;
    try {
      await _repository.logoutEverywhere();
    } on Object {
      // Ignored.
    } finally {
      await _clearSession();
      _isBusy.value = false;
    }
  }

  Future<void> forgotPassword(String email) => _repository.forgotPassword(email);

  /// Re-read the profile — after editing it, or after a verification decision
  /// that may have changed `can_publish_listings`.
  Future<void> refreshUser() async {
    if (!isSignedIn) return;
    try {
      _user.value = await _repository.me();
    } on Object {
      // A failed refresh leaves the previous user in place rather than blanking
      // the account screen.
    }
  }

  void updateUser(AppUser next) => _user.value = next;

  Future<void> _forceSignOut() async {
    if (!isSignedIn) return;
    await _clearSession();
  }

  Future<void> _clearSession() async {
    _user.value = null;
    await _secure.clear();
    // Cached API payloads are dropped so the next account never briefly sees
    // the previous one's content. Preferences — location, layout — survive.
    await _cache.clearContent();
  }
}
