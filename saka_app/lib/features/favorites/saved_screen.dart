import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../core/errors/api_exception.dart';
import '../../core/widgets/listing_card.dart';
import '../../core/widgets/states.dart';
import '../../data/models/listing.dart';
import '../../data/repositories/account_repository.dart';
import '../auth/auth_controller.dart';
import '../auth/sign_in_sheet.dart';
import 'favorites_controller.dart';

/// Saved listings.
///
/// Reloads whenever the tab becomes visible rather than caching a snapshot: a
/// listing un-hearted on a detail screen must be gone when the user comes back
/// here, and the set is small enough that a refetch is cheaper than the
/// bookkeeping to keep two lists in sync.
class SavedScreen extends StatefulWidget {
  const SavedScreen({required this.scrollController, super.key});

  final ScrollController scrollController;

  @override
  State<SavedScreen> createState() => _SavedScreenState();
}

class _SavedScreenState extends State<SavedScreen>
    with AutomaticKeepAliveClientMixin {
  final AccountRepository _repository = Get.find<AccountRepository>();
  final AuthController _auth = Get.find<AuthController>();

  List<Listing> _items = const <Listing>[];
  ApiException? _error;
  bool _loading = true;

  @override
  bool get wantKeepAlive => true;

  @override
  void initState() {
    super.initState();
    _load();
    // Re-read when the signed-in user changes, including sign-out.
    ever<Object?>(_auth.userRx, (_) => _load());
    // And when a heart is toggled anywhere in the app.
    ever<Object?>(Get.find<FavoritesController>().savedCountRx, (_) {
      if (mounted && !_loading) _load();
    });
  }

  Future<void> _load() async {
    if (!_auth.isSignedIn) {
      if (mounted) {
        setState(() {
          _items = const <Listing>[];
          _loading = false;
          _error = null;
        });
      }
      return;
    }

    if (mounted) setState(() => _loading = _items.isEmpty);
    try {
      final List<Listing> items =
          (await _repository.favoriteListings()).items;
      if (!mounted) return;
      setState(() {
        _items = items;
        _error = null;
        _loading = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);

    return Scaffold(
      backgroundColor: AppColors.page,
      appBar: AppBar(title: const Text('Saved')),
      body: Obx(() {
        if (!_auth.isSignedIn) {
          return SakaAuthPrompt(
            title: 'Keep what you like',
            message: 'Sign in to save listings and find them again on any '
                'device.',
            onSignIn: () => SignInSheet.show(
              context,
              reason: 'Sign in to see your saved listings.',
            ),
          );
        }

        if (_loading) {
          return ListView.separated(
            padding: const EdgeInsets.all(AppSpacing.screen),
            itemCount: 4,
            separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
            itemBuilder: (_, _) =>
                const ListingCardSkeleton(layout: ListingCardLayout.list),
          );
        }

        if (_error != null) {
          return SakaErrorState(error: _error!, onRetry: _load);
        }

        if (_items.isEmpty) {
          return const SakaEmptyState(
            icon: Icons.favorite_border_rounded,
            title: 'Nothing saved yet',
            message: 'Tap the heart on any listing and it will appear here.',
          );
        }

        return RefreshIndicator(
          onRefresh: _load,
          color: AppColors.primary,
          child: ListView.separated(
            controller: widget.scrollController,
            physics: const AlwaysScrollableScrollPhysics(
              parent: BouncingScrollPhysics(),
            ),
            padding: const EdgeInsets.all(AppSpacing.screen),
            itemCount: _items.length,
            separatorBuilder: (_, _) => const SizedBox(height: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final Listing listing = _items[index];
              return ListingCard(
                listing: listing,
                layout: ListingCardLayout.list,
                heroPrefix: 'saved',
                onTap: () => Get.toNamed<void>(
                  Routes.listingPath(listing.slug),
                  arguments: <String, dynamic>{
                    'listing': listing,
                    'heroPrefix': 'saved',
                  },
                ),
              );
            },
          ),
        );
      }),
    );
  }
}
