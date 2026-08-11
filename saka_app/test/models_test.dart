import 'package:flutter_test/flutter_test.dart';
import 'package:saka_app/data/models/listing.dart';
import 'package:saka_app/data/models/media.dart';
import 'package:saka_app/data/models/paginated.dart';
import 'package:saka_app/data/models/user.dart';

/// Model tests written against SHAPES CAPTURED FROM THE LIVE API, not from the
/// TypeScript types. Each group below pins a real inconsistency that would
/// crash or silently corrupt the app if the parser assumed one form.
void main() {
  group('attributes: map on index, array on detail', () {
    test('parses the MAP form the listing index sends', () {
      final Listing? listing = Listing.tryParse(<String, dynamic>{
        'slug': 'a-plot',
        'title': 'A plot',
        'attributes': <String, dynamic>{'beds': '3', 'sqft': '180'},
      });

      expect(listing, isNotNull);
      expect(listing!.attributes, hasLength(2));
      expect(listing.attributes.first.code, 'beds');
      expect(listing.attributes.first.value, '3');
    });

    test('parses the ARRAY form the detail resource sends', () {
      final Listing? listing = Listing.tryParse(<String, dynamic>{
        'slug': 'a-plot',
        'title': 'A plot',
        'attributes': <dynamic>[
          <String, dynamic>{
            'code': 'beds',
            'name': 'Bedrooms',
            'value': '3',
            'unit': null,
            'label': null,
          },
        ],
      });

      expect(listing!.attributes.single.name, 'Bedrooms');
      expect(listing.attributes.single.displayValue, '3');
    });

    test('appends the unit and prefers the option label', () {
      final Listing? listing = Listing.tryParse(<String, dynamic>{
        'slug': 'x',
        'title': 'x',
        'attributes': <dynamic>[
          <String, dynamic>{'code': 'area', 'value': '180', 'unit': 'm²'},
          <String, dynamic>{
            'code': 'furnishing',
            'value': 'furnished',
            'label': 'Fully furnished',
          },
        ],
      });

      expect(listing!.attributes[0].displayValue, '180 m²');
      expect(listing.attributes[1].displayValue, 'Fully furnished');
    });
  });

  group('rating_avg: string from /auth/me, number from listings', () {
    test('accepts the quoted decimal', () {
      final SellerProfileSummary? profile =
          SellerProfileSummary.tryParse(<String, dynamic>{
        'slug': 'juma',
        'display_name': 'Juma',
        'rating_avg': '4.25',
      });
      expect(profile!.ratingAverage, 4.25);
    });

    test('accepts the bare number', () {
      final SellerRef? seller = SellerRef.tryParse(<String, dynamic>{
        'slug': 'juma',
        'display_name': 'Juma',
        'rating_avg': 5,
      });
      expect(seller!.ratingAverage, 5.0);
    });
  });

  group('media.variants: [] when empty, {} when populated', () {
    test('an empty LIST does not crash and yields the original url', () {
      final MediaImage? image = MediaImage.tryParse(<String, dynamic>{
        'url': 'https://cdn/original.jpg',
        'variants': <dynamic>[],
      });

      expect(image, isNotNull);
      expect(image!.variants, isEmpty);
      expect(image.srcFor(MediaSize.card), 'https://cdn/original.jpg');
    });

    test('a populated MAP resolves the right rendition', () {
      final MediaImage? image = MediaImage.tryParse(<String, dynamic>{
        'url': 'https://cdn/original.jpg',
        'variants': <String, dynamic>{
          'thumb': <String, dynamic>{'url': 'https://cdn/t.webp'},
          'card': <String, dynamic>{'url': 'https://cdn/c.webp'},
          'full': <String, dynamic>{'url': 'https://cdn/f.webp'},
        },
      });

      expect(image!.srcFor(MediaSize.card), 'https://cdn/c.webp');
      expect(image.srcFor(MediaSize.full), 'https://cdn/f.webp');
    });

    test('falls DOWN the ladder rather than to the original', () {
      // `detail` is missing; a soft `card` beats a multi-megabyte original in
      // a phone-sized box.
      final MediaImage? image = MediaImage.tryParse(<String, dynamic>{
        'url': 'https://cdn/original.jpg',
        'variants': <String, dynamic>{
          'card': <String, dynamic>{'url': 'https://cdn/c.webp'},
        },
      });

      expect(image!.srcFor(MediaSize.detail), 'https://cdn/c.webp');
    });
  });

  group('pagination', () {
    test('reads Laravel meta and knows there is another page', () {
      final Paginated<Listing> page = Paginated.parse<Listing>(
        <String, dynamic>{
          'data': <dynamic>[
            <String, dynamic>{'slug': 'a', 'title': 'A'},
          ],
          'meta': <String, dynamic>{
            'current_page': 1,
            'last_page': 209,
            'total': 209,
            'per_page': 1,
          },
        },
        Listing.tryParse,
      );

      expect(page.items, hasLength(1));
      expect(page.hasMore, isTrue);
      expect(page.nextPage, 2);
    });

    test('an unpaginated rail is ONE complete page', () {
      // The featured/trending endpoints send a bare list. Treating that as
      // page 1 of many would make an infinite scroller loop forever.
      final Paginated<Listing> page = Paginated.parse<Listing>(
        <String, dynamic>{
          'data': <dynamic>[
            <String, dynamic>{'slug': 'a', 'title': 'A'},
          ],
        },
        Listing.tryParse,
      );

      expect(page.hasMore, isFalse);
    });

    test('drops unparseable rows instead of failing the page', () {
      final Paginated<Listing> page = Paginated.parse<Listing>(
        <String, dynamic>{
          'data': <dynamic>[
            <String, dynamic>{'slug': 'good', 'title': 'Good'},
            <String, dynamic>{'title': 'No slug'},
            'not an object',
          ],
        },
        Listing.tryParse,
      );

      expect(page.items, hasLength(1));
      expect(page.items.single.slug, 'good');
    });
  });

  group('price', () {
    test('a missing amount is "on request", never zero', () {
      final Listing? listing = Listing.tryParse(<String, dynamic>{
        'slug': 'x',
        'title': 'x',
        'price': <String, dynamic>{'amount': null, 'currency': 'TZS'},
      });

      expect(listing!.price.onRequest, isTrue);
      expect(listing.price.amount, isNull);
    });
  });
}
