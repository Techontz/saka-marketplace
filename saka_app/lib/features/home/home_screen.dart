import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/storage/cache_store.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/saka_logo.dart';
import '../../data/models/category.dart';
import '../../data/models/business.dart';
import '../../data/models/listing.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/ads_repository.dart';
import '../../data/repositories/catalog_repository.dart';
import '../../data/repositories/directory_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../auth/auth_controller.dart';
import '../auth/sign_in_sheet.dart';
import '../listings/listings_screen.dart';
import '../location/location_controller.dart';
import '../location/location_sheet.dart';
import '../search/search_screen.dart';
import 'home_controller.dart';
import 'widgets/ad_strip.dart';
import 'widgets/business_rail.dart';
import 'widgets/category_strip.dart';
import 'widgets/hero_listing_card.dart';
import 'widgets/listing_rail.dart';
import 'widgets/place_rail.dart';

/// Home.
///
/// A single CustomScrollView of slivers rather than a Column of scrollables:
/// one viewport, one scroll position, and every rail below the fold is built
/// lazily as it approaches. A nested-ListView layout would build all of them
/// eagerly and decode every image on the page before the first frame.
class HomeScreen extends StatefulWidget {
  const HomeScreen({required this.scrollController, super.key});

  final ScrollController scrollController;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen>
    with AutomaticKeepAliveClientMixin {
  late final HomeController _controller;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    // Created here rather than in a binding: Home lives for the life of the
    // shell, so tying its controller to a route that is never popped would
    // leave GetX holding it under a stale tag.
    _controller = Get.put(
      HomeController(
        catalog: Get.find<CatalogRepository>(),
        directory: Get.find<DirectoryRepository>(),
        listings: Get.find<ListingRepository>(),
        ads: Get.find<AdsRepository>(),
        location: Get.find<LocationController>(),
        cache: Get.find<CacheStore>(),
      ),
      permanent: true,
    );

    // The location invitation, once, and only after the first frame — a modal
    // during the opening paint would compete with the content appearing.
    WidgetsBinding.instance.addPostFrameCallback((_) => _maybeAskLocation());
  }

  Future<void> _maybeAskLocation() async {
    if (!mounted) return;
    final LocationController location = Get.find<LocationController>();
    if (location.hasChoice || location.hasBeenPrompted) return;
    await location.markPrompted();
    if (!mounted) return;
    await LocationSheet.show(context, isInvitation: true);
  }

  void _openListing(Listing listing, String railKey) {
    Get.toNamed<void>(
      Routes.listingPath(listing.slug),
      arguments: <String, dynamic>{
        'listing': listing,
        'heroPrefix': railKey,
      },
    );
  }

  void _requireAuth() => SignInSheet.show(context);

  @override
  Widget build(BuildContext context) {
    super.build(context);

    return Scaffold(
      backgroundColor: AppColors.page,
      body: RefreshIndicator(
        onRefresh: _controller.loadAll,
        color: AppColors.primary,
        backgroundColor: AppColors.background,
        edgeOffset: MediaQuery.paddingOf(context).top + 60,
        child: CustomScrollView(
          controller: widget.scrollController,
          // Always scrollable so pull-to-refresh works even when the content is
          // shorter than the viewport — which is exactly the state a user most
          // wants to refresh out of.
          physics: const AlwaysScrollableScrollPhysics(
            parent: BouncingScrollPhysics(),
          ),
          slivers: <Widget>[
            const _HomeAppBar(),
            const SliverToBoxAdapter(child: _SearchEntry()),

            // Categories. Cached, so this is populated in the first frame.
            SliverToBoxAdapter(
              child: Obx(() {
                final List<Category> categories = _controller.topCategories;
                if (categories.isEmpty && _controller.isColdStart.value) {
                  return const CategoryStripSkeleton();
                }
                if (categories.isEmpty) return const SizedBox.shrink();
                return CategoryStrip(
                  categories: categories,
                  onTap: (Category category) => Get.to<void>(
                    () => ListingsScreen(
                      initialQuery:
                          ListingQuery(categorySlug: category.slug),
                      title: category.name,
                    ),
                  ),
                );
              }),
            ),

            // The editorial slot: the single strongest featured listing,
            // full width. Sits above the rails so the feed opens with one
            // photograph rather than a row of thumbnails.
            SliverToBoxAdapter(
              child: Obx(() {
                final RailState state = _controller.featured.value;
                final Listing? lead =
                    state.items.isEmpty ? null : state.items.first;
                if (lead == null) return const SizedBox.shrink();
                return HeroListingCard(
                  listing: lead,
                  onTap: () => _openListing(lead, 'hero'),
                  onAuthRequired: _requireAuth,
                );
              }),
            ),

            // SAKA's own campaigns. Renders nothing at all when none is
            // eligible — never a reserved grey box.
            SliverToBoxAdapter(
              child: Obx(
                () => AdStrip(
                  creatives: _controller.ads.toList(growable: false),
                  placement: AdPlacements.homepageStrip,
                ),
              ),
            ),

            SliverToBoxAdapter(
              child: Obx(
                () => ListingRail(
                  title: 'Featured',
                  subtitle: 'Hand-picked by the SAKA team',
                  state: _controller.featured.value,
                  railKey: 'featured',
                  onTapListing: _openListing,
                  onAuthRequired: _requireAuth,
                  onSeeAll: () => Get.to<void>(
                    () => const ListingsScreen(
                      initialQuery: ListingQuery(featuredOnly: true),
                      title: 'Featured',
                    ),
                  ),
                ),
              ),
            ),

            SliverToBoxAdapter(
              child: Obx(() {
                final LocationController location =
                    Get.find<LocationController>();
                return ListingRail(
                  title: location.hasChoice
                      ? 'In ${location.region!.name}'
                      : 'Latest listings',
                  subtitle: location.hasChoice
                      ? 'Recently listed near you'
                      : 'Newest across Tanzania',
                  state: _controller.nearby.value,
                  railKey: 'nearby',
                  onTapListing: _openListing,
                  onAuthRequired: _requireAuth,
                  onSeeAll: () => Get.to<void>(
                    () => ListingsScreen(
                      initialQuery: ListingQuery(
                        regionSlug: location.regionSlug,
                        districtSlug: location.districtSlug,
                        sort: 'newest',
                      ),
                      title: location.hasChoice
                          ? location.region!.name
                          : 'Latest listings',
                    ),
                  ),
                );
              }),
            ),

            SliverToBoxAdapter(
              child: Obx(
                () => ListingRail(
                  title: 'Trending',
                  subtitle: 'What people are viewing this week',
                  state: _controller.trending.value,
                  railKey: 'trending',
                  onTapListing: _openListing,
                  onAuthRequired: _requireAuth,
                  onSeeAll: () => Get.to<void>(
                    () => const ListingsScreen(
                      initialQuery: ListingQuery(sort: 'popularity'),
                      title: 'Trending',
                    ),
                  ),
                ),
              ),
            ),

            SliverToBoxAdapter(
              child: Obx(
                () => ListingRail(
                  title: 'Newly posted',
                  subtitle: 'The most recent listings on SAKA',
                  state: _controller.newest.value,
                  railKey: 'newest',
                  onTapListing: _openListing,
                  onAuthRequired: _requireAuth,
                  onSeeAll: () => Get.to<void>(
                    () => const ListingsScreen(
                      initialQuery: ListingQuery(sort: 'newest'),
                      title: 'Newly posted',
                    ),
                  ),
                ),
              ),
            ),

            SliverToBoxAdapter(
              child: Obx(
                () => ListingRail(
                  title: 'Specialists',
                  subtitle: 'Book a professional near you',
                  state: _controller.specialists.value,
                  railKey: 'specialists',
                  onTapListing: _openListing,
                  onAuthRequired: _requireAuth,
                  onSeeAll: () => Get.to<void>(
                    () => const ListingsScreen(
                      initialQuery: ListingQuery(
                        categorySlug: DirectoryRepository.specialistsCategory,
                      ),
                      title: 'Specialists',
                    ),
                  ),
                ),
              ),
            ),

            // The directory. A different card shape on purpose — see
            // BusinessRail. Hidden entirely when the API returns none.
            SliverToBoxAdapter(
              child: Obx(
                () => BusinessRail(
                  businesses: _controller.businesses.toList(growable: false),
                  // Named routes, not Get.to: the detail screen reads its
                  // slug from Get.parameters, and a deep link into it must
                  // land on the same screen as a tap here.
                  onTapBusiness: (Business business) =>
                      Get.toNamed<void>(Routes.businessPath(business.slug)),
                  onSeeAll: () => Get.toNamed<void>(Routes.businesses),
                ),
              ),
            ),

            SliverToBoxAdapter(
              child: Obx(
                () => PlaceRail(
                  places: _controller.places.toList(growable: false),
                  onTapPlace: (PublicPlace place) =>
                      Get.toNamed<void>(Routes.placePath(place.slug)),
                  onSeeAll: () => Get.toNamed<void>(Routes.places),
                ),
              ),
            ),

            const SliverToBoxAdapter(child: _DiscoverRow()),

            // Clears the bottom navigation bar.
            const SliverPadding(
              padding: EdgeInsets.only(bottom: AppSpacing.xxxl),
            ),
          ],
        ),
      ),
    );
  }
}

/// Logo, location, notifications.
///
/// Pinned but not floating: it stays put while the page scrolls under it, which
/// keeps the location control — the thing that reframes the whole feed —
/// permanently reachable.
class _HomeAppBar extends StatelessWidget {
  const _HomeAppBar();

  @override
  Widget build(BuildContext context) {
    return SliverAppBar(
      pinned: true,
      backgroundColor: AppColors.background,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      titleSpacing: AppSpacing.screen,
      toolbarHeight: 58,
      title: Row(
        children: <Widget>[
          const SakaLogo(height: 26),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: PressableScale(
              onTap: () => LocationSheet.show(context),
              scale: 0.96,
              child: Obx(() {
                final LocationController location =
                    Get.find<LocationController>();
                return Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    const Icon(
                      Icons.location_on_rounded,
                      size: 15,
                      color: AppColors.teal,
                    ),
                    const SizedBox(width: 3),
                    Flexible(
                      child: Text(
                        location.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.caption.copyWith(
                          color: AppColors.navy,
                          fontWeight: FontWeight.w700,
                          fontSize: 12.5,
                        ),
                      ),
                    ),
                    const Icon(
                      Icons.keyboard_arrow_down_rounded,
                      size: 16,
                      color: AppColors.mutedForeground,
                    ),
                  ],
                );
              }),
            ),
          ),
          const _NotificationBell(),
        ],
      ),
      bottom: const PreferredSize(
        preferredSize: Size.fromHeight(1),
        child: Divider(height: 1),
      ),
    );
  }
}

class _NotificationBell extends StatelessWidget {
  const _NotificationBell();

  @override
  Widget build(BuildContext context) {
    final AuthController auth = Get.find<AuthController>();
    return Obx(() {
      if (!auth.isSignedIn) return const SizedBox.shrink();
      return PressableScale(
        onTap: () => Get.toNamed<void>(Routes.notifications),
        enforceMinTarget: true,
        semanticLabel: 'Notifications',
        child: const Icon(
          Icons.notifications_none_rounded,
          size: 23,
          color: AppColors.navy,
        ),
      );
    });
  }
}

/// The search affordance.
///
/// A button that opens the search screen, not a live text field. A real
/// TextField here would raise the keyboard over the home feed and put a
/// full-screen search experience inside a scrolling page.
class _SearchEntry extends StatelessWidget {
  const _SearchEntry();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.lg,
        AppSpacing.screen,
        AppSpacing.xs,
      ),
      child: PressableScale(
        onTap: () => Get.to<void>(() => const SearchScreen()),
        scale: 0.99,
        semanticLabel: 'Search SAKA',
        child: Container(
          height: AppSizes.inputHeight,
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.lg),
          decoration: BoxDecoration(
            color: AppColors.muted,
            borderRadius: AppRadius.mdAll,
          ),
          child: Row(
            children: <Widget>[
              const Icon(
                Icons.search_rounded,
                size: 21,
                color: AppColors.mutedForeground,
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Text(
                  'What are you looking for?',
                  style: AppTypography.body.copyWith(
                    color: AppColors.mutedForeground,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Entrances to the other three verticals.
///
/// Businesses, specialists and places are peers of the listing feed on the web,
/// and burying them in a menu would make the app feel like a listings app with
/// extras rather than the same product.
class _DiscoverRow extends StatelessWidget {
  const _DiscoverRow();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.screen,
        AppSpacing.xxl,
        AppSpacing.screen,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('Discover', style: AppTypography.section),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: <Widget>[
              Expanded(
                child: _DiscoverTile(
                  icon: Icons.storefront_rounded,
                  label: 'Businesses',
                  color: AppColors.teal,
                  onTap: () => Get.toNamed<void>(Routes.businesses),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: _DiscoverTile(
                  icon: Icons.workspace_premium_rounded,
                  label: 'Specialists',
                  color: AppColors.orange,
                  onTap: () => Get.toNamed<void>(Routes.specialists),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: _DiscoverTile(
                  icon: Icons.place_rounded,
                  label: 'Places',
                  color: AppColors.navy,
                  onTap: () => Get.toNamed<void>(Routes.places),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _DiscoverTile extends StatelessWidget {
  const _DiscoverTile({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      semanticLabel: label,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.lg),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: AppRadius.lgAll,
          boxShadow: AppShadows.card,
        ),
        child: Column(
          children: <Widget>[
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.10),
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(icon, size: 19, color: color),
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              label,
              style: AppTypography.caption.copyWith(
                color: AppColors.navy,
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
