import 'json.dart';
import 'listing.dart';

/// A hospital, school, bus station — the civic layer the marketplace sits on.
class PublicPlace {
  const PublicPlace({
    required this.slug,
    required this.name,
    required this.location,
    this.description,
    this.imageUrl,
    this.categorySlug,
    this.categoryName,
    this.categoryIcon,
    this.phone,
    this.website,
  });

  final String slug;
  final String name;
  final GeoLocation location;
  final String? description;
  final String? imageUrl;
  final String? categorySlug;
  final String? categoryName;
  final String? categoryIcon;
  final String? phone;
  final String? website;

  static PublicPlace? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;
    final Map<String, dynamic> category = asMap(json['category']);
    return PublicPlace(
      slug: slug,
      name: asStringOr(json['name'], slug),
      location: GeoLocation.parse(json['location']),
      description: asString(json['description']),
      imageUrl: asString(json['image_url']),
      categorySlug: asString(category['slug']),
      categoryName: asString(category['name']),
      categoryIcon: asString(category['icon']),
      phone: asString(json['phone']),
      website: asString(json['website']),
    );
  }

  static List<PublicPlace> parseList(dynamic value) {
    return <PublicPlace>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final PublicPlace place) place,
    ];
  }
}

class Review {
  const Review({
    required this.uuid,
    required this.rating,
    this.title,
    this.body,
    this.reviewerName,
    this.replyBody,
    this.repliedAt,
    this.helpfulCount = 0,
    this.listingSlug,
    this.listingTitle,
    this.createdAt,
  });

  final String uuid;
  final int rating;
  final String? title;
  final String? body;
  final String? reviewerName;
  final String? replyBody;
  final DateTime? repliedAt;
  final int helpfulCount;
  final String? listingSlug;
  final String? listingTitle;
  final DateTime? createdAt;

  bool get hasReply => replyBody != null && replyBody!.isNotEmpty;

  static Review? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;

    final Map<String, dynamic> reviewer = asMap(json['reviewer']);
    final Map<String, dynamic> reply = asMap(json['reply']);
    final Map<String, dynamic> listing = asMap(json['listing']);

    return Review(
      uuid: uuid,
      rating: asIntOr(json['rating'], 0),
      title: asString(json['title']),
      body: asString(json['body']),
      reviewerName: asString(reviewer['name']),
      replyBody: asString(reply['body']),
      repliedAt: asDate(reply['replied_at']),
      helpfulCount: asIntOr(json['helpful_count'], 0),
      listingSlug: asString(listing['slug']),
      listingTitle: asString(listing['title']),
      createdAt: asDate(json['created_at']),
    );
  }

  static List<Review> parseList(dynamic value) {
    return <Review>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final Review review) review,
    ];
  }
}

/// An in-app notification. `data` is a free-form payload whose shape depends on
/// [type]; only the keys the UI actually reads are pulled out of it.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.isRead,
    this.title,
    this.body,
    this.listingSlug,
    this.readAt,
    this.createdAt,
  });

  final String id;
  final String type;
  final bool isRead;
  final String? title;
  final String? body;

  /// Where tapping should go, when the payload names a target.
  final String? listingSlug;

  final DateTime? readAt;
  final DateTime? createdAt;

  static AppNotification? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? id = asString(json['id']);
    if (id == null) return null;

    final Map<String, dynamic> data = asMap(json['data']);

    return AppNotification(
      id: id,
      // The class name Laravel stores is namespaced; only the tail is useful.
      type: asStringOr(json['type'], 'notification').split('\\').last,
      isRead: asBool(json['read']),
      title: asString(data['title']) ?? asString(data['subject']),
      body: asString(data['message']) ?? asString(data['body']),
      listingSlug: asString(data['listing_slug']) ?? asString(data['slug']),
      readAt: asDate(json['read_at']),
      createdAt: asDate(json['created_at']),
    );
  }

  static List<AppNotification> parseList(dynamic value) {
    return <AppNotification>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final AppNotification n) n,
    ];
  }
}

/// A region, district or ward, as returned by the location endpoints.
class LocationOption {
  const LocationOption({
    required this.slug,
    required this.name,
    this.listingCount = 0,
    this.latitude,
    this.longitude,
    this.parentName,
    this.type = 'region',
  });

  final String slug;
  final String name;
  final int listingCount;
  final double? latitude;
  final double? longitude;
  final String? parentName;

  /// `region` | `district` | `ward`.
  final String type;

  static LocationOption? tryParse(dynamic value, {String type = 'region'}) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;
    return LocationOption(
      slug: slug,
      name: asStringOr(json['name'], slug),
      listingCount: asIntOr(json['listing_count'], 0),
      latitude: asDouble(json['latitude']),
      longitude: asDouble(json['longitude']),
      parentName: asString(json['region']) ?? asString(json['district']),
      type: asStringOr(json['type'], type),
    );
  }

  static List<LocationOption> parseList(dynamic value, {String type = 'region'}) {
    return <LocationOption>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item, type: type) case final LocationOption option) option,
    ];
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
        'slug': slug,
        'name': name,
        'listing_count': listingCount,
        'latitude': latitude,
        'longitude': longitude,
        'type': type,
      };
}

/// One row of `GET /search/suggestions`, which returns four typed buckets.
class SearchSuggestion {
  const SearchSuggestion({
    required this.type,
    required this.label,
    required this.slug,
  });

  /// `listing` | `business` | `category` | `place`.
  final String type;

  final String label;
  final String slug;

  static List<SearchSuggestion> parseAll(dynamic body) {
    final Map<String, dynamic> data = asMap(asMap(body)['data']);
    final List<SearchSuggestion> out = <SearchSuggestion>[];
    // Ordered by usefulness to somebody typing, not alphabetically: a category
    // match is a better answer than the fourth listing whose title contains the
    // same word.
    for (final String bucket in <String>[
      'categories',
      'listings',
      'businesses',
      'places',
    ]) {
      for (final Map<String, dynamic> item in asMapList(data[bucket])) {
        final String? slug = asString(item['slug']);
        final String? label = asString(item['label']);
        if (slug == null || label == null) continue;
        out.add(
          SearchSuggestion(
            type: asStringOr(item['type'], bucket),
            label: label,
            slug: slug,
          ),
        );
      }
    }
    return out;
  }
}

/// An ad creative returned by `GET /ads`.
class AdCreative {
  const AdCreative({
    required this.uuid,
    this.headline,
    this.body,
    this.imageUrl,
    this.mobileImageUrl,
    this.ctaLabel,
    this.advertiserName,
  });

  final String uuid;
  final String? headline;
  final String? body;
  final String? imageUrl;
  final String? mobileImageUrl;
  final String? ctaLabel;
  final String? advertiserName;

  /// Prefer the mobile artwork when the advertiser supplied one — its aspect
  /// ratio is the one designed for a phone.
  String? get displayImage => mobileImageUrl ?? imageUrl;

  static AdCreative? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;
    final Map<String, dynamic> advertiser = asMap(json['advertiser']);
    return AdCreative(
      uuid: uuid,
      headline: asString(json['headline']),
      body: asString(json['body']),
      imageUrl: asString(json['image_url']),
      mobileImageUrl: asString(json['mobile_image_url']),
      ctaLabel: asString(json['cta_label']),
      advertiserName: asString(advertiser['name']),
    );
  }

  static List<AdCreative> parseList(dynamic value) {
    return <AdCreative>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final AdCreative ad) ad,
    ];
  }
}
