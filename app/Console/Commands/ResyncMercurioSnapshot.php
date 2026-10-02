<?php

namespace App\Console\Commands;

use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resync the user identity bundle to Mercurio's CQRS mirror
 * (`mercurio_users_snapshot`).
 *
 * # Background
 *
 * The CRM keeps a CQRS-Lite cache (`user_identity_snapshot`) populated by
 * `crm:refresh-user-identity-snapshot`. The cache holds the canonical bundle
 * for each user:
 *
 *   { user, rol, apps, permisos, scope_label, snapshot_at }
 *
 * The `PersonasSnapshotEmitter` listener (PR-J) emits a webhook to Mercurio
 * on every persona mutation, but its payload comes from `Persona::toArray()`
 * and does NOT include `apps` / `permisos` / `entities`. That's the JANUS
 * blocker — Mercurio's `mercurio_users_snapshot.apps` is stuck at `[]`.
 *
 * # What this command does
 *
 * Iterates over every `user_identity_snapshot` row, reads the canonical
 * bundle, and dispatches a `personas.snapshot.sync` webhook to Mercurio
 * with the FULL bundle. Same endpoint + same HMAC secret as the event-
 * driven emitter (`configPrefix='personas_snapshot'`).
 *
 * Until PR-J is extended to include `apps`/`permisos`/`entities` in the
 * PersonaChanged event (see the design doc
 * `Docs/architecture/inbound/janus-bloqueante-fix-plan.md`), this command
 * is the only way to keep Mercurio's mirror warm with the multi-app data.
 *
 * # Idempotency
 *
 * Re-running this command is a no-op for Mercurio's mirror state — the
 * receiver dedupes by `(persona_id, occurred_at)` and this command stamps
 * a fresh `occurred_at` on every dispatch. To force a re-write, run with
 * `--force` (still dedupe-protected by the receiver).
 *
 * # Schedule
 *
 *   Schedule::command('crm:resync-mercurio-snapshot')
 *       ->hourly()
 *       ->withoutOverlapping(60)  // max 60 min lock
 *       ->onOneServer()
 *       ->runInBackground();
 *
 * Once PR-J is extended and event-driven emission also carries the full
 * bundle, the hourly cadence can be relaxed to nightly or removed entirely.
 */
class ResyncMercurioSnapshot extends Command
{
    protected $signature = 'crm:resync-mercurio-snapshot
                            {--user= : Optional single user_id to resync}
                            {--dry-run : Print payloads without dispatching}';

    protected $description = 'Resync user identity bundles to Mercurio CQRS mirror (personas_snapshot webhook)';

    public function handle(): int
    {
        $now = Carbon::now()->toIso8601String();
        $dryRun = (bool) $this->option('dry-run');
        $singleUserId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $query = DB::table('user_identity_snapshot');
        if ($singleUserId !== null) {
            $query->where('user_id', $singleUserId);
        }

        $rows = $query->orderBy('user_id')->get(['user_id', 'payload', 'scope_label']);

        if ($rows->isEmpty()) {
            $this->warn('No user_identity_snapshot rows found. Run `crm:refresh-user-identity-snapshot` first.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $bundle = json_decode((string) $row->payload, true);
            if (! is_array($bundle) || ! isset($bundle['user']['id'])) {
                $this->warn("  Skipping user_id={$row->user_id} — payload is not a valid identity bundle.");
                $skipped++;
                continue;
            }

            $personaId = (int) $bundle['user']['id'];
            $email = $bundle['user']['email'] ?? "(no email)";

            // Resolve the user's tenant(s). Mercurio's webhook handler
            // requires `entidad_id` at top level (used as `tenant_id` in
            // mercurio_personas_snapshot); without it, the receiver rejects
            // with `WEBHOOK_REFUSED`. We dispatch one webhook per
            // dependencia/delegacion entity so each tenant gets its own row.
            $entities = $this->extractEntities($bundle);
            if (empty($entities)) {
                $this->warn("  Skipping user_id={$personaId} — no dependencia/delegacion entities to dispatch to.");
                $skipped++;
                continue;
            }

            foreach ($entities as $entity) {
                $entidadId = (int) $entity['id'];

                // Wire envelope — TOP-LEVEL fields per Mercurio's contract
                // (see fastapi-backend/services/personas_snapshot_sync.py).
                // The action enum is strict: created | updated | deleted.
                // Resync uses `updated` because the persona already exists
                // in Mercurio's mirror; we're refreshing its bundle.
                $payload = [
                    'event' => 'personas.snapshot.sync',
                    'action' => 'updated',
                    'persona_id' => $personaId,
                    'entidad_id' => $entidadId,
                    'occurred_at' => $now,
                    'snapshot' => [
                        'email' => $bundle['user']['email'] ?? '',
                        'nombre' => $bundle['user']['nombre'] ?? '',
                        'apps' => $bundle['apps'] ?? [],
                        'entities' => $entities,
                        'permisos' => $bundle['permisos'] ?? [],
                    ],
                ];

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [DRY] user_id=%d (%s) entidad_id=%d → apps=%d permisos=%d entities=%d',
                        $personaId,
                        $email,
                        $entidadId,
                        count($payload['snapshot']['apps']),
                        count($payload['snapshot']['permisos']),
                        count($payload['snapshot']['entities'])
                    ));
                    $skipped++;
                    continue;
                }

                try {
                    DispatchOutboundWebhookJob::dispatch(
                        'personas.snapshot.sync',
                        $payload,
                        'personas_snapshot',
                        true,
                    );
                    $dispatched++;
                    $this->line("  ✓ user_id={$personaId} ({$email}) entidad_id={$entidadId} → dispatched (apps=".count($payload['snapshot']['apps']).')');
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  ✗ user_id={$personaId} entidad_id={$entidadId} ({$email}) : {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("Done. Dispatched: {$dispatched}. Skipped: {$skipped}. Errors: {$errors}.");

        if ($errors > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Extract a flat list of entities the user belongs to as a member
     * (`dependencia`) or delegated cross-tenant admin (`delegacion`).
     * Excludes `asignacion` (comercial assignments to client accounts)
     * because that relationship is transient and does NOT represent
     * organizational membership — Mercurio renders it as noise.
     *
     * The filter MUST mirror `GetMyIdentityUseCase::computeFromDb()`'s
     * semantics for `/me/identity`: same `categoria` set, same join path
     * (entidad_persona → usuarios → entidad). Otherwise the wire payload
     * diverges from the snapshot the user sees in-app.
     *
     * @return array<int, array{id: int, nombre: string}>
     */
    private function extractEntities(array $bundle): array
    {
        $userId = (int) ($bundle['user']['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        return DB::table('entidad_persona as ep')
            ->join('entidad as e', 'e.id', '=', 'ep.entidad_id')
            ->join('usuarios as u', 'u.persona_id', '=', 'ep.persona_id')
            ->where('u.id', $userId)
            ->whereIn('ep.categoria', ['dependencia', 'delegacion'])
            ->orderBy('e.nombre')
            ->get(['e.id', 'e.nombre'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'nombre' => (string) $r->nombre])
            ->all();
    }
}
