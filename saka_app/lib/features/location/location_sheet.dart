import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/misc.dart';
import '../../shared/widgets/saka_sheet.dart';
import '../../shared/widgets/saka_text_field.dart';
import 'location_controller.dart';

/// Where am I browsing?
///
/// Two modes in one sheet: an INVITATION on first launch, which explains why
/// SAKA is asking before it asks anything, and a plain picker afterwards.
///
/// The app never requests the OS location permission. Nothing in it needs a GPS
/// fix that a chosen region cannot supply, and firing a system dialog at launch
/// to sort a listing feed is the pattern the SAKA web app deliberately avoids.
class LocationSheet extends StatefulWidget {
  const LocationSheet({super.key, this.isInvitation = false});

  final bool isInvitation;

  static Future<void> show(
    BuildContext context, {
    bool isInvitation = false,
  }) {
    return SakaSheet.show<void>(
      context,
      title: isInvitation ? null : 'Choose location',
      child: LocationSheet(isInvitation: isInvitation),
    );
  }

  @override
  State<LocationSheet> createState() => _LocationSheetState();
}

class _LocationSheetState extends State<LocationSheet> {
  final TextEditingController _search = TextEditingController();
  final LocationController _location = Get.find<LocationController>();

  Timer? _debounce;
  List<LocationOption> _results = const <LocationOption>[];
  bool _searching = false;

  /// The region the user has drilled into, if any. Districts load lazily —
  /// fetching all 180-odd up front for a sheet the user may close immediately
  /// would be a wasted round trip on a metered connection.
  LocationOption? _expandedRegion;
  List<LocationOption> _districts = const <LocationOption>[];
  bool _loadingDistricts = false;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// 350ms. Below ~250ms a fast typist fires a request per keystroke; above
  /// ~450ms the list visibly lags the typing.
  void _onQueryChanged(String value) {
    _debounce?.cancel();
    final String query = value.trim();

    if (query.length < 2) {
      setState(() {
        _results = const <LocationOption>[];
        _searching = false;
      });
      return;
    }

    setState(() => _searching = true);
    _debounce = Timer(const Duration(milliseconds: 350), () async {
      final List<LocationOption> hits = await _location.search(query);
      if (!mounted) return;
      setState(() {
        _results = hits;
        _searching = false;
      });
    });
  }

  Future<void> _expand(LocationOption region) async {
    if (_expandedRegion?.slug == region.slug) {
      setState(() {
        _expandedRegion = null;
        _districts = const <LocationOption>[];
      });
      return;
    }

    setState(() {
      _expandedRegion = region;
      _districts = const <LocationOption>[];
      _loadingDistricts = true;
    });

    final List<LocationOption> districts =
        await _location.districtsFor(region.slug);
    if (!mounted) return;
    setState(() {
      _districts = districts;
      _loadingDistricts = false;
    });
  }

  Future<void> _choose({
    LocationOption? region,
    LocationOption? district,
  }) async {
    await _location.choose(region: region, district: district);
    if (!mounted) return;
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (widget.isInvitation) ...<Widget>[
          Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.09),
              shape: BoxShape.circle,
            ),
            child: const Icon(
              Icons.explore_outlined,
              size: 28,
              color: AppColors.primary,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          Text(
            'Discover what is around you',
            textAlign: TextAlign.center,
            style: AppTypography.headline,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'Choose where you are browsing and SAKA will put nearby '
            'listings, businesses and specialists first. You can change '
            'it at any time.',
            textAlign: TextAlign.center,
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
        ],

        SakaSearchField(
          controller: _search,
          hint: 'Search a region, district or ward',
          onChanged: _onQueryChanged,
        ),
        const SizedBox(height: AppSpacing.lg),

        Flexible(
          child: _search.text.trim().length >= 2
              ? _SearchResults(
                  results: _results,
                  isSearching: _searching,
                  onSelect: (LocationOption option) => _choose(
                    // A search hit is applied at whatever level it names. The
                    // API returns the parent region on a district hit, so
                    // selecting "Kinondoni" filters correctly without the user
                    // having to pick its region first.
                    region: option.type == 'region' ? option : null,
                    district: option.type == 'district' ? option : null,
                  ),
                )
              : _RegionList(
                  onChooseAll: () => _choose(),
                  expanded: _expandedRegion,
                  districts: _districts,
                  loadingDistricts: _loadingDistricts,
                  onExpand: _expand,
                  onChooseRegion: (LocationOption r) => _choose(region: r),
                  onChooseDistrict: (LocationOption d) =>
                      _choose(region: _expandedRegion, district: d),
                ),
        ),

        if (widget.isInvitation) ...<Widget>[
          const SizedBox(height: AppSpacing.md),
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Not now'),
          ),
        ],
      ],
    );
  }
}

class _RegionList extends StatelessWidget {
  const _RegionList({
    required this.onChooseAll,
    required this.expanded,
    required this.districts,
    required this.loadingDistricts,
    required this.onExpand,
    required this.onChooseRegion,
    required this.onChooseDistrict,
  });

  final VoidCallback onChooseAll;
  final LocationOption? expanded;
  final List<LocationOption> districts;
  final bool loadingDistricts;
  final ValueChanged<LocationOption> onExpand;
  final ValueChanged<LocationOption> onChooseRegion;
  final ValueChanged<LocationOption> onChooseDistrict;

  @override
  Widget build(BuildContext context) {
    final LocationController location = Get.find<LocationController>();

    return Obx(() {
      final List<LocationOption> regions = location.regions;

      if (regions.isEmpty && location.isLoading) {
        return const Center(
          child: Padding(
            padding: EdgeInsets.all(AppSpacing.xxxl),
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        );
      }

      return ListView(
        shrinkWrap: true,
        physics: const BouncingScrollPhysics(),
        padding: EdgeInsets.zero,
        children: <Widget>[
          _Row(
            label: 'All Tanzania',
            trailing: 'Everywhere',
            isSelected: !location.hasChoice,
            onTap: onChooseAll,
          ),
          const Divider(height: 1),
          for (final LocationOption region in regions) ...<Widget>[
            _Row(
              label: region.name,
              trailing: Fmt.compactCount(region.listingCount),
              isSelected: location.region?.slug == region.slug &&
                  location.district == null,
              isExpanded: expanded?.slug == region.slug,
              // Tapping the row expands; tapping the label chooses the whole
              // region. Two intents, two targets — a single-action row would
              // make "the whole of Dar es Salaam" unreachable.
              onTap: () => onExpand(region),
              onChoose: () => onChooseRegion(region),
            ),
            if (expanded?.slug == region.slug) ...<Widget>[
              if (loadingDistricts)
                const Padding(
                  padding: EdgeInsets.all(AppSpacing.lg),
                  child: Center(
                    child: SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                )
              else
                for (final LocationOption district in districts)
                  _Row(
                    label: district.name,
                    trailing: Fmt.compactCount(district.listingCount),
                    isSelected: location.district?.slug == district.slug,
                    indented: true,
                    onTap: () => onChooseDistrict(district),
                  ),
            ],
            const Divider(height: 1),
          ],
        ],
      );
    });
  }
}

class _SearchResults extends StatelessWidget {
  const _SearchResults({
    required this.results,
    required this.isSearching,
    required this.onSelect,
  });

  final List<LocationOption> results;
  final bool isSearching;
  final ValueChanged<LocationOption> onSelect;

  @override
  Widget build(BuildContext context) {
    if (isSearching && results.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(AppSpacing.xxxl),
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      );
    }

    if (results.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(AppSpacing.xxl),
        child: Text(
          'No places match that. Try a region or district name.',
          textAlign: TextAlign.center,
          style: AppTypography.bodySmall,
        ),
      );
    }

    return ListView.separated(
      shrinkWrap: true,
      physics: const BouncingScrollPhysics(),
      padding: EdgeInsets.zero,
      itemCount: results.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (BuildContext context, int index) {
        final LocationOption option = results[index];
        return _Row(
          label: option.name,
          subtitle: option.parentName,
          trailing: Fmt.compactCount(option.listingCount),
          isSelected: false,
          onTap: () => onSelect(option),
        );
      },
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.label,
    required this.isSelected,
    required this.onTap,
    this.subtitle,
    this.trailing,
    this.indented = false,
    this.isExpanded,
    this.onChoose,
  });

  final String label;
  final String? subtitle;
  final String? trailing;
  final bool isSelected;
  final bool indented;
  final bool? isExpanded;
  final VoidCallback onTap;
  final VoidCallback? onChoose;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.995,
      child: Container(
        constraints: const BoxConstraints(minHeight: AppSizes.minTouchTarget),
        padding: EdgeInsets.fromLTRB(
          indented ? AppSpacing.xxl : AppSpacing.xs,
          AppSpacing.md,
          AppSpacing.xs,
          AppSpacing.md,
        ),
        child: Row(
          children: <Widget>[
            if (indented)
              const Padding(
                padding: EdgeInsets.only(right: AppSpacing.sm),
                child: Icon(
                  Icons.subdirectory_arrow_right_rounded,
                  size: 14,
                  color: AppColors.border,
                ),
              ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.body.copyWith(
                      fontWeight:
                          isSelected ? FontWeight.w800 : FontWeight.w600,
                      color: isSelected ? AppColors.primary : AppColors.navy,
                    ),
                  ),
                  if (subtitle != null)
                    Text(subtitle!, style: AppTypography.caption),
                ],
              ),
            ),
            if (trailing != null)
              Text(trailing!, style: AppTypography.caption),
            if (isSelected)
              const Padding(
                padding: EdgeInsets.only(left: AppSpacing.sm),
                child: Icon(
                  Icons.check_rounded,
                  size: 18,
                  color: AppColors.primary,
                ),
              )
            else if (isExpanded != null) ...<Widget>[
              const SizedBox(width: AppSpacing.xs),
              // "Choose the whole region" — a separate 44pt target so drilling
              // in and selecting the parent are distinct actions.
              PressableScale(
                onTap: onChoose,
                enforceMinTarget: true,
                semanticLabel: 'Choose all of $label',
                child: Icon(
                  isExpanded!
                      ? Icons.keyboard_arrow_up_rounded
                      : Icons.keyboard_arrow_down_rounded,
                  size: 20,
                  color: AppColors.mutedForeground,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
