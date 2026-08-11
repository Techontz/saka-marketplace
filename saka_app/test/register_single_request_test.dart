import 'dart:convert';
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:saka_app/core/errors/api_exception.dart';
import 'package:saka_app/core/network/api_client.dart';
import 'package:saka_app/core/network/connectivity_service.dart';
import 'package:saka_app/core/storage/secure_store.dart';
import 'package:saka_app/data/repositories/auth_repository.dart';

/// How many HTTP requests one registration attempt actually produces.
///
/// Registration is the one call in this app that must never be replayed: a
/// second POST does not create a second account (the email is unique) but it
/// DOES spend another slot of the API's `auth-register` budget, which is 3 per
/// hour. A client that retries twice turns one honest attempt into three and
/// the user is rate-limited out of signing up at all.
///
/// So this counts real requests against a real socket rather than trusting a
/// mock: the retry lives in a Dio interceptor, and a mock adapter would not
/// exercise it.
void main() {
  final TestWidgetsFlutterBinding binding =
      TestWidgetsFlutterBinding.ensureInitialized();

  /// connectivity_plus has no platform side in a unit test, and the retry the
  /// read path depends on is gated on `isOnline()`. Left unmocked the device
  /// reads as offline, the retry never runs, and the contrast test below would
  /// pass for entirely the wrong reason.
  setUpAll(() {
    // The test binding installs an HttpOverrides that answers every real
    // socket with a 400 so widget tests cannot reach the network by accident.
    // This file's whole purpose is to count real requests, so it opts out.
    HttpOverrides.global = null;

    const MethodChannel method =
        MethodChannel('dev.fluttercommunity.plus/connectivity');
    const MethodChannel event =
        MethodChannel('dev.fluttercommunity.plus/connectivity_status');

    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      method,
      (MethodCall call) async =>
          call.method == 'check' || call.method == 'checkConnectivity'
              ? <String>['wifi']
              : null,
    );
    binding.defaultBinaryMessenger
        .setMockMethodCallHandler(event, (MethodCall call) async => null);
  });

  late HttpServer server;
  late List<String> received;
  late int Function() statusToReturn;

  /// Serves whatever [statusToReturn] says and records every hit.
  Future<void> startServer() async {
    received = <String>[];
    server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    server.listen((HttpRequest request) async {
      received.add('${request.method} ${request.uri.path}');
      final int status = statusToReturn();
      request.response.statusCode = status;
      request.response.headers.contentType = ContentType.json;
      if (status == 429) {
        request.response.headers.set('Retry-After', '1827');
      }
      request.response.write(jsonEncode(<String, dynamic>{
        'error': <String, dynamic>{
          'code': status == 429 ? 'RATE_LIMITED' : 'SERVER_ERROR',
          'message': status == 429
              ? 'Too many requests. Please slow down.'
              : 'Something went wrong on our end.',
          'request_id': '01TESTREQUESTID',
        },
      }));
      await request.response.close();
    });
  }

  /// A client wired exactly as the app wires it — same interceptors, same
  /// retry rules — but pointed at the local server.
  AuthRepository repositoryFor(HttpServer server) {
    final ApiClient api = ApiClient(
      secureStore: SecureStore(),
      connectivity: ConnectivityService(),
    );
    api.raw.options.baseUrl = 'http://127.0.0.1:${server.port}';
    return AuthRepository(api: api);
  }

  Future<void> attemptRegister(AuthRepository repository) async {
    try {
      await repository.register(
        firstName: 'Asha',
        lastName: 'Mbwana',
        email: 'asha@example.com',
        password: 'Password123',
        passwordConfirmation: 'Password123',
      );
    } on Object {
      // The failure is the point; the request count is what is asserted.
    }
  }

  setUp(startServer);
  tearDown(() => server.close(force: true));

  test('a 500 does not replay the registration POST', () async {
    // Production's actual behaviour while its database has no roles. A retry
    // here would burn three of the user's three hourly attempts on one tap.
    statusToReturn = () => 500;

    await attemptRegister(repositoryFor(server));

    expect(received, <String>['POST /auth/register']);
  });

  test('a 429 does not replay the registration POST', () async {
    statusToReturn = () => 429;

    await attemptRegister(repositoryFor(server));

    expect(received, <String>['POST /auth/register']);
  });

  test('the 429 reaches the user as the API worded it, with its request id',
      () async {
    statusToReturn = () => 429;
    final AuthRepository repository = repositoryFor(server);

    ApiException? captured;
    try {
      await repository.register(
        firstName: 'Asha',
        lastName: 'Mbwana',
        email: 'asha@example.com',
        password: 'Password123',
        passwordConfirmation: 'Password123',
      );
    } on Object catch (error) {
      captured = ApiException.from(error);
    }

    expect(captured, isNotNull);
    expect(captured!.statusCode, 429);
    // Not swallowed into a generic "something went wrong".
    expect(captured.message, 'Too many requests. Please slow down.');
    expect(captured.requestId, '01TESTREQUESTID');
  });

  test('reads keep their retry — the read path must not regress', () async {
    // Contrast case. GET is idempotent, so the bounded retry that makes the
    // app usable on a weak connection stays. If this ever starts reporting a
    // single request, the retry has been lost.
    statusToReturn = () => 500;
    final ApiClient api = ApiClient(
      secureStore: SecureStore(),
      connectivity: ConnectivityService(),
    );
    api.raw.options.baseUrl = 'http://127.0.0.1:${server.port}';

    try {
      await api.get<void>('/categories', parse: (dynamic _) {});
    } on Object {
      // Expected.
    }

    expect(received.length, greaterThan(1),
        reason: 'GET should still retry; only writes are single-shot');
    expect(received.every((String r) => r.startsWith('GET')), isTrue);
  });
}
