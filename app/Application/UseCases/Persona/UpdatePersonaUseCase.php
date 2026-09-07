<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * PR-J — UpdatePersonaUseCase dispatches `PersonaChanged(action='updated')`
 * IFF the patch produces a real field change (REQ-PSWH-006, AD-8, R-3).
 *
 * The "no effective change" rule is enforced by comparing the user-facing
 * field set of the pre- and post-update entity snapshots with
 * `array_diff_assoc`. Timestamps are intentionally EXCLUDED so a PATCH
 * that only bumps `updated_at` does NOT emit a webhook (RQ-4 — silence on
 * pure timestamp advances keeps Mercurio's mirror free of noise).
 *
 * Why `array_diff_assoc` and not `!==`: the comparison is field-by-field
 * against identical keys (the domain entity has a fixed schema). The diff
 * returns entries where $old != $new; an empty diff means "no real change".
 */
class UpdatePersonaUseCase
{
    /**
     * User-facing fields compared for the "no effective change" rule.
     * Excludes timestamps (`created_at`, `updated_at`, `deleted_at`) and
     * the auto-incrementing PK. Mirrors the columns in `PersonaResource`.
     *
     * The contact-data fields (`email_principal`, `telefono_principal`,
     * `direccion`, `ciudad`, `pais`) were dropped by Commit 4 — they
     * now live in the shared `emails` / `telefonos` / `direcciones`
     * tables, updated through their own use cases / REST verbs.
     */
    private const COMPARED_FIELDS = [
        'identificacion_tipo',
        'identificacion_numero',
        'nombres',
        'apellidos',
        'tipo_persona',
        'entidad_id',
    ];

    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        // Read on the read connection (handled inside the repository) so
        // we capture the pre-update shape from the same source of truth
        // the API consumer would see. The actual write still routes to
        // master via the repository's `update()`.
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return null;
        }

        $old = $this->projectForComparison($existing);

        $updated = DB::transaction(function () use ($id, $data) {
            $persona = $this->repository->update($id, $data);

            // Commit 4 dropped `personas.email_principal`,
            // `personas.telefono_principal`, `personas.direccion`. The
            // PATCH endpoint still accepts these keys for backwards
            // compatibility (see `PersonaUpdateRequest`); mirror them
            // into the shared tables so the response and downstream
            // readers see the update. Only the keys actually present in
            // the payload are mirrored (`validated()` already filters
            // them).
            if ($persona !== null) {
                $this->mirrorLegacyContactFields($id, $data);
            }

            return $persona;
        });

        if (! $updated) {
            return null;
        }

        $new = $this->projectForComparison($updated);

        if ($this->hasFieldChange($old, $new)) {
            event(new PersonaChanged(
                action: 'updated',
                persona_id: (int) $updated->id,
                snapshot: $updated->toArray(),
                occurred_at: now()->toIso8601String(),
            ));
        }

        return $updated;
    }

    /**
     * Mirror the persona-level `email_principal` / `telefono_principal`
     * payload fields into the shared `emails` / `telefonos` tables on
     * UPDATE. The legacy columns were dropped by Commit 4; this helper
     * keeps the data alive so PATCH callers continue to work.
     *
     * Strategy: when the request supplies a non-empty value, UPSERT the
     * primary row (the one with `es_principal = true`) on the matching
     * table. We never delete the persona's other rows — the caller may
     * have manually added `emails` / `telefonos` through their own REST
     * endpoints and we should not surprise them.
     */
    private function mirrorLegacyContactFields(int $personaId, array $data): void
    {
        $now = now();

        if (array_key_exists('email_principal', $data)) {
            $value = $data['email_principal'];
            if ($value === null || $value === '') {
                // Caller cleared the email. We don't delete the row
                // here (out of scope) but we flip `es_principal` to
                // false so the resource reads null until the caller
                // explicitly removes the row.
                DB::table('emails')
                    ->where('persona_id', $personaId)
                    ->where('es_principal', true)
                    ->update(['es_principal' => false, 'updated_at' => $now]);
            } else {
                $existing = DB::table('emails')
                    ->where('persona_id', $personaId)
                    ->where('es_principal', true)
                    ->first();
                if ($existing) {
                    DB::table('emails')
                        ->where('id', $existing->id)
                        ->update([
                            'email' => (string) $value,
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('emails')->insert([
                        'persona_id' => $personaId,
                        'email' => (string) $value,
                        'tipo' => 'personal',
                        'es_principal' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        if (array_key_exists('telefono_principal', $data)) {
            $value = $data['telefono_principal'];
            if ($value === null || $value === '') {
                DB::table('telefonos')
                    ->where('persona_id', $personaId)
                    ->where('es_principal', true)
                    ->update(['es_principal' => false, 'updated_at' => $now]);
            } else {
                $existing = DB::table('telefonos')
                    ->where('persona_id', $personaId)
                    ->where('es_principal', true)
                    ->first();
                if ($existing) {
                    DB::table('telefonos')
                        ->where('id', $existing->id)
                        ->update([
                            'numero' => (string) $value,
                            'updated_at' => $now,
                        ]);
                } else {
                    DB::table('telefonos')->insert([
                        'persona_id' => $personaId,
                        'numero' => (string) $value,
                        'tipo' => 'movil',
                        'es_principal' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Restrict the entity to the user-facing field set so timestamps
     * cannot poison the diff.
     *
     * @return array<string, mixed>
     */
    private function projectForComparison(PersonaEntity $persona): array
    {
        $full = $persona->toArray();

        return array_intersect_key($full, array_flip(self::COMPARED_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function hasFieldChange(array $old, array $new): bool
    {
        // Both arrays have the SAME keys (projectForComparison ensures
        // this), so array_diff_assoc produces a meaningful entry-set of
        // fields that differ. Empty diff → no effective change.
        return array_diff_assoc($old, $new) !== [];
    }
}
