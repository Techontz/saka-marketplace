import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/utils/formatters.dart';
import '../../core/widgets/pressable.dart';
import '../../data/models/category.dart';
import '../../data/models/misc.dart';
import '../../data/repositories/catalog_repository.dart';
import '../../data/repositories/listing_repository.dart';
import '../location/location_controller.dart';

/// Filters, built from the backend's taxonomy.
///
/// **Nothing in this file knows what a bedroom is.** The category-specific
/// section is generated from `GET /categories/{slug}/attributes`, so property
/// shows Bedrooms and Bathrooms, vehicles show Make and Transmission, and a
/// vertical an administrator adds next month shows its own fields with no
/// release. A hardcoded per-category filter map is the thing this sheet exists
/// to avoid.
class FilterSheet extends StatefulWidget {
  const FilterSheet({
    required this.current,
    required this.catalog,
    super.key,
  });

  final ListingQuery current;
  final CatalogRepository catalog;

  /// Returns the new query, or null if the user backed out.
  static Future<ListingQuery?> show(
    BuildContext context, {
    required ListingQuery current,
    required CatalogRepository catalog,
  }) {
    return showModalBottomSheet<ListingQuery>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      barrierColor: AppColors.scrim,
      // Nearly full height: filters are a task, not a glance, and a short sheet
      // means scrolling a scroll view inside a draggable sheet — which fights
      // the drag gesture on every attempt to reach the Apply button.
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * 0.9,
      ),
      builder: (BuildContext context) =>
          FilterSheet(current: current, catalog: catalog),
    );
  }

  @override
  State<FilterSheet> createState() => _FilterSheetState();
}

class _FilterSheetState extends State<FilterSheet> {
  late ListingQuery _draft = widget.current;

  List<Category> _categories = const <Category>[];
  List<CategoryAttribute> _attributes = const <CategoryAttribute>[];
  bool _loadingAttributes = false;

  final TextEditingController _minPrice = TextEditingController();
  final TextEditingController _maxPrice = TextEditingController();

  @override
  void initState() {
    super.initState();
    _categories = widget.catalog.cachedCategories() ?? const <Category>[];
    if (_draft.minPrice != null) _minPrice.text = '${_draft.minPrice}';
    if (_draft.maxPrice != null) _maxPrice.text = '${_draft.maxPrice}';
    if (_draft.categorySlug != null) _loadAttributes(_draft.categorySlug!);
  }

  @override
  void dispose() {
    _minPrice.dispose();
    _maxPrice.dispose();
    super.dispose();
  }

  Future<void> _loadAttributes(String slug) async {
    setState(() => _loadingAttributes = true);
    try {
      final List<CategoryAttribute> attributes =
          await widget.catalog.attributes(slug);
      if (!mounted) return;
      setState(() {
        _attributes = attributes
            .where((CategoryAttribute a) => a.isFilterable)
            .toList(growable: false);
        _loadingAttributes = false;
      });
    } on Object {
      if (!mounted) return;
      // A failed attribute fetch collapses the section rather than blocking the
      // sheet: the universal filters below still work.
      setState(() {
        _attributes = const <CategoryAttribute>[];
        _loadingAttributes = false;
      });
    }
  }

  void _chooseCategory(Category? category) {
    setState(() {
      _draft = category == null
          ? _draft.copyWith(clearCategory: true, attributes: <String, String>{})
          : _draft.copyWith(
              categorySlug: category.slug,
              // Attribute values belong to the category that declared them.
              // Carrying `beds=3` into vehicles would send a filter the backend
              // has no attribute for.
              attributes: <String, String>{},
            );
      _attributes = const <CategoryAttribute>[];
    });
    if (category != null) _loadAttributes(category.slug);
  }

  void _setAttribute(String code, String? value) {
    final Map<String, String> next = Map<String, String>.of(_draft.attributes);
    if (value == null || value.isEmpty) {
      next.remove(code);
    } else {
      next[code] = value;
    }
    setState(() => _draft = _draft.copyWith(attributes: next));
  }

  void _clearAll() {
    HapticFeedback.mediumImpact();
    _minPrice.clear();
    _maxPrice.clear();
    setState(() {
      // The search text survives: clearing filters is not abandoning the search.
      _draft = ListingQuery(search: widget.current.search);
      _attributes = const <CategoryAttribute>[];
    });
  }

  void _apply() {
    final ListingQuery applied = _draft.copyWith(
      minPrice: int.tryParse(_minPrice.text.replaceAll(RegExp(r'\D'), '')),
      maxPrice: int.tryParse(_maxPrice.text.replaceAll(RegExp(r'\D'), '')),
    );
    Navigator.of(context).pop(applied);
  }

  @override
  Widget build(BuildContext context) {
    final LocationController location = Get.find<LocationController>();

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.background,
        borderRadius: AppRadius.sheetTop,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const SizedBox(height: AppSpacing.sm),
          Container(
            width: 38,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.screen,
              AppSpacing.md,
              AppSpacing.md,
              AppSpacing.md,
            ),
            child: Row(
              children: <Widget>[
                Expanded(child: Text('Filters', style: AppTypography.title)),
                TextButton(
                  onPressed: _clearAll,
                  child: const Text('Clear all'),
                ),
              ],
            ),
          ),
          const Divider(height: 1),

          Flexible(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(
                AppSpacing.screen,
                AppSpacing.lg,
                AppSpacing.screen,
                AppSpacing.xxl,
              ),
              physics: const BouncingScrollPhysics(),
              children: <Widget>[
                // --- category ---------------------------------------------
                _Section(
                  title: 'Category',
                  child: Wrap(
                    spacing: AppSpacing.sm,
                    runSpacing: AppSpacing.sm,
                    children: <Widget>[
                      _Chip(
                        label: 'All',
                        isSelected: _draft.categorySlug == null,
                        onTap: () => _chooseCategory(null),
                      ),
                      for (final Category category in _categories
                          .where((Category c) => c.listingCount > 0))
                        _Chip(
                          label: '${category.icon ?? ''} ${category.name}'.trim(),
                          isSelected: _draft.categorySlug == category.slug,
                          onTap: () => _chooseCategory(category),
                        ),
                    ],
                  ),
                ),

                // --- subcategory ------------------------------------------
                //
                // Only when the chosen vertical actually has children, so the
                // section never appears empty.
                if (_selectedCategory?.hasChildren ?? false)
                  _Section(
                    title: 'Subcategory',
                    child: Wrap(
                      spacing: AppSpacing.sm,
                      runSpacing: AppSpacing.sm,
                      children: <Widget>[
                        for (final Category child in _selectedCategory!.children)
                          _Chip(
                            label: child.name,
                            isSelected: _draft.categorySlug == child.slug,
                            onTap: () => _chooseCategory(child),
                          ),
                      ],
                    ),
                  ),

                // --- purpose -----------------------------------------------
                _Section(
                  title: 'Purpose',
                  child: Wrap(
                    spacing: AppSpacing.sm,
                    runSpacing: AppSpacing.sm,
                    children: <Widget>[
                      for (final (String value, String label) in const <
                          (String, String)>[
                        ('sale', 'For sale'),
                        ('rent', 'For rent'),
                        ('lease', 'For lease'),
                        ('hire', 'For hire'),
                      ])
                        _Chip(
                          label: label,
                          isSelected: _draft.purpose == value,
                          onTap: () => setState(
                            () => _draft = _draft.copyWith(
                              purpose: _draft.purpose == value ? null : value,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),

                // --- price --------------------------------------------------
                _Section(
                  title: 'Price (TZS)',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Expanded(
                            child: _PriceField(
                              controller: _minPrice,
                              hint: 'Min',
                            ),
                          ),
                          const Padding(
                            padding: EdgeInsets.symmetric(
                              horizontal: AppSpacing.md,
                            ),
                            child: Text('–'),
                          ),
                          Expanded(
                            child: _PriceField(
                              controller: _maxPrice,
                              hint: 'Max',
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.md),
                      // Quick bands, in the magnitudes Tanzanian listings
                      // actually use. Manual entry above always wins.
                      Wrap(
                        spacing: AppSpacing.sm,
                        runSpacing: AppSpacing.sm,
                        children: <Widget>[
                          for (final (int? min, int? max, String label)
                              in const <(int?, int?, String)>[
                            (null, 500000, 'Under 500K'),
                            (500000, 1000000, '500K – 1M'),
                            (1000000, 5000000, '1M – 5M'),
                            (5000000, 20000000, '5M – 20M'),
                            (20000000, 100000000, '20M – 100M'),
                            (100000000, null, '100M+'),
                          ])
                            _Chip(
                              label: label,
                              isSelected: _minPrice.text ==
                                      (min?.toString() ?? '') &&
                                  _maxPrice.text == (max?.toString() ?? ''),
                              onTap: () => setState(() {
                                _minPrice.text = min?.toString() ?? '';
                                _maxPrice.text = max?.toString() ?? '';
                              }),
                            ),
                        ],
                      ),
                    ],
                  ),
                ),

                // --- location ------------------------------------------------
                _Section(
                  title: 'Location',
                  child: Wrap(
                    spacing: AppSpacing.sm,
                    runSpacing: AppSpacing.sm,
                    children: <Widget>[
                      _Chip(
                        label: 'Anywhere',
                        isSelected: _draft.regionSlug == null,
                        onTap: () => setState(
                          () => _draft = _draft.copyWith(clearLocation: true),
                        ),
                      ),
                      for (final LocationOption region
                          in location.regions.where(
                        (LocationOption r) => r.listingCount > 0,
                      ))
                        _Chip(
                          label: '${region.name} (${Fmt.compactCount(region.listingCount)})',
                          isSelected: _draft.regionSlug == region.slug,
                          onTap: () => setState(
                            () => _draft = _draft.regionSlug == region.slug
                                ? _draft.copyWith(clearLocation: true)
                                : _draft.copyWith(regionSlug: region.slug),
                          ),
                        ),
                    ],
                  ),
                ),

                // --- category-specific --------------------------------------
                if (_loadingAttributes)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: AppSpacing.xxl),
                    child: Center(
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    ),
                  )
                else
                  for (final CategoryAttribute attribute in _attributes)
                    _AttributeSection(
                      attribute: attribute,
                      value: _draft.attributes[attribute.code],
                      onChanged: (String? value) =>
                          _setAttribute(attribute.code, value),
                    ),

                // --- trust ---------------------------------------------------
                _Section(
                  title: 'Other',
                  child: SwitchListTile.adaptive(
                    value: _draft.verifiedOnly,
                    onChanged: (bool value) => setState(
                      () => _draft = _draft.copyWith(verifiedOnly: value),
                    ),
                    title: Text('Verified sellers only', style: AppTypography.body),
                    subtitle: Text(
                      'Only listings from sellers SAKA has verified',
                      style: AppTypography.caption,
                    ),
                    activeThumbColor: AppColors.primary,
                    contentPadding: EdgeInsets.zero,
                  ),
                ),
              ],
            ),
          ),

          // The apply bar, pinned above the safe area so it is reachable
          // without scrolling to the bottom of a long filter list.
          Container(
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
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Cancel'),
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  flex: 2,
                  child: ElevatedButton(
                    onPressed: _apply,
                    child: Text(
                      _draft.activeCount > 0
                          ? 'Apply (${_draft.activeCount})'
                          : 'Apply',
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Category? get _selectedCategory {
    final String? slug = _draft.categorySlug;
    if (slug == null) return null;
    for (final Category root in _categories) {
      if (root.slug == slug) return root;
      for (final Category child in root.children) {
        if (child.slug == slug) return root;
      }
    }
    return null;
  }
}

/// One backend-declared attribute, rendered as the right control for its type.
class _AttributeSection extends StatelessWidget {
  const _AttributeSection({
    required this.attribute,
    required this.value,
    required this.onChanged,
  });

  final CategoryAttribute attribute;
  final String? value;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    // Options → chips. The labels are the backend's own.
    if (attribute.hasOptions) {
      return _Section(
        title: attribute.name,
        child: Wrap(
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: <Widget>[
            for (final AttributeOption option in attribute.options)
              _Chip(
                label: option.label,
                isSelected: value == option.value,
                onTap: () =>
                    onChanged(value == option.value ? null : option.value),
              ),
          ],
        ),
      );
    }

    // Boolean → a yes/no pair rather than a switch, so "not filtered" stays
    // distinguishable from "explicitly no".
    if (attribute.isBoolean) {
      return _Section(
        title: attribute.name,
        child: Wrap(
          spacing: AppSpacing.sm,
          children: <Widget>[
            _Chip(
              label: 'Yes',
              isSelected: value == '1',
              onTap: () => onChanged(value == '1' ? null : '1'),
            ),
            _Chip(
              label: 'No',
              isSelected: value == '0',
              onTap: () => onChanged(value == '0' ? null : '0'),
            ),
          ],
        ),
      );
    }

    // Numeric → "1+, 2+, 3+…", bounded by the backend's own min/max so a
    // bedrooms filter never offers fifty.
    if (attribute.isNumeric) {
      final int max = (attribute.maxValue ?? 6).clamp(1, 8).toInt();
      return _Section(
        title: attribute.unit == null
            ? attribute.name
            : '${attribute.name} (${attribute.unit})',
        child: Wrap(
          spacing: AppSpacing.sm,
          runSpacing: AppSpacing.sm,
          children: <Widget>[
            for (int i = 1; i <= max; i++)
              _Chip(
                label: i == max ? '$i+' : '$i',
                isSelected: value == '$i',
                onTap: () => onChanged(value == '$i' ? null : '$i'),
              ),
          ],
        ),
      );
    }

    // A free-text attribute has no sensible mobile control and no option list
    // to draw from, so it is omitted rather than rendered as an empty box.
    return const SizedBox.shrink();
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.xxl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(title, style: AppTypography.label),
          const SizedBox(height: AppSpacing.md),
          child,
        ],
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({
    required this.label,
    required this.isSelected,
    required this.onTap,
  });

  final String label;
  final bool isSelected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressableScale(
      onTap: onTap,
      scale: 0.95,
      child: AnimatedContainer(
        duration: AppMotion.instant,
        // 40pt tall with 12pt of vertical padding — comfortably tappable
        // without the chips becoming buttons.
        constraints: const BoxConstraints(minHeight: 40),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.primary : AppColors.muted,
          borderRadius: AppRadius.pillAll,
        ),
        child: Text(
          label,
          style: AppTypography.caption.copyWith(
            color: isSelected ? Colors.white : AppColors.navy,
            fontWeight: FontWeight.w700,
            fontSize: 13,
          ),
        ),
      ),
    );
  }
}

class _PriceField extends StatelessWidget {
  const _PriceField({required this.controller, required this.hint});

  final TextEditingController controller;
  final String hint;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: TextInputType.number,
      inputFormatters: <TextInputFormatter>[
        FilteringTextInputFormatter.digitsOnly,
      ],
      style: AppTypography.body,
      cursorColor: AppColors.primary,
      decoration: InputDecoration(
        hintText: hint,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.lg,
          vertical: AppSpacing.md,
        ),
      ),
    );
  }
}
