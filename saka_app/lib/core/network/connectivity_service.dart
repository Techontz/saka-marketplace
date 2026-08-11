import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

/// "Is there a network?" — and nothing more.
///
/// Deliberately not "is the API reachable". connectivity_plus reports the radio
/// state, which is enough to decide whether a retry is worth attempting and
/// whether to show the offline banner. Treating it as proof of reachability
/// would be wrong: a phone on a captive-portal wifi reports connected.
class ConnectivityService {
  ConnectivityService({Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity() {
    _subscription = _connectivity.onConnectivityChanged.listen(_handle);
  }

  final Connectivity _connectivity;
  late final StreamSubscription<List<ConnectivityResult>> _subscription;

  final StreamController<bool> _online = StreamController<bool>.broadcast();

  bool _isOnline = true;

  /// Optimistic until proven otherwise.
  ///
  /// The first connectivity report arrives asynchronously; starting at `false`
  /// would flash an offline banner on every cold start on a perfectly good
  /// connection, which is worse than being briefly wrong in the other
  /// direction.
  bool get isOnlineNow => _isOnline;

  Stream<bool> get onStatusChange => _online.stream;

  void _handle(List<ConnectivityResult> results) {
    final bool next = _hasNetwork(results);
    if (next == _isOnline) return;
    _isOnline = next;
    if (!_online.isClosed) _online.add(next);
  }

  static bool _hasNetwork(List<ConnectivityResult> results) {
    return results.isNotEmpty &&
        results.any((ConnectivityResult r) => r != ConnectivityResult.none);
  }

  Future<bool> isOnline() async {
    final List<ConnectivityResult> results =
        await _connectivity.checkConnectivity();
    _isOnline = _hasNetwork(results);
    return _isOnline;
  }

  Future<void> refresh() => isOnline();

  void dispose() {
    _subscription.cancel();
    _online.close();
  }
}
