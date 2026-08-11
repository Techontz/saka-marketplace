import 'boundary.dart';
import 'json.dart';
import 'media.dart';

/// Money, as the API models it.
///
/// `amount` is a whole-currency integer (TZS has no minor unit in practice) and
/// may be absent — "Price on request" is a real state on this marketplace, not
/// a missing value to paper over with 0.
class Price {
  const Price({
    this.amount,
    this.currency = 'TZS',
    this.unit,
    this.isNegotiable = false,
  });

  final int? amount;
  final String currency;

  /// `monthly`, `daily`, `per_sqm`… Rendered as a suffix so a rent figure is
  /// never mistaken for a sale price.
  final String? unit;

  final bool isNegotiable;

  bool get onRequest => amount == null;

  static Price parse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    return Price(
      amount: asInt(json['amount']),
      currency: asStringOr(json['currency'], 'TZS'),
      unit: asString(json['unit']),
      isNegotiable: asBool(json['is_negotiable']),
    );
  }
}

class GeoLocation {
  const GeoLocation({
    this.region,
    this.regionSlug,
    this.district,
    this.districtSlug,
    this.ward,
    this.wardSlug,
    this.addressLine,
    this.latitude,
    this.longitude,
  });

  final String? region;
  final String? regionSlug;
  final String? district;
  final String? districtSlug;
  final String? ward;
  final String? wardSlug;
  final String? addressLine;
  final double? latitude;
  final double? longitude;

  bool get hasCoordinates => latitude != null && longitude != null;

  /// "Masaki, Kinondoni" — most specific first, at most two parts.
  ///
  /// Three parts overflow a card's single line on a 360px phone, and the region
  /// is the least useful of the three to somebody already browsing that region.
  String get shortLabel {
    final List<String> parts = <String>[
      ?ward,
      ?district,
      ?region,
    ];
    if (parts.isEmpty) return 'Tanzania';
    return parts.take(2).join(', ');
  }

  static GeoLocation parse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    return GeoLocation(
      region: asString(json['region']),
      regionSlug: asString(json['region_slug']),
      district: asString(json['district']),
      districtSlug: asString(json['district_slug']),
      ward: asString(json['ward']),
      wardSlug: asString(json['ward_slug']),
      // Businesses call it `street`, listings call it `address_line`.
      addressLine: asString(json['address_line']) ?? asString(json['street']),
      latitude: asDouble(json['latitude']),
      longitude: asDouble(json['longitude']),
    );
  }
}

class CategoryRef {
  const CategoryRef({
    required this.slug,
    required this.name,
    this.icon,
    this.parentSlug,
    this.parentName,
  });

  final String slug;
  final String name;

  /// An emoji in the seeded taxonomy ("🏠"), not an icon font name.
  final String? icon;

  final String? parentSlug;
  final String? parentName;

  static CategoryRef? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;
    final Map<String, dynamic> parent = asMap(json['parent']);
    return CategoryRef(
      slug: slug,
      name: asStringOr(json['name'], slug),
      icon: asString(json['icon']),
      parentSlug: asString(parent['slug']),
      parentName: asString(parent['name']),
    );
  }
}

/// A resolved attribute on the DETAIL resource: `{code, name, unit, value, label}`.
class ListingAttribute {
  const ListingAttribute({
    required this.code,
    required this.name,
    required this.value,
    this.unit,
    this.label,
  });

  final String code;
  final String name;
  final String value;
  final String? unit;

  /// The human label for an option-backed value ("Fully furnished" for
  /// `furnished`). Falls back to the raw value.
  final String? label;

  /// True when this came from the index resource's bare code→value map, where
  /// `name` was filled in from the code because nothing better was sent.
  bool get isUnlabelled => unit == null && label == null && name == code;

  String get displayValue {
    final String base = label ?? value;
    return unit == null ? base : '$base $unit';
  }

  static List<ListingAttribute> parseList(dynamic value) {
    // The list resource sends a MAP (`{beds: "1", sqft: "180"}`) and the detail
    // resource sends an ARRAY of objects. Both are real; both are handled.
    if (value is Map) {
      return <ListingAttribute>[
        for (final MapEntry<dynamic, dynamic> e in value.entries)
          ListingAttribute(
            code: e.key.toString(),
            name: e.key.toString(),
            value: e.value?.toString() ?? '',
          ),
      ];
    }

    return <ListingAttribute>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (asString(item['code']) case final String code)
          ListingAttribute(
            code: code,
            name: asStringOr(item['name'], code),
            value: asStringOr(item['value'], ''),
            unit: asString(item['unit']),
            label: asString(item['label']),
          ),
    ];
  }
}

class SellerRef {
  const SellerRef({
    required this.slug,
    required this.displayName,
    this.uuid,
    this.isVerified = false,
    this.ratingAverage = 0,
    this.ratingCount = 0,
    this.phone,
    this.memberSince,
  });

  final String slug;
  final String displayName;
  final String? uuid;
  final bool isVerified;

  /// Arrives as a NUMBER from the listing resource and as the STRING "0.00"
  /// from `auth/me`. asDouble absorbs both.
  final double ratingAverage;

  final int ratingCount;

  /// Present only where the backend chooses to expose it. Never assumed.
  final String? phone;

  final DateTime? memberSince;

  static SellerRef? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;
    return SellerRef(
      slug: slug,
      displayName: asStringOr(json['display_name'], slug),
      uuid: asString(json['uuid']),
      isVerified: asBool(json['is_verified']),
      ratingAverage: asDoubleOr(json['rating_avg'], 0),
      ratingCount: asIntOr(json['rating_count'], 0),
      phone: asString(json['phone']),
      memberSince: asDate(json['member_since']),
    );
  }
}

/// What a card shows.
///
/// Card and detail are ONE model rather than two, because they are one resource
/// with the detail adding fields. A separate `ListingDetail` would mean mapping
/// a card into a detail on navigation and losing the hero animation's identity.
/// The detail-only fields are simply null until the detail request lands.
class Listing {
  const Listing({
    required this.uuid,
    required this.slug,
    required this.title,
    required this.price,
    required this.location,
    this.purpose,
    this.condition,
    this.status,
    this.isVerified = false,
    this.isFeatured = false,
    this.category,
    this.primaryImage,
    this.attributes = const <ListingAttribute>[],
    this.views = 0,
    this.favorites = 0,
    this.publishedAt,
    // detail-only
    this.description,
    this.images = const <MediaImage>[],
    this.amenities = const <String>[],
    this.facilities = const <String>[],
    this.seller,
    this.supportsBoundary = false,
    this.boundary,
    this.availableFrom,
    this.isFavorited,
  });

  final String uuid;
  final String slug;
  final String title;
  final Price price;
  final GeoLocation location;

  /// `rent` | `sale` | `lease` | `hire`.
  final String? purpose;

  final String? condition;
  final String? status;
  final bool isVerified;
  final bool isFeatured;
  final CategoryRef? category;
  final MediaImage? primaryImage;
  final List<ListingAttribute> attributes;
  final int views;
  final int favorites;
  final DateTime? publishedAt;

  final String? description;
  final List<MediaImage> images;
  final List<String> amenities;
  final List<String> facilities;
  final SellerRef? seller;

  /// Land listings only. Drives whether the boundary viewer is offered.
  final bool supportsBoundary;

  /// The mapped parcel, when this listing has one. Parsed from the detail
  /// response's embedded `boundary` object.
  final ListingBoundary? boundary;

  bool get hasBoundary => boundary != null && !boundary!.isEmpty;

  final DateTime? availableFrom;

  /// From the detail response's `meta.is_favorited`. Null on a card, where the
  /// answer comes from the favourites controller instead.
  final bool? isFavorited;

  bool get isDetail => description != null;

  /// The image a card should draw. Detail responses drop `primary_image` and
  /// send `images` instead, so both are consulted.
  MediaImage? get displayImage {
    if (primaryImage != null) return primaryImage;
    if (images.isEmpty) return null;
    for (final MediaImage image in images) {
      if (image.isPrimary) return image;
    }
    return images.first;
  }

  String get purposeLabel => switch (purpose) {
        'rent' => 'Rent',
        'sale' => 'Sale',
        'lease' => 'Lease',
        'hire' => 'Hire',
        _ => '',
      };

  static Listing? tryParse(dynamic value, {bool? isFavorited}) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;

    final Map<String, dynamic> stats = asMap(json['stats']);

    return Listing(
      uuid: asStringOr(json['uuid'], slug),
      slug: slug,
      title: asStringOr(json['title'], 'Untitled listing'),
      price: Price.parse(json['price']),
      location: GeoLocation.parse(json['location']),
      purpose: asString(json['purpose']),
      condition: asString(json['condition']),
      status: asString(json['status']),
      isVerified: asBool(json['is_verified']),
      isFeatured: asBool(json['is_featured']),
      category: CategoryRef.tryParse(json['category']),
      primaryImage: MediaImage.tryParse(json['primary_image']),
      attributes: ListingAttribute.parseList(json['attributes']),
      views: asIntOr(stats['views'], 0),
      favorites: asIntOr(stats['favorites'], 0),
      publishedAt: asDate(json['published_at']),
      description: asString(json['description']),
      images: MediaImage.parseList(json['images']),
      amenities: _labels(json['amenities']),
      facilities: _labels(json['facilities']),
      seller: SellerRef.tryParse(json['seller']),
      supportsBoundary: asBool(json['supports_boundary']),
      boundary: ListingBoundary.tryParse(json['boundary']),
      availableFrom: asDate(json['available_from']),
      isFavorited: isFavorited,
    );
  }

  /// Amenities and facilities arrive either as strings or as `{name}` objects
  /// depending on which include was applied.
  static List<String> _labels(dynamic value) {
    if (value is! List) return const <String>[];
    return <String>[
      for (final dynamic item in value)
        if (item is Map)
          asStringOr(asMap(item)['name'], '')
        else if (item != null)
          item.toString(),
    ]..removeWhere((String s) => s.isEmpty);
  }

  static List<Listing> parseList(dynamic value) {
    return <Listing>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final Listing listing) listing,
    ];
  }

  Listing copyWith({bool? isFavorited}) {
    return Listing(
      uuid: uuid,
      slug: slug,
      title: title,
      price: price,
      location: location,
      purpose: purpose,
      condition: condition,
      status: status,
      isVerified: isVerified,
      isFeatured: isFeatured,
      category: category,
      primaryImage: primaryImage,
      attributes: attributes,
      views: views,
      favorites: favorites,
      publishedAt: publishedAt,
      description: description,
      images: images,
      amenities: amenities,
      facilities: facilities,
      seller: seller,
      supportsBoundary: supportsBoundary,
      boundary: boundary,
      availableFrom: availableFrom,
      isFavorited: isFavorited ?? this.isFavorited,
    );
  }
}
