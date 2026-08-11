import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/storage/cache_store.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/listing_card.dart';
import '../../core/widgets/pressable.dart';
import '../../core/widgets/states.dart';
import '../../data/models/listing.dart';
import '../../data/repositories/catalog_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../auth/sign_in_sheet.dart';
import '../filters/filter_sheet.dart';
import 'listings_controller.dart';

/// A listing feed: category results, search results, a region.
///
/// Grid and list are the same data in two shapes, and the choice persists —
/// people who compare prefer the list, people who browse prefer the grid, and
/// asking them again on every screen is the wrong default either way.
class ListingsScreen extends StatefulWidget {
  const ListingsScreen({
    required this.initialQuery,
    required this.title,
    super.key,
  });

  final ListingQuery initialQuery;
  final String title;

  @override
  State<ListingsScreen> createState() => _ListingsScreenState();
}

class _ListingsScreenState extends State<ListingsScreen> {
  late final ListingsController _controller;
  final ScrollController _scroll = ScrollController();

  /// A unique tag per instance so two listing screens on the stack — a category
  /// opened from inside a search result — do not share one controller.
  late final String _tag =
      'listings-${DateTime.now().microsecondsSinceEpoch}';

  @override
  void initState() {
    super.initState();
    _controller = Get.put(
      ListingsController(
        repository: Get.find<ListingRepository>(),
        cache: Get.find<CacheStore>(),
        initialQuery: widget.initialQuery,
      ),
      tag: _tag,
    );
    _scroll.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scroll
      ..removeListener(_onScroll)
      ..dispose();
    Get.delete<ListingsController>(tag: _tag);
    super.dispose();
  }

  /// Fetch when within ~1.5 screens of the end.
  ///
  /// Far enough ahead that the next page has usually arrived before the user
  /// reaches it, so the feed never visibly stops. Triggering at the very bottom
  /// guarantees a stall on every page boundary.
  void _onScroll() {
    if (!_scroll.hasClients) return;
    final double remaining =
        _scroll.position.maxScrollExtent - _scroll.position.pixels;
    if (remaining < _scroll.position.viewportDimension * 1.5) {
      _controller.loadMore();
    }
  }

  Future<void> _openFilters() async {
    final ListingQuery? next = await FilterSheet.show(
      context,
      current: _controller.query,
      catalog: Get.find<CatalogRepository>(),
    );
    if (next != null) await _controller.setQuery(next);
  }

  void _open(Listing listing) {
    Get.toNamed<void>(
      Routes.listingPath(listing.slug),
      arguments: <String, dynamic>{
        'listing': listing,
        'heroPrefix': _tag,
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(
        title: Text(widget.title),
        actions: <Widget>[
          Obx(
            () => IconButton(
              onPressed: _controller.toggleLayout,
              icon: Icon(
                _controller.isGrid.value
                    ? Icons.view_agenda_outlined
                    : Icons.grid_view_rounded,
                size: 21,
              ),
              tooltip: _controller.isGrid.value ? 'List view' : 'Grid view',
            ),
          ),
          const SizedBox(width: AppSpacing.xs),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(56),
          child: _FilterBar(
            controller: _controller,
            onOpenFilters: _openFilters,
          ),
        ),
      ),
      body: Obx(() {
        final ApiException? error = _controller.error.value;

        if (_controller.isLoadingFirstPage.value) {
          return _LoadingGrid(isGrid: _controller.isGrid.value);
        }

        if (error != null) {
          return SakaErrorState(
            error: error,
            onRetry: _controller.loadFirstPage,
          );
        }

        if (_controller.items.isEmpty) {
          return SakaEmptyState(
            icon: Icons.search_off_rounded,
            title: 'No listings here yet',
            message: _controller.query.activeCount > 0
                ? 'Nothing matches these filters. Try widening your search.'
                : 'There is nothing in this category at the moment.',
            actionLabel:
                _controller.query.activeCount > 0 ? 'Clear filters' : null,
            onAction: _controller.query.activeCount > 0
                ? () => _controller.setQuery(
                      ListingQuery(search: _controller.query.search),
                    )
                : null,
          );
        }

        return RefreshIndicator(
          onRefresh: _controller.reload,
          color: AppColors.primary,
          child: _controller.isGrid.value
              ? _Grid(
                  controller: _controller,
                  scroll: _scroll,
                  onOpen: _open,
                  tag: _tag,
                )
              : _List(
                  controller: _controller,
                  scroll: _scroll,
                  onOpen: _open,
                  tag: _tag,
                ),
        );
      }),
    );
  }
}

/// The result count and the filter button, always visible.
class _FilterBar extends StatelessWidget {
  const _FilterBar({required this.controller, required this.onOpenFilters});

  final ListingsController controller;
  final VoidCallback onOpenFilters;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: AppColors.background,
        border: Border(bottom: AppBorders.hairline),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          AppSpacing.screen,
          0,
          AppSpacing.md,
          AppSpacing.sm,
        ),
        child: Row(
          children: <Widget>[
            Expanded(
              child: Obx(
                () => Text(
                  controller.isLoadingFirstPage.value
                      ? 'Searching…'
                      : '${Fmt.thousands(controller.total.value)} '
                          '${controller.total.value == 1 ? 'result' : 'results'}',
                  style: AppTypography.caption.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
            Obx(() {
              final int count = controller.queryRx.value.activeCount;
              return PressableScale(
                onTap: onOpenFilters,
                scale: 0.95,
                semanticLabel: 'Filters',
                child: Container(
                  height: 38,
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.lg,
                  ),
                  decoration: BoxDecoration(
                    color: count > 0 ? AppColors.primary : AppColors.muted,
                    borderRadius: AppRadius.pillAll,
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Icon(
                        Icons.tune_rounded,
                        size: 16,
                        color: count > 0 ? Colors.white : AppColors.navy,
                      ),
                      const SizedBox(width: 6),
                      Text(
                        count > 0 ? 'Filters ($count)' : 'Filters',
                        style: AppTypography.caption.copyWith(
                          color: count > 0 ? Colors.white : AppColors.navy,
                          fontWeight: FontWeight.w800,
                          fontSize: 12.5,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }),
          ],
        ),
      ),
    );
  }
}

/// The width one card gets in the two-column grid: the viewport less the
/// screen inset on both sides and the single gutter between the columns.
double _gridCardWidth(BuildContext context) {
  return (MediaQuery.sizeOf(context).width -
          (AppSpacing.screen * 2) -
          AppSpacing.md) /
      2;
}

class _Grid extends StatelessWidget {
  const _Grid({
    required this.controller,
    required this.scroll,
    required this.onOpen,
    required this.tag,
  });

  final ListingsController controller;
  final ScrollController scroll;
  final ValueChanged<Listing> onOpen;
  final String tag;

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      controller: scroll,
      physics: const AlwaysScrollableScrollPhysics(
        parent: BouncingScrollPhysics(),
      ),
      slivers: <Widget>[
        SliverPadding(
          padding: const EdgeInsets.all(AppSpacing.screen),
          sliver: SliverGrid(
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              mainAxisSpacing: AppSpacing.md,
              crossAxisSpacing: AppSpacing.md,
              // A measured extent, not a ratio — see ListingCard.gridHeight.
              mainAxisExtent: ListingCard.gridHeight(
                context,
                _gridCardWidth(context),
              ),
            ),
            delegate: SliverChildBuilderDelegate(
              (BuildContext context, int index) {
                final Listing listing = controller.items[index];
                return ListingCard(
                  listing: listing,
                  heroPrefix: tag,
                  onTap: () => onOpen(listing),
                  onAuthRequired: () => SignInSheet.show(
                    context,
                    reason: 'Sign in to save listings to your account.',
                  ),
                );
              },
              childCount: controller.items.length,
            ),
          ),
        ),
        SliverToBoxAdapter(
          // The observables are read HERE, inside the Obx callback. Passing the
          // controller down and reading it in the child would put the read
          // outside the tracking scope, so the footer would never update.
          child: Obx(
            () => _Footer(
              isLoadingMore: controller.isLoadingMore.value,
              hasMore: controller.hasMore,
            ),
          ),
        ),
      ],
    );
  }
}

class _List extends StatelessWidget {
  const _List({
    required this.controller,
    required this.scroll,
    required this.onOpen,
    required this.tag,
  });

  final ListingsController controller;
  final ScrollController scroll;
  final ValueChanged<Listing> onOpen;
  final String tag;

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      controller: scroll,
      physics: const AlwaysScrollableScrollPhysics(
        parent: BouncingScrollPhysics(),
      ),
      slivers: <Widget>[
        SliverPadding(
          padding: const EdgeInsets.all(AppSpacing.screen),
          sliver: SliverList.separated(
            itemCount: controller.items.length,
            separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final Listing listing = controller.items[index];
              return ListingCard(
                listing: listing,
                layout: ListingCardLayout.list,
                heroPrefix: tag,
                onTap: () => onOpen(listing),
                onAuthRequired: () => SignInSheet.show(
                  context,
                  reason: 'Sign in to save listings to your account.',
                ),
              );
            },
          ),
        ),
        SliverToBoxAdapter(
          // The observables are read HERE, inside the Obx callback. Passing the
          // controller down and reading it in the child would put the read
          // outside the tracking scope, so the footer would never update.
          child: Obx(
            () => _Footer(
              isLoadingMore: controller.isLoadingMore.value,
              hasMore: controller.hasMore,
            ),
          ),
        ),
      ],
    );
  }
}

/// The end of the feed: a spinner while paging, or an honest full stop.
class _Footer extends StatelessWidget {
  const _Footer({required this.isLoadingMore, required this.hasMore});

  final bool isLoadingMore;
  final bool hasMore;

  @override
  Widget build(BuildContext context) {
    if (isLoadingMore) {
      return const Padding(
        padding: EdgeInsets.only(bottom: AppSpacing.xxxl),
        child: Center(
          child: SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    if (hasMore) return const SizedBox(height: AppSpacing.xxxl);

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.huge),
      child: Center(
        child: Text(
          "That's everything",
          style: AppTypography.caption.copyWith(color: AppColors.border),
        ),
      ),
    );
  }
}

class _LoadingGrid extends StatelessWidget {
  const _LoadingGrid({required this.isGrid});

  final bool isGrid;

  @override
  Widget build(BuildContext context) {
    if (!isGrid) {
      return ListView.separated(
        padding: const EdgeInsets.all(AppSpacing.screen),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 5,
        separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
        itemBuilder: (_, _) =>
            const ListingCardSkeleton(layout: ListingCardLayout.list),
      );
    }

    return GridView.builder(
      padding: const EdgeInsets.all(AppSpacing.screen),
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisSpacing: AppSpacing.md,
        crossAxisSpacing: AppSpacing.md,
        // Identical to the real grid, so the skeleton does not resize when the
        // cards arrive.
        mainAxisExtent: ListingCard.gridHeight(
          context,
          _gridCardWidth(context),
        ),
      ),
      itemCount: 6,
      itemBuilder: (_, _) => const ListingCardSkeleton(),
    );
  }
}
