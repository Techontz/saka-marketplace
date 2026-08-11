import 'dart:async';

import 'package:get/get.dart';

import '../../data/models/listing.dart';
import '../../data/models/paginated.dart';
import '../../data/models/user.dart';
import '../../data/repositories/account_repository.dart';
import '../auth/auth_controller.dart';

/// Saved listings, with an optimistic heart.
///
/// Permanent, and holding only a SET OF SLUGS rather than the listings
/// themselves. Every card in the app asks this set whether it is saved, so a
/// heart tapped in a search result is already filled when the same listing
/// appears on the home screen — without either screen refetching.
class FavoritesController extends GetxController {
  FavoritesController({
    required AccountRepository repository,
    required AuthController auth,
  })  : _repository = repository,
        _auth = auth;

  final AccountRepository _repository;
  final AuthController _auth;

  final RxSet<String> _listingSlugs = <String>{}.obs;
  final RxSet<String> _businessSlugs = <String>{}.obs;

  /// Slugs with a request in flight. Used to ignore a second tap rather than to
  /// show a spinner — the heart has already changed, and a spinner on top of an
  /// optimistic update is the worst of both.
  final Set<String> _inFlight = <String>{};

  bool isListingSaved(String slug) => _listingSlugs.contains(slug);
  bool isBusinessSaved(String slug) => _businessSlugs.contains(slug);

  int get savedCount => _listingSlugs.length;

  /// The observable set, so a screen can react to any heart being toggled
  /// anywhere in the app without polling.
  RxSet<String> get savedCountRx => _listingSlugs;

  @override
  void onInit() {
    super.onInit();
    // Re-sync whenever the signed-in user changes, including sign-out (where
    // the correct saved set is empty). Listens to the controller's real
    // observable — wrapping the `isSignedIn` getter in `.obs` would create a
    // fresh Rx that nothing updates, and the worker would never fire.
    ever<AppUser?>(_auth.userRx, (_) => unawaited(sync()));
    if (_auth.isSignedIn) unawaited(sync());
  }

  /// Pull the authoritative set from the server.
  ///
  /// Only the slugs are kept. Holding the full listings would mean this
  /// permanent controller retains every saved listing's images for the life of
  /// the process.
  Future<void> sync() async {
    if (!_auth.isSignedIn) {
      _listingSlugs.clear();
      _businessSlugs.clear();
      return;
    }

    try {
      final Paginated<Listing> page =
          await _repository.favoriteListings(page: 1);
      _listingSlugs
        ..clear()
        ..addAll(page.items.map((Listing l) => l.slug));
    } on Object {
      // A failed sync leaves the previous set alone. Clearing it would un-fill
      // every heart in the app because of one bad request.
    }
  }

  /// Toggle, optimistically.
  ///
  /// The heart changes in the same frame as the tap and the request follows.
  /// On failure the change is rolled back and the caller is told, so it can
  /// show a quiet message — the listing page is never reloaded for this.
  ///
  /// Returns the state the UI should now be in.
  Future<bool> toggleListing(
    String slug, {
    void Function()? onAuthRequired,
    void Function(String message)? onError,
  }) async {
    if (!_auth.isSignedIn) {
      onAuthRequired?.call();
      return false;
    }

    if (_inFlight.contains(slug)) return _listingSlugs.contains(slug);

    final bool wasSaved = _listingSlugs.contains(slug);
    final bool next = !wasSaved;

    // Optimistic.
    if (next) {
      _listingSlugs.add(slug);
    } else {
      _listingSlugs.remove(slug);
    }
    _inFlight.add(slug);

    try {
      if (next) {
        await _repository.favoriteListing(slug);
      } else {
        await _repository.unfavoriteListing(slug);
      }
      return next;
    } on Object {
      // Roll back to exactly what it was.
      if (wasSaved) {
        _listingSlugs.add(slug);
      } else {
        _listingSlugs.remove(slug);
      }
      onError?.call("Couldn't save that. Please try again.");
      return wasSaved;
    } finally {
      _inFlight.remove(slug);
    }
  }

  Future<bool> toggleBusiness(
    String slug, {
    void Function()? onAuthRequired,
    void Function(String message)? onError,
  }) async {
    if (!_auth.isSignedIn) {
      onAuthRequired?.call();
      return false;
    }
    if (_inFlight.contains(slug)) return _businessSlugs.contains(slug);

    final bool wasSaved = _businessSlugs.contains(slug);
    final bool next = !wasSaved;

    if (next) {
      _businessSlugs.add(slug);
    } else {
      _businessSlugs.remove(slug);
    }
    _inFlight.add(slug);

    try {
      if (next) {
        await _repository.favoriteBusiness(slug);
      } else {
        await _repository.unfavoriteBusiness(slug);
      }
      return next;
    } on Object {
      if (wasSaved) {
        _businessSlugs.add(slug);
      } else {
        _businessSlugs.remove(slug);
      }
      onError?.call("Couldn't save that. Please try again.");
      return wasSaved;
    } finally {
      _inFlight.remove(slug);
    }
  }

  /// Seed from a detail response's `meta.is_favorited`, so opening a listing
  /// directly (from a deep link, before any sync) shows the right heart.
  void seed(String slug, {required bool isSaved}) {
    if (isSaved) {
      _listingSlugs.add(slug);
    } else {
      _listingSlugs.remove(slug);
    }
  }
}
