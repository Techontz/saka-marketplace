import 'package:dio/dio.dart';

import '../../core/network/api_client.dart';
import '../models/booking.dart';
import '../models/boundary.dart';
import '../models/json.dart';
import '../models/listing.dart';
import '../models/paginated.dart';

/// Everything under `/seller` — the vendor's own workspace.
///
/// Kept apart from AccountRepository because the authorisation boundary is
/// different: these endpoints require the seller role and operate on records
/// the vendor owns. Mixing them would make it easy to call a vendor endpoint
/// from a customer screen and get a 403 the UI has no story for.
class VendorRepository {
  VendorRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  // --- dashboard -----------------------------------------------------------

  Future<VendorDashboard> dashboard() {
    return _api.get<VendorDashboard>(
      '/seller/dashboard',
      parse: (dynamic body) => VendorDashboard.parse(asMap(body)['data']),
    );
  }

  Future<Map<String, dynamic>> analytics({int days = 30}) {
    return _api.get<Map<String, dynamic>>(
      '/seller/analytics',
      query: <String, dynamic>{'days': days},
      parse: (dynamic body) => asMap(asMap(body)['data']),
    );
  }

  // --- listings ------------------------------------------------------------

  Future<Paginated<Listing>> listings({
    int page = 1,
    String? status,
    String? search,
  }) {
    return _api.get<Paginated<Listing>>(
      '/seller/listings',
      query: <String, dynamic>{
        'page': page,
        'status': status,
        'q': search,
      },
      parse: (dynamic body) => Paginated.parse<Listing>(body, Listing.tryParse),
    );
  }

  Future<Listing> listing(String uuid) {
    return _api.get<Listing>(
      '/seller/listings/$uuid',
      parse: (dynamic body) {
        final Listing? listing = Listing.tryParse(asMap(body)['data']);
        if (listing == null) throw StateError('listing not parseable');
        return listing;
      },
    );
  }

  Future<Listing> createListing(Map<String, dynamic> payload) {
    return _api.post<Listing>(
      '/seller/listings',
      body: payload,
      parse: (dynamic body) {
        final Listing? listing = Listing.tryParse(asMap(body)['data']);
        if (listing == null) throw StateError('listing not parseable');
        return listing;
      },
    );
  }

  Future<Listing> updateListing(String uuid, Map<String, dynamic> payload) {
    return _api.patch<Listing>(
      '/seller/listings/$uuid',
      body: payload,
      parse: (dynamic body) {
        final Listing? listing = Listing.tryParse(asMap(body)['data']);
        if (listing == null) throw StateError('listing not parseable');
        return listing;
      },
    );
  }

  Future<void> deleteListing(String uuid) {
    return _api.delete<void>('/seller/listings/$uuid', parse: (_) {});
  }

  /// Lifecycle transitions. Each is its own endpoint on the backend rather than
  /// a status field, because each has its own rules — `submit` runs moderation,
  /// `sold` stops the expiry sweeper.
  Future<void> transition(String uuid, VendorListingAction action) {
    return _api.post<void>(
      '/seller/listings/$uuid/${action.path}',
      parse: (_) {},
    );
  }

  /// Upload one photo, with progress.
  ///
  /// The field is `image` (singular) — verified against the live endpoint,
  /// which rejects `images[]` with "The image field is required." The backend
  /// sniffs the real MIME from magic bytes and accepts only JPEG/PNG/WebP.
  Future<void> uploadListingImage({
    required String listingUuid,
    required String filePath,
    void Function(int sent, int total)? onProgress,
    CancelToken? cancelToken,
  }) async {
    final FormData form = FormData.fromMap(<String, dynamic>{
      'image': await MultipartFile.fromFile(filePath),
    });

    return _api.upload<void>(
      '/seller/listings/$listingUuid/media',
      form: form,
      onProgress: onProgress,
      cancelToken: cancelToken,
      parse: (_) {},
    );
  }

  Future<void> deleteListingImage({
    required String listingUuid,
    required String mediaUuid,
  }) {
    return _api.delete<void>(
      '/seller/listings/$listingUuid/media/$mediaUuid',
      parse: (_) {},
    );
  }

  Future<void> makeImagePrimary({
    required String listingUuid,
    required String mediaUuid,
  }) {
    return _api.post<void>(
      '/seller/listings/$listingUuid/media/$mediaUuid/primary',
      parse: (_) {},
    );
  }

  Future<void> reorderImages({
    required String listingUuid,
    required List<String> mediaUuids,
  }) {
    return _api.patch<void>(
      '/seller/listings/$listingUuid/media/reorder',
      body: <String, dynamic>{'order': mediaUuids},
      parse: (_) {},
    );
  }

  // --- profile -------------------------------------------------------------

  Future<Map<String, dynamic>> vendorProfile() {
    return _api.get<Map<String, dynamic>>(
      '/seller/vendor-profile',
      parse: (dynamic body) => asMap(asMap(body)['data']),
    );
  }

  Future<Map<String, dynamic>> updateVendorProfile(
    Map<String, dynamic> changes,
  ) {
    return _api.patch<Map<String, dynamic>>(
      '/seller/vendor-profile',
      body: changes,
      parse: (dynamic body) => asMap(asMap(body)['data']),
    );
  }

  // --- inquiries and reviews ------------------------------------------------

  Future<Paginated<Map<String, dynamic>>> inquiries({
    int page = 1,
    String? status,
  }) {
    return _api.get<Paginated<Map<String, dynamic>>>(
      '/seller/inquiries',
      query: <String, dynamic>{'page': page, 'status': status},
      parse: (dynamic body) => Paginated.parse<Map<String, dynamic>>(
        body,
        (dynamic item) => item is Map ? asMap(item) : null,
      ),
    );
  }

  Future<void> replyToInquiry({
    required String inquiryId,
    required String message,
  }) {
    return _api.post<void>(
      '/seller/inquiries/$inquiryId/reply',
      body: <String, dynamic>{'message': message},
      parse: (_) {},
    );
  }

  // --- verification --------------------------------------------------------

  /// Identity verification state, plus the backend's own honest answer about
  /// whether an automated check exists.
  Future<VerificationState> verifications() {
    return _api.get<VerificationState>(
      '/seller/verifications',
      parse: VerificationState.parse,
    );
  }

  /// Submit an identity document.
  ///
  /// `document_number` is sent once, over TLS, and is never written to disk on
  /// the device, never logged (the log interceptor redacts this exact key) and
  /// never read back — the API only ever returns it masked.
  Future<void> submitVerification({
    required String type,
    required String filePath,
    String? documentNumber,
    void Function(int sent, int total)? onProgress,
  }) async {
    final FormData form = FormData.fromMap(<String, dynamic>{
      'type': type,
      'document': await MultipartFile.fromFile(filePath),
      if (documentNumber != null && documentNumber.trim().isNotEmpty)
        'document_number': documentNumber.trim(),
    });

    return _api.upload<void>(
      '/seller/verifications',
      form: form,
      onProgress: onProgress,
      parse: (_) {},
    );
  }

  // --- specialist ----------------------------------------------------------

  Future<List<SpecialistService>> specialistServices(String listingUuid) {
    return _api.get<List<SpecialistService>>(
      '/seller/specialist/$listingUuid/services',
      parse: (dynamic body) =>
          SpecialistService.parseList(asMap(body)['data']),
    );
  }

  Future<Paginated<Booking>> specialistBookings(
    String listingUuid, {
    int page = 1,
    String? status,
  }) {
    return _api.get<Paginated<Booking>>(
      '/seller/specialist/$listingUuid/bookings',
      query: <String, dynamic>{'page': page, 'status': status},
      parse: (dynamic body) => Paginated.parse<Booking>(body, Booking.tryParse),
    );
  }

  Future<void> transitionBooking({
    required String bookingUuid,
    required String status,
    String? note,
  }) {
    return _api.post<void>(
      '/seller/specialist/bookings/$bookingUuid/transition',
      body: <String, dynamic>{
        'status': status,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      parse: (_) {},
    );
  }

  // --- land boundary -------------------------------------------------------

  /// The vendor's own view of a parcel, including drafts on unpublished
  /// listings that the public detail endpoint would not return.
  Future<ListingBoundary?> boundary(String listingUuid) {
    return _api.get<ListingBoundary?>(
      '/seller/listings/$listingUuid/boundary',
      // Never deduplicated: this is read immediately after a save to confirm
      // what the server actually stored, and a shared in-flight future could
      // hand back the pre-save answer.
      deduplicate: false,
      parse: (dynamic body) =>
          ListingBoundary.tryParse(asMap(body)['data']),
    );
  }

  /// Save a parcel.
  ///
  /// `PUT`, not POST — the boundary is a single replaceable resource on the
  /// listing, so re-sending the same rings is idempotent and a retry cannot
  /// create a second parcel.
  ///
  /// The client sends ONLY geometry. Area and perimeter come back computed by
  /// `LandBoundaryService`; measuring them on the phone would produce a figure
  /// that disagrees with the website for the same plot.
  Future<ListingBoundary> saveBoundary({
    required String listingUuid,
    required List<List<List<double>>> rings,
    String? surveyReference,
    String? notes,
  }) {
    return _api.put<ListingBoundary>(
      '/seller/listings/$listingUuid/boundary',
      body: <String, dynamic>{
        'rings': rings,
        'survey_reference': ?surveyReference,
        'notes': ?notes,
      },
      parse: (dynamic body) {
        final ListingBoundary? boundary =
            ListingBoundary.tryParse(asMap(body)['data']);
        if (boundary == null) throw StateError('boundary not parseable');
        return boundary;
      },
    );
  }

  Future<void> deleteBoundary(String listingUuid) {
    return _api.delete<void>(
      '/seller/listings/$listingUuid/boundary',
      parse: (_) {},
    );
  }

  // --- promotions ----------------------------------------------------------

  Future<Paginated<Map<String, dynamic>>> promotions({int page = 1}) {
    return _api.get<Paginated<Map<String, dynamic>>>(
      '/seller/promotions',
      query: <String, dynamic>{'page': page},
      parse: (dynamic body) => Paginated.parse<Map<String, dynamic>>(
        body,
        (dynamic item) => item is Map ? asMap(item) : null,
      ),
    );
  }

  Future<Map<String, dynamic>> promotionOptions() {
    return _api.get<Map<String, dynamic>>(
      '/seller/promotions/options',
      parse: (dynamic body) => asMap(asMap(body)['data']),
    );
  }

  Future<List<Listing>> promotableListings() {
    return _api.get<List<Listing>>(
      '/seller/promotions/promotable',
      parse: (dynamic body) => Listing.parseList(asMap(body)['data']),
    );
  }
}

enum VendorListingAction {
  submit('submit'),
  pause('pause'),
  resume('resume'),
  sold('sold'),
  archive('archive'),
  duplicate('duplicate');

  const VendorListingAction(this.path);

  final String path;
}

/// The vendor dashboard payload, flattened to what the UI actually draws.
class VendorDashboard {
  const VendorDashboard({
    required this.totalListings,
    required this.activeListings,
    required this.pendingListings,
    required this.draftListings,
    required this.totalViews,
    required this.viewsLast30Days,
    required this.totalFavorites,
    required this.totalInquiries,
    required this.unreadInquiries,
    required this.canPublish,
    required this.sellerVerified,
    required this.profileCompletionPercent,
    required this.missingProfileFields,
  });

  final int totalListings;
  final int activeListings;
  final int pendingListings;
  final int draftListings;
  final int totalViews;
  final int viewsLast30Days;
  final int totalFavorites;
  final int totalInquiries;
  final int unreadInquiries;
  final bool canPublish;
  final bool sellerVerified;
  final int profileCompletionPercent;

  /// Named fields, from the backend's own checklist. The UI turns each into a
  /// concrete next action rather than showing a bare percentage.
  final List<String> missingProfileFields;

  static VendorDashboard parse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final Map<String, dynamic> listings = asMap(json['listings']);
    final Map<String, dynamic> engagement = asMap(json['engagement']);
    final Map<String, dynamic> verification = asMap(json['verification']);
    final Map<String, dynamic> completion = asMap(json['profile_completion']);

    return VendorDashboard(
      totalListings: asIntOr(listings['total'], 0),
      activeListings: asIntOr(listings['active'], 0),
      pendingListings: asIntOr(listings['pending'], 0),
      draftListings: asIntOr(listings['draft'], 0),
      totalViews: asIntOr(engagement['total_views'], 0),
      viewsLast30Days: asIntOr(engagement['views_last_30_days'], 0),
      totalFavorites: asIntOr(engagement['total_favorites'], 0),
      totalInquiries: asIntOr(engagement['total_inquiries'], 0),
      unreadInquiries: asIntOr(engagement['unread_inquiries'], 0),
      canPublish: asBool(verification['can_publish']),
      sellerVerified: asBool(verification['seller_verified']),
      profileCompletionPercent: asIntOr(completion['percent'], 0),
      missingProfileFields: asStringList(completion['missing']),
    );
  }
}

/// Verification requests plus the provider's honest availability.
class VerificationState {
  const VerificationState({
    required this.requests,
    required this.types,
    required this.automatedAvailable,
    required this.providerName,
    required this.nidaDigits,
  });

  final List<VerificationRequest> requests;

  /// `{value, label}` pairs — `national_id`, `passport`, `business_licence`…
  final List<({String value, String label})> types;

  /// False on this deployment, and the UI says so in words rather than leaving
  /// the vendor to assume a machine is deciding.
  final bool automatedAvailable;

  final String providerName;

  /// 20 for NIDA. Drives the client-side digit counter — a hint, never a gate:
  /// the server normalises and validates.
  final int nidaDigits;

  static VerificationState parse(dynamic body) {
    final Map<String, dynamic> json = asMap(body);
    final Map<String, dynamic> meta = asMap(json['meta']);
    final Map<String, dynamic> automated = asMap(meta['automated_verification']);

    return VerificationState(
      requests: <VerificationRequest>[
        for (final Map<String, dynamic> item in asMapList(json['data']))
          if (VerificationRequest.tryParse(item)
              case final VerificationRequest r)
            r,
      ],
      types: <({String value, String label})>[
        for (final Map<String, dynamic> item in asMapList(meta['types']))
          if (asString(item['value']) case final String value)
            (value: value, label: asStringOr(item['label'], value)),
      ],
      automatedAvailable: asBool(automated['available']),
      providerName: asStringOr(automated['provider'], 'manual_review'),
      nidaDigits: asIntOr(meta['nida_digits'], 20),
    );
  }
}

class VerificationRequest {
  const VerificationRequest({
    required this.uuid,
    required this.type,
    required this.status,
    this.typeLabel,
    this.statusLabel,
    this.reviewerNote,
    this.maskedNumber,
    this.submittedAt,
    this.reviewedAt,
  });

  final String uuid;
  final String type;

  /// `pending` | `approved` | `rejected` | `info_requested`.
  final String status;

  final String? typeLabel;
  final String? statusLabel;
  final String? reviewerNote;

  /// The API returns "•••• •••• •••• 6777" and never the real digits. This app
  /// has no code path that could produce the full number.
  final String? maskedNumber;

  final DateTime? submittedAt;
  final DateTime? reviewedAt;

  static VerificationRequest? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;
    return VerificationRequest(
      uuid: uuid,
      type: asStringOr(json['type'], 'national_id'),
      status: asStringOr(json['status'], 'pending'),
      typeLabel: asString(json['type_label']),
      statusLabel: asString(json['status_label']),
      reviewerNote: asString(json['reviewer_note']) ?? asString(json['note']),
      maskedNumber: asString(json['document_number_masked']) ??
          asString(json['masked_number']),
      submittedAt: asDate(json['created_at']),
      reviewedAt: asDate(json['reviewed_at']),
    );
  }
}
