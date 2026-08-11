import 'json.dart';
import 'listing.dart';

/// A bookable service on a specialist's menu.
class SpecialistService {
  const SpecialistService({
    required this.uuid,
    required this.name,
    required this.durationMinutes,
    this.description,
    this.mode,
    this.modeLabel,
    this.isActive = true,
    this.price,
  });

  final String uuid;
  final String name;
  final int durationMinutes;
  final String? description;

  /// `online` | `in_person` | `both`.
  final String? mode;
  final String? modeLabel;

  final bool isActive;

  /// Null means the specialist did not publish a price. The UI says "Price on
  /// request" — it never shows 0, which reads as free.
  final Price? price;

  String get durationLabel {
    if (durationMinutes < 60) return '$durationMinutes min';
    final int hours = durationMinutes ~/ 60;
    final int minutes = durationMinutes % 60;
    if (minutes == 0) return hours == 1 ? '1 hour' : '$hours hours';
    return '${hours}h ${minutes}m';
  }

  static SpecialistService? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;

    final Map<String, dynamic> price = asMap(json['price']);

    return SpecialistService(
      uuid: uuid,
      name: asStringOr(json['name'], 'Service'),
      durationMinutes: asIntOr(json['duration_minutes'], 60),
      description: asString(json['description']),
      mode: asString(json['mode']),
      modeLabel: asString(json['mode_label']),
      isActive: asBool(json['is_active'], fallback: true),
      price: price.isEmpty || price['amount'] == null
          ? null
          : Price.parse(price),
    );
  }

  static List<SpecialistService> parseList(dynamic value) {
    return <SpecialistService>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final SpecialistService service) service,
    ];
  }
}

/// One day of the availability grid.
class SlotDay {
  const SlotDay({required this.date, required this.slots});

  /// `yyyy-MM-dd`, in the SPECIALIST's timezone — not the device's.
  final String date;

  final List<TimeSlot> slots;

  bool get hasSlots => slots.isNotEmpty;

  DateTime? get parsedDate => DateTime.tryParse(date);

  static List<SlotDay> parseList(dynamic value) {
    return <SlotDay>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (asString(item['date']) case final String date)
          SlotDay(
            date: date,
            slots: <TimeSlot>[
              for (final Map<String, dynamic> slot in asMapList(item['slots']))
                if (TimeSlot.tryParse(slot) case final TimeSlot parsed) parsed,
            ],
          ),
    ];
  }
}

class TimeSlot {
  const TimeSlot({required this.start, required this.end});

  /// `HH:mm`.
  final String start;
  final String end;

  static TimeSlot? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? start = asString(json['start']);
    if (start == null) return null;
    return TimeSlot(start: start, end: asStringOr(json['end'], start));
  }
}

/// The availability response, which carries its own timezone.
///
/// The timezone is load-bearing. A slot is "09:00 in Africa/Dar_es_Salaam", and
/// converting it into the device's local time would show a customer in London
/// an appointment at 06:00 that the specialist believes is at 09:00. The app
/// therefore renders the strings the backend sent, and labels them with the
/// specialist's timezone whenever it differs from the device's.
class Availability {
  const Availability({
    required this.days,
    required this.timezone,
    required this.hasAvailability,
    this.service,
  });

  final List<SlotDay> days;
  final String timezone;
  final bool hasAvailability;
  final SpecialistService? service;

  List<SlotDay> get daysWithSlots =>
      days.where((SlotDay d) => d.hasSlots).toList(growable: false);

  static Availability parse(dynamic body) {
    final Map<String, dynamic> json = asMap(body);
    final Map<String, dynamic> meta = asMap(json['meta']);
    return Availability(
      days: SlotDay.parseList(json['data']),
      timezone: asStringOr(meta['timezone'], 'Africa/Dar_es_Salaam'),
      hasAvailability: asBool(meta['has_availability'], fallback: true),
      service: SpecialistService.tryParse(meta['service']),
    );
  }
}

/// A booking, from the customer's side.
class Booking {
  const Booking({
    required this.uuid,
    required this.scheduledDate,
    required this.startTime,
    required this.endTime,
    required this.status,
    required this.statusLabel,
    this.timezone,
    this.startsAtUtc,
    this.isCancellable = false,
    this.awaitsSpecialist = false,
    this.serviceName,
    this.serviceDurationMinutes,
    this.specialistSlug,
    this.specialistTitle,
    this.customerNote,
    this.specialistNote,
    this.createdAt,
  });

  final String uuid;
  final String scheduledDate;
  final String startTime;
  final String endTime;

  /// `pending` | `confirmed` | `completed` | `cancelled` | `declined` | `no_show`.
  final String status;

  final String statusLabel;
  final String? timezone;

  /// The one absolute instant on the record. Used for upcoming/past sorting,
  /// because comparing the local date strings would be wrong across timezones.
  final DateTime? startsAtUtc;

  final bool isCancellable;
  final bool awaitsSpecialist;
  final String? serviceName;
  final int? serviceDurationMinutes;
  final String? specialistSlug;
  final String? specialistTitle;
  final String? customerNote;
  final String? specialistNote;
  final DateTime? createdAt;

  bool get isUpcoming {
    final DateTime? at = startsAtUtc;
    if (at == null) return false;
    if (status == 'cancelled' || status == 'declined') return false;
    return at.isAfter(DateTime.now().toUtc());
  }

  bool get isCancelled => status == 'cancelled' || status == 'declined';

  static Booking? tryParse(dynamic value) {
    final Map<String, dynamic> json = asMap(value);
    final String? uuid = asString(json['uuid']);
    if (uuid == null) return null;

    final Map<String, dynamic> service = asMap(json['service']);
    final Map<String, dynamic> specialist = asMap(json['specialist']);

    return Booking(
      uuid: uuid,
      scheduledDate: asStringOr(json['scheduled_date'], ''),
      startTime: asStringOr(json['start_time'], ''),
      endTime: asStringOr(json['end_time'], ''),
      status: asStringOr(json['status'], 'pending'),
      statusLabel: asStringOr(json['status_label'], 'Pending'),
      timezone: asString(json['timezone']),
      startsAtUtc: asDate(json['starts_at_utc']),
      isCancellable: asBool(json['is_cancellable']),
      awaitsSpecialist: asBool(json['awaits_specialist']),
      serviceName: asString(service['name']),
      serviceDurationMinutes: asInt(service['duration_minutes']),
      specialistSlug: asString(specialist['slug']),
      specialistTitle: asString(specialist['title']),
      customerNote: asString(json['customer_note']),
      specialistNote: asString(json['specialist_note']),
      createdAt: asDate(json['created_at']),
    );
  }

  static List<Booking> parseList(dynamic value) {
    return <Booking>[
      for (final Map<String, dynamic> item in asMapList(value))
        if (tryParse(item) case final Booking booking) booking,
    ];
  }
}
