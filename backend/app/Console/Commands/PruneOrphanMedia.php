<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Media\MediaUploadService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes media whose owner no longer exists.
 *
 * Media is POLYMORPHIC, so there is no foreign key and nothing cascades. A row
 * that is force-deleted — a listing purged by an administrator, a seller
 * profile removed — leaves its media rows behind, and with them the files and
 * every generated variant on disk. Storage then grows forever with images
 * nothing can reach.
 *
 * TWO RULES, both learned the hard way:
 *
 *  1. The owner table is resolved FROM THE MORPH CLASS ITSELF, never from a
 *     hand-written list. An earlier version of this command carried a list of
 *     known owner types and deleted anything outside it — which quietly
 *     destroyed every public-place category image, because that type simply
 *     was not on the list.
 *
 *  2. A type this command cannot resolve is REPORTED AND SKIPPED, never
 *     deleted. "I don't recognise this" is not evidence that something is
 *     orphaned, and a cleanup job must only delete what it can prove.
 *
 * Soft-deleted owners are deliberately left alone: a soft-deleted listing can
 * be restored, and restoring one with no photos is worse than keeping a few
 * megabytes.
 */
class PruneOrphanMedia extends Command
{
    protected $signature = 'saka:media:prune-orphans {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete media rows and files whose owning record no longer exists';

    public function handle(MediaUploadService $media): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;

        foreach ($this->orphanIds() as $id) {
            $row = Media::find($id);

            if ($row === null) {
                continue;
            }

            $this->line(($dryRun ? '[dry-run] ' : '').'orphan media '.$row->uuid.' → '.$row->path);

            if (! $dryRun) {
                $media->delete($row);
            }

            $deleted++;
        }

        $this->info($dryRun
            ? "{$deleted} orphaned media row(s) would be deleted."
            : "{$deleted} orphaned media row(s) deleted.");

        return self::SUCCESS;
    }

    /**
     * Ids whose owner row is provably gone.
     *
     * One anti-join per owner type against that model's own table, rather than
     * a per-row existence query.
     *
     * @return array<int, int>
     */
    private function orphanIds(): array
    {
        $ids = [];

        /** @var array<int, string> $types */
        $types = DB::table('media')
            ->whereNotNull('mediable_type')
            ->distinct()
            ->pluck('mediable_type')
            ->all();

        foreach ($types as $type) {
            $model = $this->resolve($type);

            if ($model === null) {
                // Unresolvable: report loudly and leave it completely alone.
                $count = DB::table('media')->where('mediable_type', $type)->count();

                $this->warn("Skipping {$count} media row(s) owned by [{$type}] — that class could not be resolved. Nothing was deleted for this type.");
                Log::warning('media.prune.unresolvable_type', ['type' => $type, 'rows' => $count]);

                continue;
            }

            $table = $model->getTable();
            $key = $model->getKeyName();

            $query = DB::table('media')
                ->leftJoin($table, "{$table}.{$key}", '=', 'media.mediable_id')
                ->where('media.mediable_type', $type)
                ->whereNull("{$table}.{$key}");

            $ids = array_merge($ids, $query->pluck('media.id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The model behind a morph value, whether it is an alias or a class name.
     */
    private function resolve(string $type): ?Model
    {
        $class = Model::getActualClassNameForMorph($type);

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = new $class;

        /*
         * A soft-deleting owner is never orphaned by a soft delete — the row is
         * still there, so the anti-join finds it and nothing is deleted. Noted
         * explicitly so a future reader does not "fix" this by joining against
         * a non-deleted scope, which would delete the media of every restorable
         * listing.
         */
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $this->line("  {$class}: soft-deletes, so only force-deleted owners qualify.", null, 'v');
        }

        return $model;
    }
}
