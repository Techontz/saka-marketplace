<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt `verification_requests.document_number` at rest.
     *
     * This column holds a Tanzanian NIDA number — a national identity number
     * that is not reissued and that identifies a person for life. It has been
     * stored in plaintext, which means it appears in every database backup,
     * every replica, every `SELECT *` in a console, and in any dump shared with
     * a contractor. Encrypting it means a leaked backup does not leak
     * identities, because the ciphertext is useless without `APP_KEY`, which
     * lives in the environment rather than the database.
     *
     * TWO STRUCTURAL CHANGES, both required:
     *
     *   1. `string(60)` -> `text`. Laravel's encrypter emits base64 of a JSON
     *      envelope (iv, value, mac, tag), so a 20-digit NIDA becomes ~250
     *      characters. Left at 60 the insert would either be truncated — with
     *      `strict` off, silently, producing ciphertext that can never be
     *      decrypted — or throw on every submission.
     *
     *   2. A backfill, encrypting rows already present. Without it the model's
     *      `encrypted` cast would try to decrypt plaintext on read and throw a
     *      DecryptException on the admin queue for every historical row.
     *
     * NOT REVERSIBLE BY DESIGN. `down()` widens the column back but does NOT
     * decrypt: a rollback that writes national identity numbers back to
     * plaintext is not a recovery, it is a second incident. Recovering the
     * cleartext is a deliberate, audited operation, not a side effect of
     * `migrate:rollback`.
     */
    public function up(): void
    {
        Schema::table('verification_requests', function (Blueprint $table): void {
            $table->text('document_number')->nullable()->change();
        });

        /*
         * Backfill in chunks with a per-row guard.
         *
         * The guard matters on a re-run: if this migration is applied to a
         * database where some rows are already encrypted — a partial failure, a
         * restored snapshot — double-encrypting them would make the plaintext
         * unrecoverable. A value that decrypts is already done and is skipped.
         */
        DB::table('verification_requests')
            ->select('id', 'document_number')
            ->whereNotNull('document_number')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $value = (string) $row->document_number;

                    if ($value === '' || $this->looksEncrypted($value)) {
                        continue;
                    }

                    DB::table('verification_requests')
                        ->where('id', $row->id)
                        ->update(['document_number' => Crypt::encryptString($value)]);
                }
            });
    }

    public function down(): void
    {
        // Widened, never decrypted. See the note above.
        Schema::table('verification_requests', function (Blueprint $table): void {
            $table->text('document_number')->nullable()->change();
        });
    }

    private function looksEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
