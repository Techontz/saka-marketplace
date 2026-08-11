import 'json.dart';
import 'listing.dart';

/// A vendor's public shopfront.
class Business {
  const Business({
    required this.slug,
    required this.displayName,
    required this.location,
    this.businessType,
    this.businessTypeLabel,
    this.logoUrl,
    this.coverUrl,
    this.ratingAverage = 0,
    this.ratingCount = 0,
    this.listingCount = 0,
    this.isVerified = false,
    this.bio,
    this.contact = const BusinessContact(),
    this.openingHours = const <String, List<OpeningWindow>>{},
    this.socialLinks = const <String, String>{},
    this.activeListings = 0,
    this.memberSince,
  });

  final String slug;
  final String displayName;
  final GeoLocation location;
  final String? businessType;
  final String? businessTypeLabel;
  final String? logoUrl;
  final String? coverUrl;
  final double ratingAverage;
  final int ratingCount;
  final int listingCount;
  final bool isVerified;
  final String? bio;
  final BusinessContact contact;

  /// Keyed by `mon`…`sun`. A day with an empty list is CLOSED, which is
  /// different from a day that is absent — absent means the vendor never told
  /// us, and the UI must not claim they are shut.
  final Map<String, List<OpeningWindow>> openingHours;

  /// Only the networks the vendor actually configured. The UI iterates this
  /// map; it never renders a fixed row of grey social icons.
  final Map<String, String> socialLinks;

  final int activeListings;
  final DateTime? memberSince;

  bool get hasOpeningHours => openingHours.isNotEmpty;

  static Business? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;

    final Map<String, dynamic> rating = asMap(json['rating']);
    final Map<String, dynamic> stats = asMap(json['stats']);

    final Map<String, List<OpeningWindow>> hours =
        <String, List<OpeningWindow>>{};
    asMap(json['opening_hours']).forEach((String day, dynamic windows) {
      hours[day] = <OpeningWindow>[
        for (final Map<String, dynamic> w in asMapList(windows))
          if (OpeningWindow.tryParse(w) case final OpeningWindow window) window,
      ];
    });

    final Map<String, String> social = <String, String>{};
    asMap(json['social_links']).forEach((String network, dynamic url) {
      final String? href = asString(url);
      if (href != null) social[network] = href;
    });

    return Business(
      slug: slug,
      displayName: asStringOr(json['display_name'], slug),
      location: GeoLocation.parse(json['location']),
      businessType: asString(json['business_type']),
      businessTypeLabel: asString(json['business_type_label']),
      logoUrl: asString(json['logo_url']),
      coverUrl: asString(json['cover_url']),
      ratingAverage: asDoubleOr(rating['average'], 0),
      ratingCount: asIntOr(rating['count'], 0),
      listingCount: asIntOr(json['listing_count'], 0),
      isVerified: asBool(json['is_verified']),
      bio: asString(json['bio']),
      contact: BusinessContact.parse(json['contact']),
      openingHours: hours,
      socialLinks: social,
      activeListings: asIntOr(stats['active_listings'], 0),
      memberSince: asDate(json['member_since']),
    );
  }

  static List<Business> parseList(dynamic value) {
    return <Business>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final Business business) business,
    ];
  }
}

class BusinessContact {
  const BusinessContact({this.phone, this.email, this.whatsapp, this.website});

  final String? phone;
  final String? email;
  final String? whatsapp;
  final String? website;

  bool get hasAny =>
      phone != null || email != null || whatsapp != null || website != null;

  static BusinessContact parse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    return BusinessContact(
      phone: asString(json['phone']),
      email: asString(json['email']),
      whatsapp: asString(json['whatsapp']),
      website: asString(json['website']),
    );
  }
}

class OpeningWindow {
  const OpeningWindow({required this.open, required this.close});

  final String open;
  final String close;

  String get label => '$open – $close';

  static OpeningWindow? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? open = asString(json['open']);
    final String? close = asString(json['close']);
    if (open == null || close == null) return null;
    return OpeningWindow(open: open, close: close);
  }
}
