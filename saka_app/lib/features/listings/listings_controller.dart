import 'dart:async';

import 'package:dio/dio.dart';
import 'package:get/get.dart';

import '../../core/errors/api_exception.dart';
import '../../core/storage/cache_store.dart';
import '../../data/models/listing.dart';
import '../../data/models/paginated.dart';
import '../../data/repositories/listing_repository.dart';

/// A paginated listing feed.
///
/// One controller drives every listing surface — a category, a search result,
/// a region — because they differ only by the [ListingQuery] they start with.
class ListingsController extends GetxController {
  ListingsController({
    required ListingRepository repository,
    required CacheStore cache,
    required ListingQuery initialQuery,
  })  : _repository = repository,
        _cache = cache,
        _query = initialQuery.obs;

  final ListingRepository _repository;
  final CacheStore _cache;
  final Rx<ListingQuery> _query;

  final RxList<Listing> items = <Listing>[].obs;
  final RxBool isLoadingFirstPage = true.obs;
  final RxBool isLoadingMore = false.obs;
  final Rxn<ApiException> error = Rxn<ApiException>();
  final RxInt total = 0.obs;
  final RxBool isGrid = true.obs;

  int _page = 1;
  int _lastPage = 1;

  /// Cancels the previous request when the filters change.
  ///
  /// Without this, a user adjusting three filters in a second has three
  /// requests racing, and whichever returns LAST wins — which is routinely the
  /// oldest one, so the screen settles on the wrong results.
  CancelToken? _cancel;

  ListingQuery get query => _query.value;
  Rx<ListingQuery> get queryRx => _query;
  bool get hasMore => _page < _lastPage;

  @override
  void onInit() {
    super.onInit();
    isGrid.value = _cache.readString(CacheStore.kListingLayout) != 'list';
    unawaited(loadFirstPage());
  }

  @override
  void onClose() {
    _cancel?.cancel('controller disposed');
    super.onClose();
  }

  Future<void> setQuery(ListingQuery next) async {
    _query.value = next;
    await loadFirstPage();
  }

  Future<void> toggleLayout() async {
    isGrid.value = !isGrid.value;
    // Remembered across launches: switching to the list view is a preference,
    // not a per-screen mode.
    await _cache.writeString(
      CacheStore.kListingLayout,
      isGrid.value ? 'grid' : 'list',
    );
  }

  Future<void> loadFirstPage() async {
    _cancel?.cancel('superseded');
    final CancelToken token = CancelToken();
    _cancel = token;

    _page = 1;
    isLoadingFirstPage.value = true;
    error.value = null;

    try {
      final Paginated<Listing> page = await _repository.search(
        _query.value,
        cancelToken: token,
      );
      if (token.isCancelled) return;
      items.assignAll(page.items);
      total.value = page.total;
      _lastPage = page.lastPage;
    } on ApiException catch (e) {
      // A cancelled request is not a failure — it is the newer request doing
      // its job. Showing an error for it would flash a red state on every
      // filter change.
      if (e.kind == ApiErrorKind.cancelled) return;
      error.value = e;
      items.clear();
    } on Object catch (e) {
      final ApiException wrapped = ApiException.from(e);
      if (wrapped.kind == ApiErrorKind.cancelled) return;
      error.value = wrapped;
      items.clear();
    } finally {
      if (!token.isCancelled) isLoadingFirstPage.value = false;
    }
  }

  /// The next page.
  ///
  /// Guarded three ways — already loading, no more pages, first page still in
  /// flight — because a fast scroll fires the trigger several times before the
  /// first response lands, and duplicate pages mean duplicate rows.
  Future<void> loadMore() async {
    if (isLoadingMore.value || isLoadingFirstPage.value || !hasMore) return;

    isLoadingMore.value = true;
    final int next = _page + 1;

    try {
      final Paginated<Listing> page = await _repository.search(
        _query.value,
        page: next,
      );
      items.addAll(page.items);
      _page = page.currentPage;
      _lastPage = page.lastPage;
      total.value = page.total;
    } on Object {
      // Silently stops paging. A snackbar on a background page load interrupts
      // scrolling for a problem the user did not ask about; the footer's retry
      // is there if they want it.
    } finally {
      isLoadingMore.value = false;
    }
  }

  /// Named `reload`, not `refresh`: GetxController already declares
  /// `refresh()` and silently overriding it would fire GetBuilder rebuilds
  /// nobody asked for.
  Future<void> reload() => loadFirstPage();
}
