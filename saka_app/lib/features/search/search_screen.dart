import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/routes/app_routes.dart';
import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/storage/cache_store.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/listing_repository.dart';
import '../../shared/widgets/saka_text_field.dart';
import '../listings/listings_screen.dart';

/// Search.
///
/// A dedicated screen, not a field on the home page: search needs the keyboard,
/// the full height for suggestions, and its own back gesture.
///
/// Two things make it feel instant — a 300ms debounce so a fast typist fires
/// one request instead of eight, and a CancelToken so the answer to "dar" can
/// never overwrite the answer to "dar es sal". Without cancellation the
/// suggestions visibly flicker backwards as slower earlier requests land.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final TextEditingController _query = TextEditingController();
  final ListingRepository _repository = Get.find<ListingRepository>();
  final CacheStore _cache = Get.find<CacheStore>();

  Timer? _debounce;
  CancelToken? _cancel;

  List<SearchSuggestion> _suggestions = const <SearchSuggestion>[];
  List<String> _recent = const <String>[];
  List<String> _popular = const <String>[];
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _recent = _readRecent();
    unawaited(_loadPopular());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _cancel?.cancel('screen disposed');
    _query.dispose();
    super.dispose();
  }

  List<String> _readRecent() {
    final dynamic raw = _cache.readJson(CacheStore.kRecentSearches)?.value;
    if (raw is! List) return const <String>[];
    return raw.whereType<String>().toList(growable: false);
  }

  Future<void> _remember(String term) async {
    final List<String> next = <String>[
      term,
      ..._recent.where((String t) => t.toLowerCase() != term.toLowerCase()),
    ].take(8).toList(growable: false);
    setState(() => _recent = next);
    await _cache.writeJson(CacheStore.kRecentSearches, next);
  }

  Future<void> _loadPopular() async {
    try {
      final List<String> popular = await _repository.popularSearches();
      if (mounted) setState(() => _popular = popular);
    } on Object {
      // A missing "popular" list simply hides that section.
    }
  }

  void _onChanged(String value) {
    _debounce?.cancel();
    _cancel?.cancel('superseded');

    final String term = value.trim();
    if (term.length < 2) {
      setState(() {
        _suggestions = const <SearchSuggestion>[];
        _loading = false;
      });
      return;
    }

    setState(() => _loading = true);

    // 300ms: the middle of the band where a request per word, rather than per
    // keystroke, still feels immediate.
    _debounce = Timer(const Duration(milliseconds: 300), () async {
      final CancelToken token = CancelToken();
      _cancel = token;
      try {
        final List<SearchSuggestion> hits =
            await _repository.suggestions(term, cancelToken: token);
        if (!mounted || token.isCancelled) return;
        setState(() {
          _suggestions = hits;
          _loading = false;
        });
      } on Object {
        if (!mounted || token.isCancelled) return;
        setState(() => _loading = false);
      }
    });
  }

  Future<void> _submit(String term) async {
    final String value = term.trim();
    if (value.isEmpty) return;
    await _remember(value);
    if (!mounted) return;
    await Get.to<void>(
      () => ListingsScreen(
        initialQuery: ListingQuery(search: value),
        title: value,
      ),
    );
  }

  void _openSuggestion(SearchSuggestion suggestion) {
    switch (suggestion.type) {
      case 'listing':
        Get.toNamed<void>(Routes.listingPath(suggestion.slug));
      case 'business':
        Get.toNamed<void>(Routes.businessPath(suggestion.slug));
      case 'place':
        Get.toNamed<void>(Routes.placePath(suggestion.slug));
      case 'category':
        Get.to<void>(
          () => ListingsScreen(
            initialQuery: ListingQuery(categorySlug: suggestion.slug),
            title: suggestion.label,
          ),
        );
      default:
        _submit(suggestion.label);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bool isTyping = _query.text.trim().length >= 2;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsets.only(right: AppSpacing.screen),
          child: SakaSearchField(
            controller: _query,
            autofocus: true,
            hint: 'Listings, businesses, specialists',
            onChanged: (String value) => setState(() => _onChanged(value)),
            onSubmitted: _submit,
          ),
        ),
      ),
      body: isTyping
          ? _Suggestions(
              suggestions: _suggestions,
              isLoading: _loading,
              query: _query.text.trim(),
              onTap: _openSuggestion,
              onSearchAll: () => _submit(_query.text),
            )
          : _Discovery(
              recent: _recent,
              popular: _popular,
              onTap: (String term) {
                _query.text = term;
                _submit(term);
              },
              onClearRecent: () async {
                setState(() => _recent = const <String>[]);
                await _cache.delete(CacheStore.kRecentSearches);
              },
            ),
    );
  }
}

class _Suggestions extends StatelessWidget {
  const _Suggestions({
    required this.suggestions,
    required this.isLoading,
    required this.query,
    required this.onTap,
    required this.onSearchAll,
  });

  final List<SearchSuggestion> suggestions;
  final bool isLoading;
  final String query;
  final ValueChanged<SearchSuggestion> onTap;
  final VoidCallback onSearchAll;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: EdgeInsets.zero,
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      children: <Widget>[
        // "Search for X" is always first, so a term with no suggestions is
        // still actionable rather than a dead end.
        _Row(
          icon: Icons.search_rounded,
          label: 'Search for "$query"',
          isPrimary: true,
          onTap: onSearchAll,
        ),
        const Divider(height: 1),
        if (isLoading && suggestions.isEmpty)
          const Padding(
            padding: EdgeInsets.all(AppSpacing.xxl),
            child: Center(
              child: SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          )
        else
          for (final SearchSuggestion suggestion in suggestions)
            _Row(
              icon: switch (suggestion.type) {
                'business' => Icons.storefront_outlined,
                'place' => Icons.place_outlined,
                'category' => Icons.category_outlined,
                _ => Icons.local_offer_outlined,
              },
              label: suggestion.label,
              trailing: switch (suggestion.type) {
                'business' => 'Business',
                'place' => 'Place',
                'category' => 'Category',
                _ => null,
              },
              onTap: () => onTap(suggestion),
            ),
      ],
    );
  }
}

class _Discovery extends StatelessWidget {
  const _Discovery({
    required this.recent,
    required this.popular,
    required this.onTap,
    required this.onClearRecent,
  });

  final List<String> recent;
  final List<String> popular;
  final ValueChanged<String> onTap;
  final VoidCallback onClearRecent;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.screen),
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      children: <Widget>[
        if (recent.isNotEmpty) ...<Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text('Recent', style: AppTypography.section)),
              TextButton(onPressed: onClearRecent, child: const Text('Clear')),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              for (final String term in recent)
                _Pill(label: term, icon: Icons.history_rounded, onTap: () => onTap(term)),
            ],
          ),
          const SizedBox(height: AppSpacing.xxl),
        ],
        if (popular.isNotEmpty) ...<Widget>[
          Text('Popular on SAKA', style: AppTypography.section),
          const SizedBox(height: AppSpacing.md),
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
            children: <Widget>[
              for (final String term in popular)
                _Pill(
                  label: term,
                  icon: Icons.trending_up_rounded,
                  onTap: () => onTap(term),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.icon,
    required this.label,
    required this.onTap,
    this.trailing,
    this.isPrimary = false,
  });

  final IconData icon;
  final String label;
  final String? trailing;
  final bool isPrimary;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        constraints: const BoxConstraints(minHeight: AppSizes.minTouchTarget),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.screen,
          vertical: AppSpacing.md,
        ),
        child: Row(
          children: <Widget>[
            Icon(
              icon,
              size: 18,
              color: isPrimary ? AppColors.primary : AppColors.mutedForeground,
            ),
            const SizedBox(width: AppSpacing.md),
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: AppTypography.body.copyWith(
                  fontWeight: isPrimary ? FontWeight.w700 : FontWeight.w500,
                  color: isPrimary ? AppColors.primary : AppColors.navy,
                ),
              ),
            ),
            if (trailing != null) Text(trailing!, style: AppTypography.caption),
          ],
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label, required this.icon, required this.onTap});

  final String label;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.95,
      child: Container(
        constraints: const BoxConstraints(minHeight: 40),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        decoration: BoxDecoration(
          color: AppColors.muted,
          borderRadius: AppRadius.pillAll,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: 14, color: AppColors.mutedForeground),
            const SizedBox(width: 6),
            Text(
              label,
              style: AppTypography.caption.copyWith(
                color: AppColors.navy,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
