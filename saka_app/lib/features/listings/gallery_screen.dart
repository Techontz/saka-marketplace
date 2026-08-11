import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/widgets/saka_image.dart';
import '../../data/models/media.dart';

/// Full-screen photos, with pinch and double-tap zoom.
///
/// The one place in the app that requests [MediaSize.full]: this is where the
/// pixels are genuinely wanted. Everywhere else asks for a smaller rendition,
/// which is what keeps a scrolling grid off the memory ceiling.
class GalleryScreen extends StatefulWidget {
  const GalleryScreen({
    required this.images,
    required this.initialIndex,
    super.key,
    this.heroPrefix = '',
  });

  final List<MediaImage> images;
  final int initialIndex;
  final String heroPrefix;

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  late final PageController _pages =
      PageController(initialPage: widget.initialIndex);
  late int _index = widget.initialIndex;

  /// One transform controller per page, so zooming one photo and swiping to the
  /// next does not carry the previous zoom across.
  final Map<int, TransformationController> _transforms =
      <int, TransformationController>{};

  @override
  void initState() {
    super.initState();
    // A black gallery with dark status-bar icons is unreadable.
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
        statusBarBrightness: Brightness.dark,
      ),
    );
  }

  @override
  void dispose() {
    _pages.dispose();
    for (final TransformationController c in _transforms.values) {
      c.dispose();
    }
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
      ),
    );
    super.dispose();
  }

  TransformationController _controllerFor(int index) =>
      _transforms.putIfAbsent(index, TransformationController.new);

  /// Double-tap zooms to 2.5× AT THE TAP POINT, not at the centre. Zooming to
  /// the centre is the classic mistake — the user taps a detail and the app
  /// magnifies something else.
  void _doubleTap(int index, TapDownDetails details, Size size) {
    final TransformationController controller = _controllerFor(index);
    final bool isZoomed = controller.value.getMaxScaleOnAxis() > 1.05;

    if (isZoomed) {
      controller.value = Matrix4.identity();
      return;
    }

    const double scale = 2.5;
    final Offset position = details.localPosition;
    controller.value = Matrix4.identity()
      ..translateByDouble(
        -position.dx * (scale - 1),
        -position.dy * (scale - 1),
        0,
        1,
      )
      ..scaleByDouble(scale, scale, scale, 1);
  }

  @override
  Widget build(BuildContext context) {
    final Size size = MediaQuery.sizeOf(context);

    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        children: <Widget>[
          PageView.builder(
            controller: _pages,
            itemCount: widget.images.length,
            onPageChanged: (int index) {
              // Reset the previous page's zoom, so returning to it starts fresh.
              _transforms[_index]?.value = Matrix4.identity();
              setState(() => _index = index);
            },
            itemBuilder: (BuildContext context, int index) {
              final MediaImage image = widget.images[index];
              return GestureDetector(
                onDoubleTapDown: (TapDownDetails details) =>
                    _doubleTap(index, details, size),
                // Present but empty: without an onDoubleTap the framework never
                // dispatches onDoubleTapDown.
                onDoubleTap: () {},
                child: InteractiveViewer(
                  transformationController: _controllerFor(index),
                  minScale: 1,
                  maxScale: 5,
                  // Panning past the edge hands the gesture back to the
                  // PageView, so a zoomed photo still swipes to the next one.
                  panEnabled: true,
                  child: Center(
                    child: Hero(
                      tag: index == widget.initialIndex
                          ? 'gallery-${widget.heroPrefix}-$index'
                          : 'gallery-page-$index',
                      child: SakaImage(
                        image: image,
                        size: MediaSize.full,
                        fit: BoxFit.contain,
                      ),
                    ),
                  ),
                ),
              );
            },
          ),

          Positioned(
            top: MediaQuery.paddingOf(context).top + AppSpacing.sm,
            left: AppSpacing.sm,
            right: AppSpacing.sm,
            child: Row(
              children: <Widget>[
                _GlassButton(
                  icon: Icons.close_rounded,
                  onTap: () => Navigator.of(context).pop(),
                  semanticLabel: 'Close',
                ),
                const Spacer(),
                if (widget.images.length > 1)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.md,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.55),
                      borderRadius: AppRadius.pillAll,
                    ),
                    child: Text(
                      '${_index + 1} / ${widget.images.length}',
                      style: AppTypography.caption.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
              ],
            ),
          ),

          // The thumbnail strip. Uses `thumb`, never `full` — a filmstrip of
          // full-resolution photos would defeat the whole point of the ladder.
          if (widget.images.length > 1)
            Positioned(
              bottom: MediaQuery.paddingOf(context).bottom + AppSpacing.lg,
              left: 0,
              right: 0,
              child: SizedBox(
                height: 58,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.screen,
                  ),
                  itemCount: widget.images.length,
                  separatorBuilder: (_, _) => const SizedBox(width: 8),
                  itemBuilder: (BuildContext context, int index) {
                    final bool isActive = index == _index;
                    return GestureDetector(
                      onTap: () => _pages.animateToPage(
                        index,
                        duration: AppMotion.base,
                        curve: AppMotion.easeOut,
                      ),
                      child: AnimatedContainer(
                        duration: AppMotion.fast,
                        width: 58,
                        decoration: BoxDecoration(
                          borderRadius: AppRadius.smAll,
                          border: Border.all(
                            color: isActive ? Colors.white : Colors.transparent,
                            width: 2,
                          ),
                        ),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(6),
                          child: Opacity(
                            opacity: isActive ? 1 : 0.55,
                            child: SakaImage(
                              image: widget.images[index],
                              size: MediaSize.thumb,
                              fit: BoxFit.cover,
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _GlassButton extends StatelessWidget {
  const _GlassButton({
    required this.icon,
    required this.onTap,
    this.semanticLabel,
  });

  final IconData icon;
  final VoidCallback onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: semanticLabel,
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: Container(
          width: AppSizes.minTouchTarget,
          height: AppSizes.minTouchTarget,
          decoration: BoxDecoration(
            color: Colors.black.withValues(alpha: 0.55),
            shape: BoxShape.circle,
          ),
          child: Icon(icon, size: 21, color: Colors.white),
        ),
      ),
    );
  }
}
