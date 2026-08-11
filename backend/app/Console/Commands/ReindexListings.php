<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Listing\ListingIndexer;
use Illuminate\Console\Command;

/**
 * Rebuild the `search_document` projection on every listing.
 *
 * WHY THIS IS NEEDED
 * ------------------
 * `listings.search_document` is a denormalised copy of a listing's EAV values,
 * written by ListingIndexer whenever the listing is saved through the
 * application. LIST responses read the flat `attributes` map from it — that is
 * what makes a results page one query instead of one per card.
 *
 * Anything that writes `listing_attribute_values` WITHOUT going through
 * ListingService leaves that projection behind: a seeder, a data migration, a
 * manual SQL fix, or adding an attribute to a vertical and backfilling it. The
 * symptom is subtle and easy to misread — filtering still works, because
 * AttributeFilter joins the EAV table directly, but the value never appears on
 * the card. A filter that returns the right listings while the cards look
 * unchanged reads as a broken filter.
 *
 * Idempotent, chunked, and safe to run on a live catalogue.
 *
 *     php artisan saka:listings:reindex
 *     php artisan saka:listings:reindex --category=vehicles
 */
class ReindexListings extends Command
{
    protected $signature = 'saka:listings:reindex
                            {--category= : Limit to one category slug and its descendants}
                            {--chunk=200 : Rows per batch}';

    protected $description = 'Rebuild the search_document projection used by listing cards';

    public function handle(ListingIndexer $indexer): int
    {
        $query = Listing::query()->with(['attributeValues.attribute', 'category']);

        $categorySlug = $this->option('category');

        if (is_string($categorySlug) && $categorySlug !== '') {
            $query->whereHas('category', function ($q) use ($categorySlug): void {
                $q->where('slug', $categorySlug)
                    ->orWhereHas('parent', fn ($parent) => $parent->where('slug', $categorySlug));
            });
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing to reindex.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $failed = 0;

        // chunkById, not chunk: indexing does not change the ordering column,
        // but paging by offset over a table that can be written to mid-run
        // silently skips rows.
        $query->chunkById((int) $this->option('chunk'), function ($listings) use ($indexer, $bar, &$failed): void {
            foreach ($listings as $listing) {
                try {
                    $indexer->index($listing);
                } catch (\Throwable $e) {
                    // One malformed listing must not abort a catalogue-wide
                    // rebuild; report the count and carry on.
                    $failed++;
                    report($e);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($failed > 0) {
            $this->warn("Reindexed {$total} listings, {$failed} failed — see the log.");

            return self::FAILURE;
        }

        $this->info("Reindexed {$total} listings.");

        return self::SUCCESS;
    }
}
