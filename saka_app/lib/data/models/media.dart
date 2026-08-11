import 'json.dart';

/// A photo, and the sizes the backend generated for it.
///
/// The backend's `GenerateImageVariants` job produces WebP renditions named
/// `thumb`, `card`, `detail` and `full`. Picking the right one is the single
/// biggest lever on this app's memory and data use: a listing grid that renders
/// the original 1200×800 JPEG in a 180pt box decodes ~4 MB of bitmap per card.
class MediaImage {
  const MediaImage({
    required this.url,
    required this.variants,
    this.uuid,
    this.altText,
    this.width,
    this.height,
    this.isPrimary = false,
  });

  final String url;

  /// variant name → url. Empty when the job has not run, or when the row is
  /// demo media pointing at an external host.
  final Map<String, String> variants;

  final String? uuid;
  final String? altText;
  final int? width;
  final int? height;
  final bool isPrimary;

  static MediaImage? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? url = asString(json['url']);
    if (url == null) return null;

    // `variants` arrives as `{}` when populated and as `[]` when empty — PHP
    // serialises an empty associative array as a JSON list. asMap absorbs both.
    final Map<String, dynamic> rawVariants = asMap(json['variants']);
    final Map<String, String> variants = <String, String>{};
    rawVariants.forEach((String key, dynamic entry) {
      // Each variant is `{path, url, width, height}`; older rows are a bare
      // string. Both appear in the seeded data.
      final String? variantUrl =
          entry is Map ? asString(asMap(entry)['url']) : asString(entry);
      if (variantUrl != null) variants[key] = variantUrl;
    });

    return MediaImage(
      url: url,
      variants: variants,
      uuid: asString(json['uuid']),
      altText: asString(json['alt_text']),
      width: asInt(json['width']),
      height: asInt(json['height']),
      isPrimary: asBool(json['is_primary']),
    );
  }

  static List<MediaImage> parseList(dynamic value) {
    return <MediaImage>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final MediaImage image) image,
    ];
  }

  /// The URL for a given intent, falling back DOWN the ladder then to the
  /// original.
  ///
  /// Falling back downwards is deliberate: if `card` is missing it is better to
  /// serve a slightly soft `thumb` than a 4 MB original into a grid cell. The
  /// original is the last resort, not the first.
  String srcFor(MediaSize size) {
    for (final String name in size._ladder) {
      final String? hit = variants[name];
      if (hit != null && hit.isNotEmpty) return hit;
    }
    return url;
  }

  double? get aspectRatio {
    final int? w = width;
    final int? h = height;
    if (w == null || h == null || h == 0) return null;
    return w / h;
  }
}

/// Which rendition a surface should ask for.
enum MediaSize {
  /// Avatars, chips, the gallery's thumbnail strip.
  thumb(<String>['thumb', 'card', 'detail']),

  /// Grid and rail cards. The workhorse.
  card(<String>['card', 'detail', 'thumb']),

  /// The listing hero, before the user opens the lightbox.
  detail(<String>['detail', 'full', 'card']),

  /// Pinch-zoom in the lightbox, where the pixels are actually wanted.
  full(<String>['full', 'detail', 'card']);

  const MediaSize(this._ladder);

  final List<String> _ladder;
}
