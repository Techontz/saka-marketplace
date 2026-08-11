import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:latlong2/latlong.dart';

import '../../app/theme/app_colors.dart';
import '../../app/theme/app_tokens.dart';
import '../../app/theme/app_typography.dart';
import '../../core/errors/api_exception.dart';
import '../../core/utils/formatters.dart';
import '../../data/models/boundary.dart';
import '../../data/repositories/vendor_repository.dart';
import '../../shared/widgets/saka_text_field.dart';
import 'boundary_map.dart';

/// Drawing a land parcel.
///
/// Vendor-only, reached from a listing the vendor owns — the backend authorises
/// every call with `manageMedia`, so a request for somebody else's listing is
/// refused server-side regardless of what this screen does.
///
/// The client owns GEOMETRY ONLY. Area and perimeter are whatever
/// `LandBoundaryService` computes and returns; nothing here measures land. A
/// phone that reports its own acreage would eventually disagree with the
/// website about the same plot, and on a land listing that is a dispute.
class BoundaryEditorScreen extends StatefulWidget {
  const BoundaryEditorScreen({
    required this.listingUuid,
    required this.listingTitle,
    super.key,
    this.initialCentre,
  });

  final String listingUuid;
  final String listingTitle;

  /// The listing's own pin, so a new parcel starts over the right village
  /// rather than over Dar es Salaam.
  final LatLng? initialCentre;

  @override
  State<BoundaryEditorScreen> createState() => _BoundaryEditorScreenState();
}

class _BoundaryEditorScreenState extends State<BoundaryEditorScreen> {
  final VendorRepository _repository = Get.find<VendorRepository>();
  final TextEditingController _reference = TextEditingController();
  final TextEditingController _notes = TextEditingController();

  List<LatLng> _points = <LatLng>[];

  /// Every state the shape has been in, for undo. Bounded at 50 — a vendor
  /// placing corners does not need unlimited history, and an unbounded list of
  /// coordinate arrays is a slow leak on a long editing session.
  final List<List<LatLng>> _history = <List<LatLng>>[];

  ListingBoundary? _saved;
  ApiException? _error;
  bool _loading = true;
  bool _saving = false;
  bool _dirty = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reference.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final ListingBoundary? existing =
          await _repository.boundary(widget.listingUuid);
      if (!mounted) return;
      setState(() {
        _saved = existing;
        _points = existing?.outerRing ?? <LatLng>[];
        _reference.text = existing?.surveyReference ?? '';
        _notes.text = existing?.notes ?? '';
        _loading = false;
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        // A 404 simply means "no parcel yet", which is the normal starting
        // state for a new listing — not an error worth showing.
        _error = error.kind == ApiErrorKind.notFound ? null : error;
        _loading = false;
      });
    } on Object {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _push(List<LatLng> next) {
    setState(() {
      if (_history.length >= 50) _history.removeAt(0);
      _history.add(List<LatLng>.of(_points));
      _points = next;
      _dirty = true;
    });
  }

  void _undo() {
    if (_history.isEmpty) return;
    HapticFeedback.selectionClick();
    setState(() {
      _points = _history.removeLast();
      _dirty = true;
    });
  }

  void _removeVertex(int index) {
    if (index < 0 || index >= _points.length) return;
    HapticFeedback.lightImpact();
    final List<LatLng> next = List<LatLng>.of(_points)..removeAt(index);
    _push(next);
  }

  Future<void> _confirmRemove(int index) async {
    // Below four corners, removing one stops it being a polygon at all — worth
    // saying so rather than silently collapsing the shape.
    final bool wouldBreak = _points.length <= 3;

    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: Text('Remove corner ${index + 1}?'),
        content: Text(
          wouldBreak
              ? 'A boundary needs at least three corners. Removing this one '
                  'will leave an incomplete shape you cannot save until you '
                  'add another.'
              : 'The boundary will close across the remaining corners.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.destructive),
            child: const Text('Remove'),
          ),
        ],
      ),
    );

    if (confirmed ?? false) _removeVertex(index);
  }

  Future<void> _save() async {
    if (_points.length < 3) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final ListingBoundary boundary = await _repository.saveBoundary(
        listingUuid: widget.listingUuid,
        // Closed on the way out — the backend counts vertices as `length - 1`
        // and its geometry checks assume a closed ring.
        rings: ListingBoundary.toRings(_points),
        surveyReference: _reference.text.trim().isEmpty
            ? null
            : _reference.text.trim(),
        notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      );

      if (!mounted) return;
      HapticFeedback.mediumImpact();
      setState(() {
        _saved = boundary;
        // Re-seeded from the SERVER's response, not from local state: if the
        // backend normalised the winding or dropped a duplicate corner, the map
        // must show what was actually stored.
        _points = boundary.outerRing;
        _history.clear();
        _dirty = false;
        _saving = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = ApiException.from(error);
        _saving = false;
      });
    }
  }

  Future<void> _delete() async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Remove this boundary?'),
        content: const Text(
          'The plot outline will be deleted from the listing. Buyers will no '
          'longer see the shaded parcel.',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.destructive),
            child: const Text('Remove'),
          ),
        ],
      ),
    );
    if (!(confirmed ?? false)) return;

    try {
      await _repository.deleteBoundary(widget.listingUuid);
      if (!mounted) return;
      setState(() {
        _saved = null;
        _points = <LatLng>[];
        _history.clear();
        _dirty = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() => _error = ApiException.from(error));
    }
  }

  /// Guards the back gesture when there is unsaved geometry. Losing a
  /// hand-placed parcel to a stray swipe is not recoverable.
  Future<bool> _confirmLeave() async {
    if (!_dirty) return true;
    final bool? leave = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        title: const Text('Discard changes?'),
        content: const Text('This boundary has not been saved.'),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep editing'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: AppColors.destructive),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    return leave ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final bool canSave = _points.length >= 3 && _dirty && !_saving;

    return PopScope<void>(
      canPop: !_dirty,
      onPopInvokedWithResult: (bool didPop, void _) async {
        if (didPop) return;
        if (await _confirmLeave() && context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        backgroundColor: AppColors.page,
        appBar: AppBar(
          title: const Text('Land boundary'),
          actions: <Widget>[
            if (_history.isNotEmpty)
              IconButton(
                onPressed: _undo,
                icon: const Icon(Icons.undo_rounded, size: 21),
                tooltip: 'Undo',
              ),
            if (_saved != null)
              IconButton(
                onPressed: _delete,
                icon: const Icon(Icons.delete_outline_rounded, size: 21),
                color: AppColors.destructive,
                tooltip: 'Remove boundary',
              ),
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator(strokeWidth: 2))
            : Column(
                children: <Widget>[
                  Expanded(
                    child: Stack(
                      children: <Widget>[
                        BoundaryMap(
                          points: _points,
                          isEditable: true,
                          fallbackCentre: widget.initialCentre,
                          onPointsChanged: _push,
                          onVertexTapped: _confirmRemove,
                        ),
                        if (_points.isEmpty)
                          const Positioned(
                            left: AppSpacing.md,
                            right: 70,
                            top: AppSpacing.md,
                            child: _Hint(
                              text: 'Tap each corner of the plot. Add at least '
                                  'three, then drag any corner to adjust it.',
                            ),
                          )
                        else if (_points.length < 3)
                          Positioned(
                            left: AppSpacing.md,
                            right: 70,
                            top: AppSpacing.md,
                            child: _Hint(
                              text: '${3 - _points.length} more '
                                  '${_points.length == 2 ? 'corner' : 'corners'} '
                                  'needed to close the shape.',
                            ),
                          )
                        else
                          const Positioned(
                            left: AppSpacing.md,
                            right: 70,
                            top: AppSpacing.md,
                            child: _Hint(
                              text: 'Tap a numbered corner to remove it, or '
                                  'drag it to move it.',
                            ),
                          ),
                      ],
                    ),
                  ),
                  _Toolbar(
                    points: _points,
                    saved: _saved,
                    dirty: _dirty,
                    saving: _saving,
                    canSave: canSave,
                    error: _error,
                    reference: _reference,
                    notes: _notes,
                    onSave: _save,
                  ),
                ],
              ),
      ),
    );
  }
}

class _Hint extends StatelessWidget {
  const _Hint({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      decoration: BoxDecoration(
        color: AppColors.navy.withValues(alpha: 0.86),
        borderRadius: AppRadius.mdAll,
      ),
      child: Text(
        text,
        style: AppTypography.caption.copyWith(
          color: Colors.white,
          height: 1.35,
        ),
      ),
    );
  }
}

class _Toolbar extends StatelessWidget {
  const _Toolbar({
    required this.points,
    required this.saved,
    required this.dirty,
    required this.saving,
    required this.canSave,
    required this.error,
    required this.reference,
    required this.notes,
    required this.onSave,
  });

  final List<LatLng> points;
  final ListingBoundary? saved;
  final bool dirty;
  final bool saving;
  final bool canSave;
  final ApiException? error;
  final TextEditingController reference;
  final TextEditingController notes;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    return Container(
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
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: _Readout(
                  label: 'Corners',
                  value: '${points.length}',
                ),
              ),
              Expanded(
                child: _Readout(
                  label: 'Area',
                  // Blank until the server has measured it. Showing a
                  // client-side estimate that changes on save would be worse
                  // than showing nothing.
                  value: saved != null && !dirty ? saved!.areaDisplay : '—',
                  hint: dirty && points.length >= 3 ? 'Save to measure' : null,
                ),
              ),
              Expanded(
                child: _Readout(
                  label: 'Perimeter',
                  value:
                      saved != null && !dirty ? saved!.perimeterDisplay : '—',
                ),
              ),
            ],
          ),

          if (saved != null && !dirty) ...<Widget>[
            const SizedBox(height: 4),
            Text(
              '${Fmt.thousands(saved!.areaSqm)} m² · '
              '${saved!.areaAcres.toStringAsFixed(2)} acres · '
              'measured by SAKA',
              style: AppTypography.caption.copyWith(fontSize: 11),
            ),
          ],

          if (error != null) ...<Widget>[
            const SizedBox(height: AppSpacing.sm),
            Text(
              // Surfaces the backend's own geometry message —
              // "A land boundary needs at least three corners",
              // "The boundary edges cross" — which is far more useful than a
              // generic failure.
              error!.fieldError('rings') ?? error!.message,
              style: AppTypography.caption.copyWith(
                color: AppColors.destructive,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],

          const SizedBox(height: AppSpacing.md),
          Row(
            children: <Widget>[
              Expanded(
                child: OutlinedButton(
                  onPressed: () => _openDetails(context, reference, notes),
                  child: const Text('Details'),
                ),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                flex: 2,
                child: ElevatedButton(
                  onPressed: canSave ? onSave : null,
                  child: saving
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : Text(
                          points.length < 3
                              ? 'Add ${3 - points.length} more'
                              : dirty
                                  ? 'Save boundary'
                                  : 'Saved',
                        ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _openDetails(
    BuildContext context,
    TextEditingController reference,
    TextEditingController notes,
  ) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (BuildContext context) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: Container(
          decoration: const BoxDecoration(
            color: AppColors.background,
            borderRadius: AppRadius.sheetTop,
          ),
          padding: EdgeInsets.fromLTRB(
            AppSpacing.screen,
            AppSpacing.xl,
            AppSpacing.screen,
            AppSpacing.xl + MediaQuery.paddingOf(context).bottom,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text('Parcel details', style: AppTypography.title),
              const SizedBox(height: AppSpacing.lg),
              SakaTextField(
                controller: reference,
                label: 'Survey reference (optional)',
                hint: 'e.g. Plot 214, Block C',
                maxLength: 120,
              ),
              const SizedBox(height: AppSpacing.md),
              SakaTextField(
                controller: notes,
                label: 'Notes (optional)',
                hint: 'Anything a buyer should know about the plot',
                maxLines: 3,
                maxLength: 2000,
              ),
              const SizedBox(height: AppSpacing.xl),
              ElevatedButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Done'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Readout extends StatelessWidget {
  const _Readout({required this.label, required this.value, this.hint});

  final String label;
  final String value;
  final String? hint;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(label, style: AppTypography.caption.copyWith(fontSize: 11)),
        const SizedBox(height: 1),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTypography.label.copyWith(fontSize: 15),
        ),
        if (hint != null)
          Text(
            hint!,
            style: AppTypography.caption.copyWith(
              fontSize: 10,
              color: AppColors.warning,
            ),
          ),
      ],
    );
  }
}
