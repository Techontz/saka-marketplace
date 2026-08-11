import 'json.dart';

/// The signed-in person.
///
/// `permissions` is the list the API itself computed. The app uses it to decide
/// what to SHOW; the backend decides what is allowed. Hiding a control the user
/// cannot use is courtesy — it is never the security boundary.
class AppUser {
  const AppUser({
    required this.uuid,
    required this.fullName,
    required this.email,
    this.firstName,
    this.lastName,
    this.phone,
    this.status = 'active',
    this.emailVerified = false,
    this.phoneVerified = false,
    this.canPublishListings = false,
    this.avatarUrl,
    this.roles = const <String>[],
    this.permissions = const <String>[],
    this.sellerProfile,
    this.createdAt,
  });

  final String uuid;
  final String fullName;
  final String email;
  final String? firstName;
  final String? lastName;
  final String? phone;
  final String status;
  final bool emailVerified;
  final bool phoneVerified;

  /// The backend's own answer to "may this account publish?", which folds in
  /// phone verification and moderation policy. Never re-derived on the client.
  final bool canPublishListings;

  final String? avatarUrl;
  final List<String> roles;
  final List<String> permissions;
  final SellerProfileSummary? sellerProfile;
  final DateTime? createdAt;

  /// Whether to offer "My business" at all.
  ///
  /// Having a seller profile is the signal, not holding the `seller` role: a
  /// buyer can be granted the role before completing onboarding, and dropping
  /// them into an empty vendor dashboard is a worse first impression than not
  /// showing the entrance yet.
  bool get isVendor => sellerProfile != null || roles.contains('seller');

  bool can(String permission) => permissions.contains(permission);

  /// Two letters for the avatar fallback. Never renders a blank grey circle.
  String get initials {
    final String first = (firstName ?? fullName).trim();
    final String last = (lastName ?? '').trim();
    final String a = first.isEmpty ? '' : first[0];
    final String b = last.isEmpty
        ? (first.split(' ').length > 1 ? first.split(' ')[1][0] : '')
        : last[0];
    final String out = '$a$b'.toUpperCase();
    return out.isEmpty ? 'S' : out;
  }

  static AppUser? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;

    return AppUser(
      uuid: uuid,
      fullName: asStringOr(json['full_name'], 'SAKA user'),
      email: asStringOr(json['email'], ''),
      firstName: asString(json['first_name']),
      lastName: asString(json['last_name']),
      phone: asString(json['phone']),
      status: asStringOr(json['status'], 'active'),
      emailVerified: asBool(json['email_verified']),
      phoneVerified: asBool(json['phone_verified']),
      canPublishListings: asBool(json['can_publish_listings']),
      avatarUrl: asString(json['avatar_url']),
      roles: asStringList(json['roles']),
      permissions: asStringList(json['permissions']),
      sellerProfile: SellerProfileSummary.tryParse(json['seller_profile']),
      createdAt: asDate(json['created_at']),
    );
  }
}

class SellerProfileSummary {
  const SellerProfileSummary({
    required this.slug,
    required this.displayName,
    this.isVerified = false,
    this.ratingAverage = 0,
    this.ratingCount = 0,
  });

  final String slug;
  final String displayName;
  final bool isVerified;

  /// Arrives as the STRING "0.00" here and as a number elsewhere.
  final double ratingAverage;

  final int ratingCount;

  static SellerProfileSummary? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;
    return SellerProfileSummary(
      slug: slug,
      displayName: asStringOr(json['display_name'], slug),
      isVerified: asBool(json['is_verified']),
      ratingAverage: asDoubleOr(json['rating_avg'], 0),
      ratingCount: asIntOr(json['rating_count'], 0),
    );
  }
}

/// What a successful `POST /auth/login` returns.
class AuthSession {
  const AuthSession({
    required this.user,
    required this.token,
    this.expiresAt,
  });

  final AppUser user;
  final String token;
  final DateTime? expiresAt;

  static AuthSession? tryParse(dynamic body) {
    final Map<String, dynamic> data = asMap(asMap(body)['data']);
    final AppUser? user = AppUser.tryParse(data['user']);
    final String? token = asString(data['token']);
    if (user == null || token == null) return null;
    return AuthSession(
      user: user,
      token: token,
      expiresAt: asDate(data['expires_at']),
    );
  }
}
