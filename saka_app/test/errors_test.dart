import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:saka_app/core/errors/api_exception.dart';
import 'package:saka_app/core/utils/formatters.dart';
import 'package:saka_app/data/models/listing.dart';

/// The rule these tests defend: a user must never see a status code, a stack
/// trace, a SQL fragment or raw JSON. Every failure becomes one sentence they
/// can act on.
void main() {
  DioException badResponse(int status, Map<String, dynamic> body) {
    final RequestOptions options = RequestOptions(path: '/x');
    return DioException(
      requestOptions: options,
      type: DioExceptionType.badResponse,
      response: Response<dynamic>(
        requestOptions: options,
        statusCode: status,
        data: body,
      ),
    );
  }

  /// The envelope the SAKA API actually sends, verified against the live
  /// backend: `{"error": {"code", "message", "request_id"}}`.
  Map<String, dynamic> envelope(String code, String message) =>
      <String, dynamic>{
        'error': <String, dynamic>{
          'code': code,
          'message': message,
          'request_id': '01KZQJDHF01PKGW5VKAFFD9AKZ',
        },
      };

  group('status → kind', () {
    test('401 is an auth problem and says so plainly', () {
      final ApiException error = ApiException.from(
        badResponse(401, envelope('UNAUTHENTICATED', 'Unauthenticated.')),
      );
      expect(error.kind, ApiErrorKind.unauthorized);
      expect(error.isAuthProblem, isTrue);
    });

    test('403 is forbidden and is NOT retryable', () {
      final ApiException error = ApiException.from(
        badResponse(403, envelope('FORBIDDEN', 'You are not allowed.')),
      );
      expect(error.kind, ApiErrorKind.forbidden);
      // Offering "Try again" on a permission failure is a lie.
      expect(error.isRetryable, isFalse);
    });

    test('409 keeps the booking wording the backend chose', () {
      final ApiException error = ApiException.from(
        badResponse(
          409,
          envelope(
            'CONFLICT',
            'That time is no longer available. Please choose another.',
          ),
        ),
      );
      expect(error.kind, ApiErrorKind.conflict);
      expect(
        error.message,
        'That time is no longer available. Please choose another.',
      );
    });

    test('429 is rate limiting, not a server fault', () {
      expect(
        ApiException.from(badResponse(429, envelope('RATE_LIMITED', 'Slow down.')))
            .kind,
        ApiErrorKind.rateLimited,
      );
    });

    test('5xx is retryable and carries a reference', () {
      final ApiException error = ApiException.from(
        badResponse(500, envelope('SERVER_ERROR', 'Something went wrong.')),
      );
      expect(error.kind, ApiErrorKind.server);
      expect(error.isRetryable, isTrue);
      expect(error.requestId, '01KZQJDHF01PKGW5VKAFFD9AKZ');
    });
  });

  group('422 field errors bind to inputs', () {
    test('exposes per-field messages', () {
      final ApiException error = ApiException.from(
        badResponse(422, <String, dynamic>{
          ...envelope('VALIDATION_FAILED', 'The email field is required.'),
          'errors': <String, dynamic>{
            'email': <dynamic>['The email field is required.'],
            'password': <dynamic>['The password must be 8 characters.'],
          },
        }),
      );

      expect(error.kind, ApiErrorKind.validation);
      expect(error.fieldError('email'), 'The email field is required.');
      expect(error.fieldError('password'), contains('8 characters'));
      expect(error.fieldError('nonexistent'), isNull);
    });
  });

  group('transport failures', () {
    test('a dead connection is distinguished from a slow one', () {
      final RequestOptions options = RequestOptions(path: '/x');

      expect(
        ApiException.from(
          DioException(
            requestOptions: options,
            type: DioExceptionType.connectionError,
          ),
        ).kind,
        ApiErrorKind.offline,
      );

      expect(
        ApiException.from(
          DioException(
            requestOptions: options,
            type: DioExceptionType.receiveTimeout,
          ),
        ).kind,
        ApiErrorKind.timeout,
      );
    });

    test('a cancelled request is not an error the user should see', () {
      final ApiException error = ApiException.from(
        DioException(
          requestOptions: RequestOptions(path: '/x'),
          type: DioExceptionType.cancel,
        ),
      );
      expect(error.kind, ApiErrorKind.cancelled);
      expect(error.isRetryable, isFalse);
    });
  });

  group('nothing internal reaches the user', () {
    test('a raw Laravel debug payload never becomes the message', () {
      // What a 500 looks like with APP_DEBUG accidentally on.
      final ApiException error = ApiException.from(
        badResponse(500, <String, dynamic>{
          'message': 'SQLSTATE[42S22]: Column not found',
          'exception': 'Illuminate\\Database\\QueryException',
          'file': '/var/www/saka/app/Models/Listing.php',
          'line': 214,
          'trace': <dynamic>[<String, dynamic>{'file': '/var/www/x.php'}],
        }),
      );

      expect(error.message, isNot(contains('SQLSTATE')));
      expect(error.message, isNot(contains('Illuminate')));
      expect(error.message, isNot(contains('/var/www')));
      expect(error.message, 'Something went wrong on our side. Please try again.');
    });

    test('an unreadable 2xx body is malformed, not a server fault', () {
      const ApiException error = ApiException.malformed();
      expect(error.kind, ApiErrorKind.malformed);
      expect(error.message, 'Something went wrong loading this content.');
    });
  });

  group('price formatting', () {
    test('abbreviates for a card and spells out in full for a detail', () {
      const Price price = Price(amount: 1200000, unit: 'monthly');
      expect(Fmt.priceCompact(price), 'TZS 1.2M');
      expect(Fmt.price(price), 'TZS 1,200,000 / month');
    });

    test('a missing price is never rendered as free', () {
      const Price price = Price();
      expect(Fmt.price(price), 'Price on request');
      expect(Fmt.priceCompact(price), 'On request');
    });

    test('drops a pointless decimal', () {
      expect(Fmt.priceCompact(const Price(amount: 2000000)), 'TZS 2M');
      expect(Fmt.priceCompact(const Price(amount: 850000)), 'TZS 850K');
    });

    test('a booking date is a calendar date, not an instant', () {
      // Formatted WITHOUT toLocal(), or a 00:00 UTC date moves back a day for
      // anyone west of Greenwich.
      expect(Fmt.apiDate('2026-08-11'), 'Tue 11 Aug');
      expect(Fmt.apiDate('not-a-date'), 'not-a-date');
    });
  });
}
