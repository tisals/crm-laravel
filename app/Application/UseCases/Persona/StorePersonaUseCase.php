<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * PR-J + PR-K — StorePersonaUseCase handles the R-iter4.R08 inversion
 * (Natural → entidad) and dispatches `PersonaChanged(action='created')`
 * after the DB write commits (REQ-PSWH-001, REQ-PNCE-001..005, AD-8, R-3).
 *
 * ## R-iter4.R08 inversion (PR-K)
 *
 * When the caller POSTs a `Persona Natural` without an `entidad_id`, the
 * use case first inserts a fresh `entidad` row (with the persona's name,
 * identification, and address), then inserts the persona pointing at the
 * new `entidad.id`. The two writes run inside a single `DB::transaction`
 * — if EITHER fails, both roll back. There is no orphan-entidad state.
 *
 * The dispatch sits OUTSIDE the `DB::transaction` callback (not via
 * `DB::afterCommit()`): the controller path is not nested inside another
 * transaction, so dispatching right after the closure returns gives the
 * same post-commit semantics without the `RefreshDatabase` interaction
 * quirk where `afterCommit` callbacks queue up until the outer test
 * transaction commits at tear-down. Keeping the dispatch at the use-case
 * boundary (post-transaction, pre-return) keeps tests deterministic and
 * matches the spec's "after the DB commit succeeds" wording.
 *
 * ## Why ONE event, not TWO
 *
 * The receiver (Mercurio's CQRS mirror) keys reconciliation on
 * `(persona_id, occurred_at)`. If we emitted one event for the entidad
 * and one for the persona, Mercurio would have to dedupe two unrelated
 * streams. The spec (REQ-PNCE-006 + REQ-PSWH-001) calls for exactly one
 * event per write — so the dispatch happens AFTER the inversion succeeds
 * and AFTER the persona insert commits, with the final (post-inversion)
 * snapshot.
 *
 * ## Why `DB::table('entidad')->insertGetId()` (not the repository)
 *
 * The inversion creates an `entidad` as a side-effect of creating a
 * persona. There's no resource/observer layer for it — Mercurio doesn't
 * mirror entidades — so bypassing the `EloquentEntidadRepository` keeps
 * the side-effect atomic with the persona write (same connection, same
 * transaction) and avoids that repository's list-view eager loads
 * (`withCount(['contactos','oportunidades'])`, `with(['usuarios','ciudad'])`).
 * Those eager loads are correct for the index endpoint but pointless for
 * an internal side-effect. A bare `insertGetId` is the smallest possible
 * write that still respects the FK / ENUM / default constraints.
 */
class StorePersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        $persona = DB::transaction(function () use ($data) {
            $payload = $this->maybeCreateImpliedEntity($data);

            return $this->repository->create($payload);
        });

        // Post-commit dispatch: the create succeeded, the row is visible.
        // The listener (PersonasSnapshotEmitter) is a no-op on failure —
        // webhook emission NEVER breaks the originating request (R-3).
        event(new PersonaChanged(
            action: 'created',
            persona_id: (int) $persona->id,
            snapshot: $this->toSnapshot($persona),
            occurred_at: now()->toIso8601String(),
        ));

        return $persona;
    }

    /**
     * R-iter4.R08 inversion branch. Returns the payload that the persona
     * repository should receive — either unchanged (juridica / explicit
     * entidad_id) or with a freshly-created `entidad_id` merged in.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function maybeCreateImpliedEntity(array $data): array
    {
        $tipo = isset($data['tipo_persona']) ? strtolower((string) $data['tipo_persona']) : 'natural';
        $hasEntidadId = ! empty($data['entidad_id']);

        // Inversion ONLY fires for `natural` personas when the caller did
        // not supply an `entidad_id`. Juridica personas and explicit
        // `entidad_id` callers fall through with the payload unchanged —
        // backward compatibility (R-8, REQ-PNCE-002, REQ-PNCE-003).
        if ($tipo !== 'natural' || $hasEntidadId) {
            return $this->canonicalizeTipoPersona($data);
        }

        $entidadId = $this->createEntidadForNaturalPersona($data);

        $data['entidad_id'] = $entidadId;

        return $this->canonicalizeTipoPersona($data);
    }

    /**
     * Insert the inversion `entidad` row and return the new id. Runs inside
     * the use case's outer `DB::transaction`, so a failure on the persona
     * step that follows rolls back this insert too.
     *
     * Column-by-column mapping (REQ-PNCE-001):
     *   - tipo_persona   ← 'Natural' (canonical ENUM form)
     *   - tipo_id        ← persona.identificacion_tipo (e.g. 'CC')
     *   - identificacion ← persona.identificacion_numero (NULL allowed —
     *                       the column is nullable AND unique, multiple
     *                       NULLs are fine in MySQL/MariaDB semantics)
     *   - nombre         ← trim(persona.nombres.' '.persona.apellidos),
     *                       falling back to just `nombres` when apellidos
     *                       is null (juridica personas don't have surnames)
     *   - nombre_comercial, dominio, email_contacto ← NULL
     *   - estado         ← 'Activo'
     *
     * Commit 4 dropped `entidad.direccion` and `entidad.ciudad_cod` —
     * those fields now live in the shared `direcciones` table, which
     * the caller writes through a separate REST verb after this
     * insert (or via the legacy `direccion` field on the persona if
     * present, mirrored into `direcciones` for that new entidad).
     *
     * @param  array<string, mixed>  $persona
     */
    private function createEntidadForNaturalPersona(array $persona): int
    {
        $nombres = (string) ($persona['nombres'] ?? '');
        $apellidos = $persona['apellidos'] ?? null;
        $nombreCompleto = trim(
            $apellidos !== null && $apellidos !== ''
                ? "{$nombres} {$apellidos}"
                : $nombres
        );

        $entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Natural',
            'tipo_id' => $persona['identificacion_tipo'] ?? null,
            'identificacion' => $persona['identificacion_numero'] ?? null,
            'nombre' => $nombreCompleto,
            'nombre_comercial' => null,
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mirror any persona-level `direccion` value into a new
        // `direcciones` row for this entidad (Commit 3 contract). The
        // legacy `personas.direccion` column was dropped by Commit 4,
        // but personas still carry it in incoming payloads for now —
        // once the API migrates to the dedicated `/direcciones`
        // endpoint, this fallback can be deleted.
        if (! empty($persona['direccion'])) {
            DB::table('direcciones')->insert([
                'entidad_id' => (int) $entidadId,
                'direccion_principal' => (string) $persona['direccion'],
                'tipo' => 'oficina',
                'es_principal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $entidadId;
    }

    /**
     * Map the lowercase 'natural' / 'juridica' validator output to the
     * canonical ENUM form ('Natural' / 'Juridica') so the persona row
     * survives the `personas.tipo_persona` enum check. Default is
     * 'Natural' per REQ-PNCE-005.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function canonicalizeTipoPersona(array $data): array
    {
        $tipo = $data['tipo_persona'] ?? null;

        if ($tipo === null || $tipo === '') {
            $data['tipo_persona'] = 'Natural';

            return $data;
        }

        $data['tipo_persona'] = ucfirst((string) $tipo);

        return $data;
    }

    /**
     * Project the domain entity to the receiver-facing snapshot shape.
     * Re-uses `toArray()` so the wire format stays in lock-step with the
     * domain entity (the resource's relations block is intentionally NOT
     * included — that's an HTTP-only convenience; the webhook payload
     * must stay small and receiver-stable).
     */
    private function toSnapshot(PersonaEntity $persona): array
    {
        return $persona->toArray();
    }
}
