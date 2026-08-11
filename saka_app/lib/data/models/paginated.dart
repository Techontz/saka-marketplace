import 'json.dart';

/// A page of results, plus enough to know whether to ask for another.
///
/// Laravel's paginator sends `meta.current_page` / `meta.last_page`. Reading
/// `links.next` instead would also work, but the page numbers are what the
/// infinite-scroll controller needs to guarantee it never requests the same
/// page twice.
class Paginated<T> {
  const Paginated({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
    this.perPage = 20,
  });

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int total;
  final int perPage;

  bool get hasMore => currentPage < lastPage;
  int get nextPage => currentPage + 1;
  bool get isEmpty => items.isEmpty;

  const Paginated.empty()
      : items = const <Never>[],
        currentPage = 1,
        lastPage = 1,
        total = 0,
        perPage = 20;

  static Paginated<T> parse<T>(
    dynamic body,
    T? Function(dynamic item) parseItem,
  ) {
    final Map<String, dynamic> json = asMap(body);
    final Map<String, dynamic> meta = asMap(json['meta']);

    final List<T> items = <T>[
      for (final dynamic item in (json['data'] is List
          ? json['data'] as List<dynamic>
          : const <dynamic>[]))
        if (parseItem(item) case final T parsed) parsed,
    ];

    // Endpoints that return a bare list with no meta (the featured/trending
    // rails do) are treated as a single complete page rather than as page 1 of
    // an unknown number — otherwise the scroll controller asks for page 2 of a
    // resource that has no page 2 and gets the same rows back forever.
    final int lastPage = asIntOr(meta['last_page'], 1);

    return Paginated<T>(
      items: items,
      currentPage: asIntOr(meta['current_page'], 1),
      lastPage: lastPage,
      total: asIntOr(meta['total'], items.length),
      perPage: asIntOr(meta['per_page'], items.isEmpty ? 20 : items.length),
    );
  }

  Paginated<T> merge(Paginated<T> next) {
    return Paginated<T>(
      items: <T>[...items, ...next.items],
      currentPage: next.currentPage,
      lastPage: next.lastPage,
      total: next.total,
      perPage: next.perPage,
    );
  }
}
