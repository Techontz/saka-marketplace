import 'dart:io' show HttpDate;

import 'package:dio/dio.dart';

/// Every failure this app can show a human.
///
/// The API's envelope is `{"error": {"code", "message", "request_id"}}` plus an
/// optional `errors` map on 422 — verified against the live backend, not
/// assumed. Nothing outside this file parses that envelope, and nothing outside
/// this file decides what a user reads.
enum ApiErrorKind {
  /// No usable connection. Distinct from a timeout: the copy differs and so
  /// does the retry advice.
  offline,
  timeout,
  unauthorized,
  forbidden,
  notFound,

  /// 409. On this backend it means one thing in practice — a booking slot was
  /// taken between rendering and submitting.
  conflict,
  validation,
  rateLimited,
  server,

  /// A 2xx whose body was not the shape we parse. Its own kind because the fix
  /// is a code change, not a retry.
  malformed,
  cancelled,
  unknown,
}

class ApiException implements Exception {
  const ApiException({
    required this.kind,
    required this.message,
    this.statusCode,
    this.requestId,
    this.fieldErrors = const <String, List<String>>{},
    this.retryAfter,
  });

  final ApiErrorKind kind;

  /// Safe to render verbatim. Either the backend's own user-facing string or
  /// one of the fallbacks below — never an exception's `toString()`.
  final String message;

  final int? statusCode;

  /// The API stamps a ULID on every error. Surfaced in the UI only on 5xx,
  /// where it is the one thing that makes a support conversation tractable.
  final String? requestId;

  /// 422 only, keyed by field name, for binding straight onto form inputs.
  final Map<String, List<String>> fieldErrors;

  /// 429 only: how long the API said to wait, from its `Retry-After` header.
  ///
  /// Registration is capped at 3 per hour, so the wait is measured in tens of
  /// minutes. "Please slow down" on its own invites the user to tap again
  /// immediately, which is exactly the behaviour that put them here.
  final Duration? retryAfter;

  /// "in about 30 minutes" — for appending to a rate-limit message.
  ///
  /// Rounded up, and never says "in 0 minutes": under a minute reads as
  /// "in a moment", which is both true and actionable.
  String? get retryAfterLabel {
    final Duration? wait = retryAfter;
    if (wait == null || wait <= Duration.zero) return null;
    if (wait.inMinutes < 1) return 'in a moment';
    if (wait.inMinutes < 60) {
      final int minutes = wait.inSeconds ~/ 60 + (wait.inSeconds % 60 > 0 ? 1 : 0);
      return 'in about $minutes minute${minutes == 1 ? '' : 's'}';
    }
    final int hours = wait.inHours + (wait.inMinutes % 60 > 0 ? 1 : 0);
    return 'in about $hours hour${hours == 1 ? '' : 's'}';
  }

  bool get isAuthProblem => kind == ApiErrorKind.unauthorized;
  bool get isRetryable =>
      kind == ApiErrorKind.offline ||
      kind == ApiErrorKind.timeout ||
      kind == ApiErrorKind.server;

  /// The first message for [field], for an inline error under an input.
  String? fieldError(String field) {
    final List<String>? messages = fieldErrors[field];
    return (messages == null || messages.isEmpty) ? null : messages.first;
  }

  /// Build from anything Dio throws.
  ///
  /// This is the only place a `DioException` is allowed to exist. Above this
  /// line the app deals in [ApiException] and nothing else, which is what keeps
  /// a transport detail from reaching a widget.
  factory ApiException.from(Object error) {
    if (error is ApiException) return error;
    if (error is! DioException) {
      return const ApiException(
        kind: ApiErrorKind.unknown,
        message: _unknownMessage,
      );
    }

    switch (error.type) {
      case DioExceptionType.cancel:
        return const ApiException(
          kind: ApiErrorKind.cancelled,
          message: 'Request cancelled.',
        );

      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return const ApiException(
          kind: ApiErrorKind.timeout,
          message: 'That took too long. Your connection may be slow — try again.',
        );

      case DioExceptionType.connectionError:
      case DioExceptionType.unknown:
        return const ApiException(
          kind: ApiErrorKind.offline,
          message: "Your connection seems unavailable. Check your internet and try again.",
        );

      case DioExceptionType.badCertificate:
        return const ApiException(
          kind: ApiErrorKind.unknown,
          message: 'Could not establish a secure connection.',
        );

      case DioExceptionType.badResponse:
        return ApiException._fromResponse(error.response);

      // A wildcard rather than an exhaustive list: Dio adds transport error
      // types between minor versions, and a new one must degrade to a readable
      // message rather than fail the build.
      default:
        return const ApiException(
          kind: ApiErrorKind.unknown,
          message: _unknownMessage,
        );
    }
  }

  factory ApiException._fromResponse(Response<dynamic>? response) {
    final int status = response?.statusCode ?? 0;
    final dynamic body = response?.data;

    String? serverMessage;
    String? requestId;
    Map<String, List<String>> fields = const <String, List<String>>{};

    if (body is Map) {
      final dynamic envelope = body['error'];
      if (envelope is Map) {
        final dynamic m = envelope['message'];
        if (m is String && m.trim().isNotEmpty) serverMessage = m.trim();
        final dynamic id = envelope['request_id'];
        if (id is String && id.isNotEmpty) requestId = id;
      }

      final dynamic errors = body['errors'];
      if (errors is Map) {
        fields = <String, List<String>>{
          for (final MapEntry<dynamic, dynamic> e in errors.entries)
            if (e.key is String && e.value is List)
              e.key as String: <String>[
                for (final dynamic v in e.value as List)
                  if (v is String) v,
              ],
        };
      }
    }

    /// The backend's own message is preferred wherever it is written FOR a
    /// user. On 5xx it is not — the generic envelope there says "Something went
    /// wrong", which is true but says nothing about what to do next.
    String pick(ApiErrorKind kind, String fallback) {
      if (kind == ApiErrorKind.server) return fallback;
      return serverMessage ?? fallback;
    }

    final (ApiErrorKind kind, String fallback) = switch (status) {
      401 => (ApiErrorKind.unauthorized, 'Your session has expired. Please sign in again.'),
      403 => (ApiErrorKind.forbidden, "You don't have permission to do this."),
      404 => (ApiErrorKind.notFound, 'This content is no longer available.'),
      409 => (ApiErrorKind.conflict, 'That is no longer available. Please choose another.'),
      422 => (ApiErrorKind.validation, 'Please check the highlighted fields.'),
      429 => (ApiErrorKind.rateLimited, 'Too many requests. Please try again shortly.'),
      >= 500 => (ApiErrorKind.server, 'Something went wrong on our side. Please try again.'),
      _ => (ApiErrorKind.unknown, _unknownMessage),
    };

    return ApiException(
      kind: kind,
      message: pick(kind, fallback),
      statusCode: status,
      requestId: requestId,
      fieldErrors: fields,
      retryAfter: _retryAfterOf(response),
    );
  }

  /// Reads `Retry-After`, which RFC 9110 allows as either a delay in seconds or
  /// an HTTP date. Laravel sends seconds; the date form is handled so a CDN or
  /// WAF sitting in front of the API cannot make this silently return null.
  static Duration? _retryAfterOf(Response<dynamic>? response) {
    final String? raw = response?.headers.value('retry-after')?.trim();
    if (raw == null || raw.isEmpty) return null;

    final int? seconds = int.tryParse(raw);
    if (seconds != null) return Duration(seconds: seconds);

    try {
      final Duration wait =
          HttpDate.parse(raw).difference(DateTime.now().toUtc());
      return wait.isNegative ? null : wait;
    } on Object {
      return null;
    }
  }

  /// A 2xx we could not read. Separated from [ApiErrorKind.server] so it is
  /// obvious in logs that the contract drifted rather than the server failing.
  const ApiException.malformed()
      : kind = ApiErrorKind.malformed,
        message = 'Something went wrong loading this content.',
        statusCode = null,
        requestId = null,
        fieldErrors = const <String, List<String>>{},
        retryAfter = null;

  static const String _unknownMessage = 'Something went wrong. Please try again.';

  @override
  String toString() => 'ApiException($kind, $statusCode): $message';
}
