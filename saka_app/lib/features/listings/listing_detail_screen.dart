import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/config/app_config.dart';
import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/favorite_button.dart';
import '../../core/widgets/listing_card.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_image.dart';
import '../../core/widgets/states.dart';
import '../../data/models/listing.dart';
import '../../data/models/media.dart';
import '../../data/repositories/directory_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../auth/sign_in_sheet.dart';
import '../favorites/favorites_controller.dart';
import 'gallery_screen.dart';
import 'widgets/contact_sheet.dart';
import 'widgets/detail_sections.dart';
import 'widgets/specialist_services_block.dart';

/// A listing, in full.
///
/// Opens with whatever the calling card already knew — title, price, photo —
/// so the screen is READABLE in the first frame and the Hero has something to
/// fly into. The detail request fills in description, attributes, seller and
/// the rest of the gallery underneath. Waiting for the round trip before
/// drawing anything would make every tap feel like a page load.
class ListingDetailScreen extends StatefulWidget {
  const ListingDetailScreen({super.key});

  @override
  State<ListingDetailScreen> createState() => _ListingDetailScreenState();
}

class _ListingDetailScreenState extends State<ListingDetailScreen> {
  final ScrollController _scroll = ScrollController();

  Listing? _listing;
  List<Listing> _similar = const <Listing>[];
  ApiException? _error;
  bool _loading = true;
  String _heroPrefix = '';
  late String _slug;

  /// Drives the app bar's fade from transparent-over-photo to solid.
  double _scrollOffset = 0;

  @override
  void initState() {
    super.initState();

    final Object? args = Get.arguments;
    if (args is Map<String, dynamic>) {
      _listing = args['listing'] as Listing?;
      _heroPrefix = (args['heroPrefix'] as String?) ?? '';
    }
    _slug = _listing?.slug ??
        (Get.parameters['slug'] ?? '');

    _scroll.addListener(() {
      final double next = _scroll.hasClients ? _scroll.offset : 0;
      // Rebuild only while the bar is actually changing. Past the threshold the
      // bar is solid and further scrolling must not rebuild it.
      if ((next < 220 || _scrollOffset < 220) &&
          (next - _scrollOffset).abs() > 4) {
        setState(() => _scrollOffset = next);
      }
    });

    _load();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (_slug.isEmpty) {
      setState(() {
        _loading = false;
        _error = const ApiException(
          kind: ApiErrorKind.notFound,
          message: 'This listing could not be opened.',
        );
      });
      return;
    }

    final ListingRepository repository = Get.find<ListingRepository>();

    try {
      final Listing detail = await repository.detail(_slug);
      if (!mounted) return;

      // Seed the favourites set from `meta.is_favorited`, so a listing opened
      // from a deep link shows the correct heart before any sync has run.
      if (detail.isFavorited != null) {
        Get.find<FavoritesController>()
            .seed(detail.slug, isSaved: detail.isFavorited!);
      }

      setState(() {
        _listing = detail;
        _loading = false;
      });

      // Similar listings load AFTER the detail, not alongside it: they sit
      // below the fold and racing them would compete for bandwidth with the
      // photographs the user is waiting to see.
      final List<Listing> similar = await repository.similar(_slug);
      if (mounted) setState(() => _similar = similar);
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        // A failure with a card already on screen keeps the card and says
        // nothing — the user can still read the price and the photo.
        if (_listing == null) _error = ApiException.from(error);
      });
    }
  }

  void _openGallery(int index) {
    final List<MediaImage> images = _images;
    if (images.isEmpty) return;
    Get.to<void>(
      () => GalleryScreen(
        images: images,
        initialIndex: index,
        heroPrefix: _heroPrefix,
      ),
      opaque: false,
      transition: Transition.fadeIn,
      duration: AppMotion.base,
    );
  }

  /// True when this listing lives in the specialists vertical — checked
  /// against the category's own lineage, not against the title.
  bool get _isSpecialist {
    final CategoryRef? category = _listing?.category;
    if (category == null) return false;
    return category.slug == DirectoryRepository.specialistsCategory ||
        category.parentSlug == DirectoryRepository.specialistsCategory;
  }

  List<MediaImage> get _images {
    final Listing? listing = _listing;
    if (listing == null) return const <MediaImage>[];
    if (listing.images.isNotEmpty) return listing.images;
    final MediaImage? primary = listing.displayImage;
    return primary == null ? const <MediaImage>[] : <MediaImage>[primary];
  }

  Future<void> _share() async {
    final Listing? listing = _listing;
    if (listing == null) return;
    // Shares the WEB url, not a custom scheme: a recipient without the app must
    // land on the real page rather than on nothing.
    await Share.share(
      '${listing.title}\n${AppConfig.webOrigin}/listings/${listing.slug}',
      subject: listing.title,
    );
  }

  @override
  Widget build(BuildContext context) {
    final Listing? listing = _listing;

    if (listing == null) {
      return Scaffold(
        appBar: AppBar(),
        body: _error != null
            ? SakaErrorState(error: _error!, onRetry: _load)
            : const Center(child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }

    final double fade = (_scrollOffset / 200).clamp(0.0, 1.0);

    return Scaffold(
      backgroundColor: AppColors.page,
      extendBodyBehindAppBar: true,
      appBar: _DetailAppBar(
        fade: fade,
        title: listing.title,
        slug: listing.slug,
        onShare: _share,
      ),
      body: CustomScrollView(
        controller: _scroll,
        physics: const BouncingScrollPhysics(),
        slivers: <Widget>[
          SliverToBoxAdapter(
            child: _HeroGallery(
              images: _images,
              heroPrefix: _heroPrefix,
              slug: listing.slug,
              onTap: _openGallery,
            ),
          ),
          SliverToBoxAdapter(
            child: ListingSummary(listing: listing, isLoading: _loading),
          ),
          // Mounted only for the specialists vertical, so no other listing
          // makes a services request that would 404.
          if (_isSpecialist)
            SliverToBoxAdapter(
              child: SpecialistServicesBlock(
                slug: listing.slug,
                specialistName: listing.title,
              ),
            ),
          if (listing.attributes.isNotEmpty)
            SliverToBoxAdapter(
              child: AttributeGrid(attributes: listing.attributes),
            ),
          if (listing.description != null)
            SliverToBoxAdapter(
              child: DescriptionBlock(text: listing.description!),
            ),
          if (listing.amenities.isNotEmpty)
            SliverToBoxAdapter(
              child: ChipBlock(title: 'Amenities', items: listing.amenities),
            ),
          if (listing.facilities.isNotEmpty)
            SliverToBoxAdapter(
              child: ChipBlock(title: 'Facilities', items: listing.facilities),
            ),
          if (listing.location.hasCoordinates)
            SliverToBoxAdapter(
              child: LocationBlock(
                location: listing.location,
                supportsBoundary: listing.supportsBoundary,
                boundary: listing.boundary,
                title: listing.title,
              ),
            ),
          if (listing.seller != null)
            SliverToBoxAdapter(child: SellerBlock(seller: listing.seller!)),
          if (_similar.isNotEmpty)
            SliverToBoxAdapter(
              child: _SimilarRail(listings: _similar, slug: listing.slug),
            ),
          const SliverPadding(
            padding: EdgeInsets.only(bottom: 120),
          ),
        ],
      ),
      bottomNavigationBar: _ContactBar(listing: listing),
    );
  }
}

/// A bar that starts invisible over the photograph and fades to solid.
class _DetailAppBar extends StatelessWidget implements PreferredSizeWidget {
  const _DetailAppBar({
    required this.fade,
    required this.title,
    required this.slug,
    required this.onShare,
  });

  final double fade;
  final String title;
  final String slug;
  final VoidCallback onShare;

  @override
  Size get preferredSize => const Size.fromHeight(AppSizes.appBarHeight);

  @override
  Widget build(BuildContext context) {
    final bool solid = fade > 0.5;

    return AppBar(
      backgroundColor: AppColors.background.withValues(alpha: fade),
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      // The title appears only once the bar is opaque; over the photo it would
      // be unreadable and redundant with the title just below.
      title: AnimatedOpacity(
        opacity: solid ? 1 : 0,
        duration: AppMotion.fast,
        child: Text(
          title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.label,
        ),
      ),
      leading: Padding(
        padding: const EdgeInsets.only(left: AppSpacing.sm),
        child: _RoundButton(
          icon: Icons.arrow_back_rounded,
          solid: solid,
          onTap: () => Navigator.of(context).pop(),
          semanticLabel: 'Back',
        ),
      ),
      leadingWidth: 56,
      actions: <Widget>[
        _RoundButton(
          icon: Icons.ios_share_rounded,
          solid: solid,
          onTap: onShare,
          semanticLabel: 'Share',
        ),
        const SizedBox(width: AppSpacing.xs),
        Padding(
          padding: const EdgeInsets.only(right: AppSpacing.sm),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: solid
                  ? Colors.transparent
                  : Colors.white.withValues(alpha: 0.92),
              shape: BoxShape.circle,
            ),
            child: FavoriteButton(
              slug: slug,
              size: 20,
              onAuthRequired: () => SignInSheet.show(
                context,
                reason: 'Sign in to save this listing.',
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _RoundButton extends StatelessWidget {
  const _RoundButton({
    required this.icon,
    required this.solid,
    required this.onTap,
    this.semanticLabel,
  });

  final IconData icon;
  final bool solid;
  final VoidCallback onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: semanticLabel,
      child: Container(
        width: 38,
        height: 38,
        margin: const EdgeInsets.symmetric(vertical: 9),
        decoration: BoxDecoration(
          color: solid
              ? Colors.transparent
              : Colors.white.withValues(alpha: 0.92),
          shape: BoxShape.circle,
          boxShadow: solid ? null : AppShadows.floating,
        ),
        child: Icon(icon, size: 19, color: AppColors.navy),
      ),
    );
  }
}

/// The photograph, and the counter that says how many more there are.
class _HeroGallery extends StatefulWidget {
  const _HeroGallery({
    required this.images,
    required this.heroPrefix,
    required this.slug,
    required this.onTap,
  });

  final List<MediaImage> images;
  final String heroPrefix;
  final String slug;
  final ValueChanged<int> onTap;

  @override
  State<_HeroGallery> createState() => _HeroGalleryState();
}

class _HeroGalleryState extends State<_HeroGallery> {
  final PageController _pages = PageController();
  int _index = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.images.isEmpty) {
      return const AspectRatio(
        aspectRatio: AppSizes.heroImageAspect,
        child: ColoredBox(color: AppColors.muted),
      );
    }

    return AspectRatio(
      aspectRatio: AppSizes.heroImageAspect,
      child: Stack(
        fit: StackFit.expand,
        children: <Widget>[
          PageView.builder(
            controller: _pages,
            itemCount: widget.images.length,
            onPageChanged: (int i) => setState(() => _index = i),
            itemBuilder: (BuildContext context, int index) {
              final Widget image = SakaImage(
                image: widget.images[index],
                size: MediaSize.detail,
                fit: BoxFit.cover,
              );

              return GestureDetector(
                onTap: () => widget.onTap(index),
                // Only the FIRST page carries the Hero tag — it is the one the
                // card flew from, and two Heroes with the same tag on one route
                // throws.
                child: index == 0
                    ? Hero(
                        tag: 'listing-${widget.heroPrefix}-${widget.slug}',
                        child: image,
                      )
                    : image,
              );
            },
          ),
          if (widget.images.length > 1)
            Positioned(
              right: AppSpacing.md,
              bottom: AppSpacing.md,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md,
                  vertical: 5,
                ),
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: 0.6),
                  borderRadius: AppRadius.pillAll,
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const Icon(
                      Icons.photo_library_outlined,
                      size: 13,
                      color: Colors.white,
                    ),
                    const SizedBox(width: 5),
                    Text(
                      '${_index + 1} / ${widget.images.length}',
                      style: AppTypography.caption.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 11.5,
                      ),
                    ),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// The persistent call to action.
///
/// Pinned to the bottom rather than placed in the page, because the whole
/// purpose of a listing screen is to start a conversation with the seller, and
/// making the user scroll back up to do it loses the enquiry.
class _ContactBar extends StatelessWidget {
  const _ContactBar({required this.listing});

  final Listing listing;

  @override
  Widget build(BuildContext context) {
    final SellerRef? seller = listing.seller;

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.background,
        border: Border(top: AppBorders.hairline),
      ),
      padding: EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.md,
        AppSpacing.screen,
        AppSpacing.md + MediaQuery.paddingOf(context).bottom,
      ),
      child: Row(
        children: <Widget>[
          // Flex left at the default: widening this column narrowed the button
          // enough to wrap "Contact seller" onto two lines. The FittedBox below
          // is what keeps the price whole, so the extra width was not needed.
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                // Scaled down, never ellipsised.
                //
                // This read "TZS 118,..." on a real 360pt phone: the price
                // column had flex 1 against the button's 2, which left it
                // about 87pt for a nine-figure number and a "/ month" unit.
                // The price is the one string on this bar that must survive
                // intact — a buyer cannot act on a truncated one, and cutting
                // the digits is worse than cutting the type size.
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    Fmt.price(listing.price),
                    maxLines: 1,
                    style: AppTypography.price.copyWith(fontSize: 18),
                  ),
                ),
                if (listing.price.isNegotiable)
                  Text('Negotiable', style: AppTypography.caption),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.md),

          // Call, only when the API actually supplied a number. A dead phone
          // button is worse than no phone button.
          if (seller?.phone != null) ...<Widget>[
            _CircleAction(
              icon: Icons.phone_rounded,
              color: AppColors.teal,
              semanticLabel: 'Call seller',
              onTap: () => launchUrl(Uri.parse('tel:${seller!.phone}')),
            ),
            const SizedBox(width: AppSpacing.sm),
          ],

          Expanded(
            flex: 2,
            child: ElevatedButton(
              onPressed: () => ContactSheet.show(context, listing: listing),
              style: ElevatedButton.styleFrom(
                minimumSize: const Size.fromHeight(AppSizes.minTouchTarget),
              ),
              child: const Text('Contact seller'),
            ),
          ),
        ],
      ),
    );
  }
}

class _CircleAction extends StatelessWidget {
  const _CircleAction({
    required this.icon,
    required this.color,
    required this.onTap,
    this.semanticLabel,
  });

  final IconData icon;
  final Color color;
  final VoidCallback onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: semanticLabel,
      child: Container(
        width: AppSizes.minTouchTarget,
        height: AppSizes.minTouchTarget,
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.10),
          borderRadius: AppRadius.mdAll,
        ),
        child: Icon(icon, size: 20, color: color),
      ),
    );
  }
}

class _SimilarRail extends StatelessWidget {
  const _SimilarRail({required this.listings, required this.slug});

  final List<Listing> listings;
  final String slug;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.screen,
            AppSpacing.xxl,
            AppSpacing.screen,
            AppSpacing.md,
          ),
          child: Text('Similar listings', style: AppTypography.section),
        ),
        SizedBox(
          height: ListingCard.railHeight(context),
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.screen),
            physics: const BouncingScrollPhysics(),
            itemCount: listings.length,
            separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final Listing item = listings[index];
              return ListingCard(
                listing: item,
                layout: ListingCardLayout.rail,
                heroPrefix: 'similar-$slug',
                onTap: () => Get.toNamed<void>(
                  Routes.listingPath(item.slug),
                  arguments: <String, dynamic>{
                    'listing': item,
                    'heroPrefix': 'similar-$slug',
                  },
                  // A fresh route each time, so tapping through similar
                  // listings builds a back stack the user can walk out of.
                  preventDuplicates: false,
                ),
                onAuthRequired: () => SignInSheet.show(context),
              );
            },
          ),
        ),
      ],
    );
  }
}
