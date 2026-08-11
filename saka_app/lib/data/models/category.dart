import 'json.dart';

/// A node in the SAKA taxonomy.
///
/// The tree arrives whole from `GET /categories` — 12 verticals with their
/// children — which is why it is cached aggressively: it changes when an
/// administrator edits it, i.e. almost never, and every filter sheet in the app
/// needs it.
class Category {
  const Category({
    required this.slug,
    required this.name,
    required this.depth,
    this.icon,
    this.description,
    this.isLeaf = true,
    this.listingCount = 0,
    this.imageUrl,
    this.children = const <Category>[],
  });

  final String slug;
  final String name;
  final int depth;

  /// An emoji from the seeder ("🏠", "🚗"). Rendered as text, not as an icon
  /// font — inventing a Material icon per category would drift from the web,
  /// which shows these same emoji.
  final String? icon;

  final String? description;
  final bool isLeaf;
  final int listingCount;
  final String? imageUrl;
  final List<Category> children;

  bool get hasChildren => children.isNotEmpty;

  static Category? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? slug = asString(json['slug']);
    if (slug == null) return null;

    return Category(
      slug: slug,
      name: asStringOr(json['name'], slug),
      depth: asIntOr(json['depth'], 0),
      icon: asString(json['icon']),
      description: asString(json['description']),
      isLeaf: asBool(json['is_leaf'], fallback: true),
      listingCount: asIntOr(json['listing_count'], 0),
      imageUrl: asString(json['image_url']),
      children: parseList(json['children']),
    );
  }

  static List<Category> parseList(dynamic value) {
    return <Category>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final Category category) category,
    ];
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
        'slug': slug,
        'name': name,
        'depth': depth,
        'icon': icon,
        'description': description,
        'is_leaf': isLeaf,
        'listing_count': listingCount,
        'image_url': imageUrl,
        'children': children.map((Category c) => c.toJson()).toList(),
      };
}

/// A filterable attribute, as declared by `GET /categories/{slug}/attributes`.
///
/// This is what makes the filter sheet category-aware without a single
/// hardcoded rule: "Bedrooms" appears under property because the backend says
/// property has it, and never appears under vehicles because it does not.
class CategoryAttribute {
  const CategoryAttribute({
    required this.code,
    required this.name,
    required this.dataType,
    this.inputType,
    this.unit,
    this.isFilterable = false,
    this.isRequired = false,
    this.minValue,
    this.maxValue,
    this.options = const <AttributeOption>[],
  });

  final String code;
  final String name;

  /// `string` | `integer` | `decimal` | `boolean` | `date`. Chooses the control:
  /// a range slider for numbers, a chip group for options, a switch for booleans.
  final String dataType;

  /// The backend's own UI hint (`number`, `select`, `text`…). Preferred over
  /// [dataType] when present, because it distinguishes cases the storage type
  /// cannot — a `select` of integers is still a chip group, not a slider.
  final String? inputType;

  final String? unit;
  final bool isFilterable;
  final bool isRequired;

  /// Bounds for the range control. Without them a slider has to invent its own
  /// ceiling, which is how a "bedrooms" filter ends up offering 5,000.
  final num? minValue;
  final num? maxValue;

  final List<AttributeOption> options;

  bool get isNumeric => dataType == 'integer' || dataType == 'decimal';
  bool get isBoolean => dataType == 'boolean';
  bool get hasOptions => options.isNotEmpty;

  static CategoryAttribute? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? code = asString(json['code']);
    if (code == null) return null;

    return CategoryAttribute(
      code: code,
      name: asStringOr(json['name'], code),
      dataType: asStringOr(json['data_type'], 'string'),
      inputType: asString(json['input_type']),
      unit: asString(json['unit']),
      isFilterable: asBool(json['is_filterable']),
      isRequired: asBool(json['is_required']),
      minValue: asDouble(json['min_value']),
      maxValue: asDouble(json['max_value']),
      options: <AttributeOption>[
        for (final Map<String, dynamic> item in asMapList(json['options']))
          if (AttributeOption.tryParse(item) case final AttributeOption option)
            option,
      ],
    );
  }

  static List<CategoryAttribute> parseList(dynamic value) {
    return <CategoryAttribute>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final CategoryAttribute attribute) attribute,
    ];
  }
}

class AttributeOption {
  const AttributeOption({required this.value, required this.label});

  final String value;
  final String label;

  static AttributeOption? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? v = asString(json['value']);
    if (v == null) return null;
    return AttributeOption(value: v, label: asStringOr(json['label'], v));
  }
}
