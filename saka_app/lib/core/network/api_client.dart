import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../app/config/app_config.dart';
import '../errors/api_exception.dart';
import '../storage/secure_store.dart';
import 'connectivity_service.dart';

/// The one place an HTTP request is made.
///
/// Repositories call this; controllers call repositories; widgets call
/// controllers. No widget in this app imports Dio.
class ApiClient {
  ApiClient({
    required SecureStore secureStore,
    required ConnectivityService connectivity,
    Dio? dio,
  })  : _secure = secureStore,
        _connectivity = connectivity,
        _dio = dio ?? Dio() {
    _dio.options = BaseOptions(
      baseUrl: AppConfig.apiBaseUrl,
      // Tuned for a 3G connection in Tanzania, not for an office LAN. A 10s
      // connect timeout on a slow handshake produces a failure the user reads
      // as "the app is broken" when the network was merely slow.
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(seconds: 30),
      sendTimeout: const Duration(seconds: 60), // uploads
      headers: <String, String>{
        'Accept': 'application/json',
        'X-Requested-With': 'SakaMobile',
      },
      // Every status is "successful" so the error interceptor sees the body and
      // can read the API's envelope, rather than Dio throwing on the status
      // line before anyone has looked at `error.message`.
      validateStatus: (int? status) => status != null && status > 0,
      responseType: ResponseType.json,
    );

    _dio.interceptors.add(_AuthInterceptor(_secure));
    _dio.interceptors.add(_ErrorInterceptor(_connectivity));

    if (AppConfig.enableNetworkLogs && !kReleaseMode) {
      _dio.interceptors.add(_RedactingLogInterceptor());
    }
  }

  final Dio _dio;
  final SecureStore _secure;
  final ConnectivityService _connectivity;

  /// Fired when a request comes back 401 and the session cannot be recovered.
  /// AuthController listens and tears the session down exactly once.
  final StreamController<void> _sessionExpired =
      StreamController<void>.broadcast();

  Stream<void> get onSessionExpired => _sessionExpired.stream;

  Dio get raw => _dio;

  /// In-flight GETs, keyed by their full URL.
  ///
  /// Deduplication, not caching. Two widgets asking for `/categories` in the
  /// same frame — which happens on the home screen every cold start — issue one
  /// request and share the result. Without this the app makes the same call
  /// three times before the first frame is on screen.
  final Map<String, Future<Response<dynamic>>> _inFlight =
      <String, Future<Response<dynamic>>>{};

  Future<T> get<T>(
    String path, {
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    bool deduplicate = true,
    required T Function(dynamic body) parse,
  }) async {
    final String key = _cacheKey(path, query);

    Future<Response<dynamic>> send() => _dio.get<dynamic>(
          path,
          queryParameters: _clean(query),
          cancelToken: cancelToken,
        );

    // A request carrying its own CancelToken is never shared: cancelling one
    // caller's search would cancel the other's, and they are not the same
    // question even when the URL matches.
    if (!deduplicate || cancelToken != null) {
      return _parse(await send(), parse);
    }

    // `send()..whenComplete(...)` looks equivalent but is not: the cascade
    // stores the ORIGINAL future while `whenComplete` returns a NEW derived one
    // that nobody listens to. When the request fails — a dead DNS lookup on a
    // release build, say — that orphan completes with an error no zone handles,
    // and Flutter reports "Unhandled Exception" for a failure the caller is
    // already catching. `.ignore()` marks the derived future as handled.
    final Future<Response<dynamic>> pending = _inFlight.putIfAbsent(key, () {
      final Future<Response<dynamic>> request = send();
      request.whenComplete(() => _inFlight.remove(key)).ignore();
      return request;
    });

    try {
      return _parse(await pending, parse);
    } finally {
      _inFlight.remove(key);
    }
  }

  Future<T> post<T>(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    CancelToken? cancelToken,
    required T Function(dynamic body) parse,
  }) async {
    final Response<dynamic> res = await _dio.post<dynamic>(
      path,
      data: body,
      queryParameters: _clean(query),
      cancelToken: cancelToken,
    );
    return _parse(res, parse);
  }

  Future<T> patch<T>(
    String path, {
    Object? body,
    CancelToken? cancelToken,
    required T Function(dynamic body) parse,
  }) async {
    final Response<dynamic> res = await _dio.patch<dynamic>(
      path,
      data: body,
      cancelToken: cancelToken,
    );
    return _parse(res, parse);
  }

  Future<T> put<T>(
    String path, {
    Object? body,
    CancelToken? cancelToken,
    required T Function(dynamic body) parse,
  }) async {
    final Response<dynamic> res = await _dio.put<dynamic>(
      path,
      data: body,
      cancelToken: cancelToken,
    );
    return _parse(res, parse);
  }

  Future<T> delete<T>(
    String path, {
    Object? body,
    CancelToken? cancelToken,
    required T Function(dynamic body) parse,
  }) async {
    final Response<dynamic> res = await _dio.delete<dynamic>(
      path,
      data: body,
      cancelToken: cancelToken,
    );
    return _parse(res, parse);
  }

  /// Multipart upload with progress.
  ///
  /// Uses the long send timeout: a listing photo over a Tanzanian mobile
  /// connection legitimately takes longer than a JSON round trip, and killing
  /// it at 30s means the upload never completes on the connections that need it
  /// most.
  Future<T> upload<T>(
    String path, {
    required FormData form,
    void Function(int sent, int total)? onProgress,
    CancelToken? cancelToken,
    required T Function(dynamic body) parse,
  }) async {
    final Response<dynamic> res = await _dio.post<dynamic>(
      path,
      data: form,
      cancelToken: cancelToken,
      onSendProgress: onProgress,
      options: Options(contentType: 'multipart/form-data'),
    );
    return _parse(res, parse);
  }

  T _parse<T>(Response<dynamic> response, T Function(dynamic body) parse) {
    try {
      return parse(response.data);
    } on ApiException {
      rethrow;
    } catch (_) {
      // A parse failure is a contract drift, not a server fault. Kept distinct
      // so it is obvious in a crash report which one happened.
      throw const ApiException.malformed();
    }
  }

  static String _cacheKey(String path, Map<String, dynamic>? query) {
    final Map<String, dynamic> cleaned = _clean(query) ?? <String, dynamic>{};
    if (cleaned.isEmpty) return path;
    final List<String> parts = cleaned.entries
        .map((MapEntry<String, dynamic> e) => '${e.key}=${e.value}')
        .toList()
      ..sort();
    return '$path?${parts.join('&')}';
  }

  /// Drops nulls and empty strings so an untouched filter never narrows a
  /// query — the same rule the web's `buildQuery` follows, so the two clients
  /// send identical URLs for identical user intent.
  static Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) return null;
    final Map<String, dynamic> out = <String, dynamic>{};
    query.forEach((String key, dynamic value) {
      if (value == null) return;
      if (value is String && value.trim().isEmpty) return;
      if (value is List) {
        final List<dynamic> items = value
            .where((dynamic v) => v != null && v.toString().trim().isNotEmpty)
            .toList(growable: false);
        if (items.isEmpty) return;
        // Laravel's parser expects the repeated-key-with-brackets form.
        out['$key[]'] = items;
        return;
      }
      out[key] = value;
    });
    return out.isEmpty ? null : out;
  }

  void notifySessionExpired() {
    if (!_sessionExpired.isClosed) _sessionExpired.add(null);
  }

  void dispose() {
    _sessionExpired.close();
    _dio.close(force: true);
  }
}

/// Attaches the bearer token.
class _AuthInterceptor extends Interceptor {
  _AuthInterceptor(this._secure);

  final SecureStore _secure;

  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) {
    final String? token = _secure.token;
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}

/// Turns every non-2xx into a `DioException` carrying the response, and adds a
/// bounded retry for reads only.
class _ErrorInterceptor extends Interceptor {
  _ErrorInterceptor(this._connectivity);

  final ConnectivityService _connectivity;

  static const int _maxRetries = 2;

  @override
  Future<void> onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) async {
    final int status = response.statusCode ?? 0;
    if (status >= 200 && status < 300) {
      handler.next(response);
      return;
    }

    handler.reject(
      DioException(
        requestOptions: response.requestOptions,
        response: response,
        type: DioExceptionType.badResponse,
      ),
      true,
    );
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    // Retry ONLY idempotent reads.
    //
    // This is the rule that keeps a flaky connection from creating two
    // bookings. POST /bookings is protected by a database constraint, but a
    // client that retries a non-idempotent write is asking the backend to
    // defend against its own client — and would produce a duplicate inquiry or
    // a double review where no such constraint exists.
    final RequestOptions req = err.requestOptions;
    final bool isRead = req.method.toUpperCase() == 'GET';
    final bool worthRetrying = err.type == DioExceptionType.connectionError ||
        err.type == DioExceptionType.connectionTimeout ||
        err.type == DioExceptionType.receiveTimeout ||
        (err.response?.statusCode ?? 0) >= 500;

    final int attempt = (req.extra['saka.retry'] as int?) ?? 0;

    if (isRead && worthRetrying && attempt < _maxRetries) {
      // Exponential-ish, and only once the device believes it has a network
      // again — retrying three times into a dead radio just spends battery.
      await Future<void>.delayed(Duration(milliseconds: 300 * (attempt + 1)));
      if (await _connectivity.isOnline()) {
        req.extra['saka.retry'] = attempt + 1;
        try {
          final Response<dynamic> res = await Dio(
            BaseOptions(
              baseUrl: req.baseUrl,
              headers: req.headers,
              connectTimeout: req.connectTimeout,
              receiveTimeout: req.receiveTimeout,
              validateStatus: (int? s) => s != null && s > 0,
            ),
          ).fetch<dynamic>(req);

          final int status = res.statusCode ?? 0;
          if (status >= 200 && status < 300) {
            handler.resolve(res);
            return;
          }
        } on DioException {
          // Fall through and report the original failure.
        }
      }
    }

    handler.next(err);
  }
}

/// Development logging that cannot leak a credential.
///
/// A stock `LogInterceptor` prints headers, which means it prints the bearer
/// token into the console and into any log file that captures it. This one
/// redacts before printing, and is compiled out of release by its call site.
class _RedactingLogInterceptor extends Interceptor {
  static const Set<String> _redactBodyKeys = <String>{
    'password',
    'password_confirmation',
    'current_password',
    'token',
    'document_number',
  };

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    debugPrint('→ ${options.method} ${options.uri}');
    if (options.data is Map) {
      debugPrint('  body ${_scrub(options.data as Map<dynamic, dynamic>)}');
    }
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    debugPrint('← ${response.statusCode} ${response.requestOptions.uri}');
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    debugPrint(
      '✗ ${err.response?.statusCode ?? err.type} ${err.requestOptions.uri}',
    );
    handler.next(err);
  }

  Map<dynamic, dynamic> _scrub(Map<dynamic, dynamic> input) {
    return <dynamic, dynamic>{
      for (final MapEntry<dynamic, dynamic> e in input.entries)
        e.key: _redactBodyKeys.contains(e.key.toString().toLowerCase())
            ? '***'
            : e.value,
    };
  }
}
