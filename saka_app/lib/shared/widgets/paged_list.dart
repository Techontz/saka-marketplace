import 'package:flutter/material.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/widgets/states.dart';
import '../../data/models/paginated.dart';

/// An infinite list over a paginated endpoint.
///
/// Written once because every directory in this app — businesses, specialists,
/// places, bookings, reviews — is the same problem: fetch page 1, render, fetch
/// page 2 when the user nears the end, and never fetch the same page twice.
/// Reimplementing that per screen is how one of them ends up duplicating rows.
class PagedList<T> extends StatefulWidget {
  const PagedList({
    required this.fetch,
    required this.itemBuilder,
    required this.emptyTitle,
    required this.emptyMessage,
    super.key,
    this.emptyIcon = Icons.inbox_outlined,
    this.separatorHeight = AppSpacing.md,
    this.padding,
    this.scrollController,
    this.header,
    this.skeleton,
  });

  /// Called with the page number; returns that page.
  final Future<Paginated<T>> Function(int page) fetch;

  final Widget Function(BuildContext context, T item, int index) itemBuilder;
  final String emptyTitle;
  final String emptyMessage;
  final IconData emptyIcon;
  final double separatorHeight;
  final EdgeInsets? padding;
  final ScrollController? scrollController;
  final Widget? header;
  final Widget? skeleton;

  @override
  State<PagedList<T>> createState() => PagedListState<T>();
}

class PagedListState<T> extends State<PagedList<T>> {
  late final ScrollController _scroll =
      widget.scrollController ?? ScrollController();

  final List<T> _items = <T>[];
  ApiException? _error;
  bool _loadingFirst = true;
  bool _loadingMore = false;
  int _page = 1;
  int _lastPage = 1;

  bool get _hasMore => _page < _lastPage;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    reload();
  }

  @override
  void dispose() {
    _scroll.removeListener(_onScroll);
    if (widget.scrollController == null) _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scroll.hasClients || _loadingMore || _loadingFirst || !_hasMore) {
      return;
    }
    final double remaining =
        _scroll.position.maxScrollExtent - _scroll.position.pixels;
    if (remaining < _scroll.position.viewportDimension * 1.5) _loadMore();
  }

  /// Public so a parent can refresh after a filter change.
  Future<void> reload() async {
    setState(() {
      _loadingFirst = true;
      _error = null;
    });
    try {
      final Paginated<T> page = await widget.fetch(1);
      if (!mounted) return;
      setState(() {
        _items
          ..clear()
          ..addAll(page.items);
        _page = page.currentPage;
        _lastPage = page.lastPage;
        _loadingFirst = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _loadingFirst = false;
      });
    }
  }

  Future<void> _loadMore() async {
    setState(() => _loadingMore = true);
    try {
      final Paginated<T> page = await widget.fetch(_page + 1);
      if (!mounted) return;
      setState(() {
        _items.addAll(page.items);
        _page = page.currentPage;
        _lastPage = page.lastPage;
        _loadingMore = false;
      });
    } on Object {
      // Stops paging silently; the user can pull to refresh.
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loadingFirst) {
      return widget.skeleton ??
          const Center(child: CircularProgressIndicator(strokeWidth: 2));
    }

    if (_error != null) {
      return SakaErrorState(error: _error!, onRetry: reload);
    }

    if (_items.isEmpty) {
      return RefreshIndicator(
        onRefresh: reload,
        color: AppColors.primary,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: <Widget>[
            if (widget.header != null) widget.header!,
            SizedBox(
              height: MediaQuery.sizeOf(context).height * 0.5,
              child: SakaEmptyState(
                icon: widget.emptyIcon,
                title: widget.emptyTitle,
                message: widget.emptyMessage,
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: reload,
      color: AppColors.primary,
      child: ListView.separated(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(
          parent: BouncingScrollPhysics(),
        ),
        padding: widget.padding ?? const EdgeInsets.all(AppSpacing.screen),
        // One extra row for the header and one for the footer.
        itemCount: _items.length + (widget.header != null ? 1 : 0) + 1,
        separatorBuilder: (_, _) => SizedBox(height: widget.separatorHeight),
        itemBuilder: (BuildContext context, int index) {
          final int offset = widget.header != null ? 1 : 0;

          if (widget.header != null && index == 0) return widget.header!;

          final int itemIndex = index - offset;
          if (itemIndex >= _items.length) {
            if (_loadingMore) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: AppSpacing.xl),
                child: Center(
                  child: SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ),
              );
            }
            if (_hasMore) return const SizedBox(height: AppSpacing.xxl);
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.xxl),
              child: Center(
                child: Text(
                  "That's everything",
                  style: AppTypography.caption.copyWith(color: AppColors.border),
                ),
              ),
            );
          }

          return widget.itemBuilder(context, _items[itemIndex], itemIndex);
        },
      ),
    );
  }
}
