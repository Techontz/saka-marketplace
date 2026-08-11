import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../data/models/media.dart';

/// Every remote image in this app.
///
/// Three things happen here that decide whether the app feels fast or feels
/// like a phone running out of memory:
///
///  1. it asks the backend for the right VARIANT (`card` in a grid, `full` in
///     the lightbox) instead of the original;
///  2. it caps the DECODED size with `memCacheWidth`, so a 1200px photo drawn
///     in a 180pt box costs ~0.5 MB of bitmap instead of ~4 MB;
///  3. it fades in from a flat placeholder rather than popping, which is what
///     makes a scrolling grid look composed rather than assembled.
class SakaImage extends StatelessWidget {
  const SakaImage({
    required this.image,
    required this.size,
    super.key,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.borderRadius,
    this.semanticLabel,
  }) : _rawUrl = null;

  /// Convenience for the handful of places that hold a bare URL — an advertiser
  /// creative, a business logo — where there is no variant ladder to consult.
  const SakaImage.url({
    required String? url,
    required this.size,
    super.key,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.borderRadius,
    this.semanticLabel,
  }) : image = null,
       _rawUrl = url;

  final MediaImage? image;
  final MediaSize size;
  final double? width;
  final double? height;
  final BoxFit fit;
  final BorderRadius? borderRadius;
  final String? semanticLabel;

  final String? _rawUrl;

  String? get _url {
    if (_rawUrl != null) return _rawUrl;
    return image?.srcFor(size);
  }

  /// Decode budget, in logical pixels, per intent.
  ///
  /// Multiplied by devicePixelRatio at build time so a 3x phone still gets a
  /// sharp image — the point is to stop decoding a 4000px original into a
  /// thumbnail, not to render everything soft.
  double get _decodeWidth => switch (size) {
        MediaSize.thumb => 160,
        MediaSize.card => 420,
        MediaSize.detail => 900,
        MediaSize.full => 1600,
      };

  @override
  Widget build(BuildContext context) {
    final String? url = _url;
    final BorderRadius radius = borderRadius ?? BorderRadius.zero;

    if (url == null || url.isEmpty) {
      return _Placeholder(
        width: width,
        height: height,
        borderRadius: radius,
        isError: true,
      );
    }

    final double dpr = MediaQuery.devicePixelRatioOf(context);
    // Capped at 3x: beyond that the extra pixels are invisible and the memory
    // is not.
    final int cacheWidth = (_decodeWidth * dpr.clamp(1.0, 3.0)).round();

    final Widget picture = CachedNetworkImage(
      imageUrl: url,
      width: width,
      height: height,
      fit: fit,
      memCacheWidth: cacheWidth,
      // 180ms: long enough to read as a fade, short enough that a fast
      // connection still feels instant.
      fadeInDuration: AppMotion.fast,
      fadeOutDuration: Duration.zero,
      placeholder: (BuildContext _, String _) => _Placeholder(
        width: width,
        height: height,
        borderRadius: BorderRadius.zero,
      ),
      errorWidget: (BuildContext _, String _, Object _) => _Placeholder(
        width: width,
        height: height,
        borderRadius: BorderRadius.zero,
        isError: true,
      ),
    );

    final Widget clipped = radius == BorderRadius.zero
        ? picture
        : ClipRRect(borderRadius: radius, child: picture);

    final String? label = semanticLabel ?? image?.altText;
    if (label == null) {
      // Decorative to a screen reader: a photo with no alt text adds nothing
      // but noise to the reading order.
      return ExcludeSemantics(child: clipped);
    }

    return Semantics(image: true, label: label, child: clipped);
  }
}

/// The state before and instead of a photo.
///
/// A flat tinted block, not a spinner. A spinner inside a 180pt card draws the
/// eye to the thing that is missing; a soft block simply holds the shape until
/// the picture arrives.
class _Placeholder extends StatelessWidget {
  const _Placeholder({
    this.width,
    this.height,
    this.borderRadius = BorderRadius.zero,
    this.isError = false,
  });

  final double? width;
  final double? height;
  final BorderRadius borderRadius;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: AppColors.shimmerBase,
        borderRadius: borderRadius,
      ),
      alignment: Alignment.center,
      child: isError
          ? const Icon(
              Icons.image_not_supported_outlined,
              size: 22,
              color: AppColors.border,
            )
          : null,
    );
  }
}
