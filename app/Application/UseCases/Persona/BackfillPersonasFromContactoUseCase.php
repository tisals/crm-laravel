<?php

namespace App\Application\UseCases\Persona;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR-F (Phase 4): Backfill legacy `contacto` rows into the canonical
 * `personas` table.
 *
 * The personas table was extended by PR-A (tipo_persona, entidad_id,
 * apellidos nullable) and PR-E added `contacto.persona_id`. With those
 * columns in place, this use case is the data-migration step that
 * populates `personas` from existing `contacto` rows so that PR-G can
 * safely swap `seguimiento.contacto_id → persona_id`.
 *
 * Design (per `design.md` AD-4, AD-12, §5.4):
 *  - Dedupe key is `email_principal` (case-insensitive) — one persona
 *    per email globally (AD-12). Two contactos with the same email
 *    across multiple entidades share ONE persona.
 *  - NULL/empty emails → `backfill.skipped.null_email` warning + counter.
 *  - Re-runnable: only `contacto WHERE persona_id IS NULL` is processed
 *    (idempotent on re-run, REQ-PCBF-002).
 *  - Per-row try/catch: a bad row logs `backfill.row.error`, increments
 *    `errors`, and the loop continues. Caller decides exit code.
 *  - DB::transaction per row for atomicity (insert persona + update
 *    contacto link as one unit, REQ-ISCF-006 reversal-friendly).
 *  - Soft-deletable personas (Persona uses SoftDeletes): rollback is
 *    `UPDATE contacto SET persona_id=NULL` + `Persona::where(...)->delete()`
 *    (soft-delete preserves history).
 *
 * Returns a counter array consumed by the artisan command to render
 * the machine-readable summary table (REQ-PCBF-006):
 *  - dry-run: `dry_run=true, would_insert, would_skip_null_email, would_skip_duplicate`
 *  - actual: `dry_run=false, inserted, updated, skipped_already_linked,
 *             skipped_null_email, skipped_duplicate, errors`
 */
class BackfillPersonasFromContactoUseCase
{
    /**
     * @param  array{dry_run?: bool, limit?: int|null}  $options
     * @return array<string, int|bool> counter map (see class doc)
     */
    public function execute(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;

        $query = DB::table('contacto')->whereNull('persona_id');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        Log::info('backfill.started', [
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        $result = $dryRun
            ? $this->dryRun($query)
            : $this->runActual($query);

        Log::info('backfill.completed', $result);

        return $result;
    }

    /**
     * Read every contacto that WOULD be touched; tally outcomes without
     * writing. In-memory dedup tracks emails we WOULD insert so duplicates
     * within the same batch are reported too.
     */
    private function dryRun($query): array
    {
        $wouldInsert = 0;
        $wouldSkipNullEmail = 0;
        $wouldSkipDuplicate = 0;

        // Pre-load existing personas emails (case-insensitive) for the
        // pre-existing-dedup case. Commit 4 dropped `personas.email_principal`;
        // the dedup now reads from the shared `emails` table.
        $existingEmails = DB::table('emails')
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->flip()
            ->all();

        // In-batch dedup (so aContacto/bContacto with the same new email
        // both show up: first would_insert, second would_skip_duplicate).
        $seenEmails = [];

        foreach ($query->orderBy('id')->cursor() as $contacto) {
            $email = trim((string) ($contacto->email_contacto ?? ''));

            if ($email === '') {
                $wouldSkipNullEmail++;

                continue;
            }

            $emailLower = strtolower($email);

            if (isset($existingEmails[$emailLower]) || isset($seenEmails[$emailLower])) {
                $wouldSkipDuplicate++;
            } else {
                $wouldInsert++;
                $seenEmails[$emailLower] = true;
            }
        }

        return [
            'dry_run' => true,
            'would_insert' => $wouldInsert,
            'would_skip_null_email' => $wouldSkipNullEmail,
            'would_skip_duplicate' => $wouldSkipDuplicate,
        ];
    }

    /**
     * Walk each contacto in chunks; per-row DB::transaction, per-row
     * try/catch. Inserts new persona or re-uses existing by email
     * (cross-entidad dedupe per AD-12). Updates contacto.persona_id.
     */
    private function runActual($query): array
    {
        $inserted = 0;
        $updated = 0;
        $skippedNullEmail = 0;
        $skippedDuplicate = 0;
        $errors = 0;

        foreach ($query->orderBy('id')->cursor() as $contacto) {
            $contactoId = (int) $contacto->id;
            $email = trim((string) ($contacto->email_contacto ?? ''));

            try {
                if ($email === '') {
                    Log::warning('backfill.skipped.null_email', [
                        'contacto_id' => $contactoId,
                        'code' => 'backfill.skipped.null_email',
                    ]);
                    $skippedNullEmail++;

                    continue;
                }

                $emailLower = strtolower($email);

                DB::transaction(function () use ($contactoId, $contacto, $email, $emailLower, &$inserted, &$updated, &$skippedDuplicate) {
                    // Cross-entidad dedupe by lowercased email (AD-12).
                    // Commit 4 dropped `personas.email_principal`; the
                    // dedup now reads from the shared `emails` table.
                    $existing = DB::table('emails')
                        ->whereRaw('LOWER(email) = ?', [$emailLower])
                        ->value('persona_id');

                    if ($existing) {
                        $personaId = (int) $existing;
                        Log::info('backfill.dedupe.matched', [
                            'contacto_id' => $contactoId,
                            'persona_id' => $personaId,
                            'code' => 'backfill.dedupe.matched',
                        ]);

                        DB::table('contacto')
                            ->where('id', $contactoId)
                            ->update([
                                'persona_id' => $personaId,
                                'updated_at' => now(),
                            ]);
                        $skippedDuplicate++;
                    } else {
                        $personaId = DB::table('personas')->insertGetId([
                            'nombres' => (string) ($contacto->nombres ?? ''),
                            'apellidos' => $contacto->apellidos !== null && $contacto->apellidos !== ''
                                ? (string) $contacto->apellidos
                                : null,
                            // `tipo_persona` ENUM was dropped from personas
                            // in commit 7a2d33c (PR-A cleanup). The new
                            // personas table is type-agnostic; the
                            // Natural/Juridica split lives on entidad
                            // (and on persona->entidad_id for the legacy
                            // 1:1 link). We default new personas to the
                            // implicit "Natural" persona type without
                            // writing it anywhere.
                            //
                            // Commit 4 dropped `email_principal` /
                            // `telefono_principal`; those values now live
                            // in the shared `emails` / `telefonos`
                            // tables. We insert them right after the
                            // persona row below so the backfill
                            // produces a fully-populated persona.
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        if ($email !== '') {
                            DB::table('emails')->insert([
                                'persona_id' => (int) $personaId,
                                'email' => $email,
                                'tipo' => 'personal',
                                'es_principal' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        if (! empty($contacto->tel_contacto)) {
                            DB::table('telefonos')->insert([
                                'persona_id' => (int) $personaId,
                                'numero' => (string) $contacto->tel_contacto,
                                'tipo' => 'movil',
                                'es_principal' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        DB::table('contacto')
                            ->where('id', $contactoId)
                            ->update([
                                'persona_id' => $personaId,
                                'updated_at' => now(),
                            ]);
                        $inserted++;
                    }
                });
            } catch (Throwable $e) {
                Log::error('backfill.row.error', [
                    'contacto_id' => $contactoId,
                    'error' => $e->getMessage(),
                    'code' => 'backfill.row.error',
                ]);
                $errors++;
            }
        }

        // already-linked is a snapshot stat: how many contactos already had
        // a persona link before this run. Useful for the operator to see
        // "this run did nothing because everything was already linked".
        $skippedAlreadyLinked = DB::table('contacto')->whereNotNull('persona_id')->count()
            - $inserted
            - $skippedDuplicate;

        return [
            'dry_run' => false,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped_already_linked' => max(0, $skippedAlreadyLinked),
            'skipped_null_email' => $skippedNullEmail,
            'skipped_duplicate' => $skippedDuplicate,
            'errors' => $errors,
        ];
    }
}
