import '../../core/network/api_client.dart';
import '../models/booking.dart';
import '../models/json.dart';
import '../models/paginated.dart';

class BookingRepository {
  BookingRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  /// Create a booking.
  ///
  /// Deliberately a plain POST with no retry: the error interceptor only
  /// retries GETs, precisely so that a flaky connection can never turn one tap
  /// into two appointments. The backend enforces the same rule at the database
  /// level with a unique index on `(listing_id, slot_key)` — a 409 here means
  /// somebody else won the slot, and the caller must refresh the grid rather
  /// than try again.
  ///
  /// Field names verified against `Public\BookingController::store`: it wants
  /// `date` and `note`, not `scheduled_date` and `notes`.
  Future<Booking> create({
    required String serviceUuid,
    required String date,
    required String startTime,
    required String customerName,
    required String customerPhone,
    String? customerEmail,
    String? note,
  }) {
    return _api.post<Booking>(
      '/bookings',
      body: <String, dynamic>{
        'service_uuid': serviceUuid,
        'date': date,
        'start_time': startTime,
        'customer_name': customerName.trim(),
        'customer_phone': customerPhone.trim(),
        if (customerEmail != null && customerEmail.trim().isNotEmpty)
          'customer_email': customerEmail.trim(),
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
      },
      parse: (dynamic body) {
        final Booking? booking = Booking.tryParse(asMap(body)['data']);
        if (booking == null) throw StateError('booking not parseable');
        return booking;
      },
    );
  }

  Future<Paginated<Booking>> myBookings({int page = 1, String? status}) {
    return _api.get<Paginated<Booking>>(
      '/account/bookings',
      query: <String, dynamic>{'page': page, 'status': status},
      parse: (dynamic body) => Paginated.parse<Booking>(body, Booking.tryParse),
    );
  }

  Future<Booking> booking(String uuid) {
    return _api.get<Booking>(
      '/account/bookings/$uuid',
      parse: (dynamic body) {
        final Booking? booking = Booking.tryParse(asMap(body)['data']);
        if (booking == null) throw StateError('booking not parseable');
        return booking;
      },
    );
  }

  Future<void> cancel(String uuid, {String? reason}) {
    return _api.post<void>(
      '/account/bookings/$uuid/cancel',
      body: <String, dynamic>{
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      },
      parse: (_) {},
    );
  }
}
