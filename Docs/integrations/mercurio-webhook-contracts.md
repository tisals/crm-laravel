# crm-laravel → Mercurio Integration Contracts

> **Audience**: Mercurio maintainers, future contributors, and any
> downstream service that wants to mirror CRM entities.
>
> **Owner**: CRM team (`tisals/crm-laravel`).
>
> **Status** (Sept 9 2026): stable. Wired and tested in production
> against Mercurio v0.4+. Field names are part of the public wire
> contract — rename with deprecation, not silently.

This document captures the three contracts between `crm-laravel` and the
Mercurio CQRS mirror:

| Contract | Direction | Trigger | Notes |
|---|---|---|---|
| **A** `personas.snapshot.sync` | crm-laravel → Mercurio | `PersonaChanged` domain event | Pre-Commit 8 (`feat/iter4-persona-tracker` PR-J). |
| **B** `entidades.snapshot.sync` | crm-laravel → Mercurio | `EntidadChanged` domain event | Commit 7. Adds UUIDv4 `event_id`. |
| **C** `?depth=1\|2\|3` API projection | Mercurio → crm-laravel | Per-request query param | Commit 6. Optional hint, never 4xx. |

The OpenSpec mirror of this document lives at
`openspec/changes/tenant-data-model-correction/specs/mercurio-contracts/spec.md`
— both reference each other. If you change a field name, update BOTH.

---

## 1. Overview

Mercurio is the CQRS mirror for CRM tenant data. It maintains
read-optimised projections of `personas` and `entidades` so the rest of
the Tecnoinnsoft platform (dashboards, FastAPI services, the SAIlus
gateway) can query tenant identity without round-tripping crm-laravel.

The two flows are:

* **Webhook push** (Contracts A & B). crm-laravel emits a snapshot
  whenever a write commits. The receiver (Mercurio) upserts its
  mirror row keyed on the entity id.
* **Pull projection** (Contract C). When Mercurio needs the deeper
  relation graph (e.g. for a UI that shows a contact's linked
  opportunities), it calls crm-laravel with `?depth=N` to control how
  much graph is embedded in the response.

Both are best-effort + at-least-once. The webhook is the
near-realtime path; the projection is the deep-read path. They are
intentionally orthogonal — the webhook payload does NOT embed deep
relations (it would balloon to N+1-sized bodies), and the projection
endpoint is NOT a write target.

---

## 2. Authentication

All webhook deliveries are signed with HMAC-SHA256 over the **exact
JSON body bytes** sent. The receiver verifies the signature before
trusting the payload.

### Header

```
X-CRM-Signature: sha256=<hex digest>
```

The `sha256=` prefix is mandatory — it lets the receiver disambiguate
algorithm upgrades (sha512, etc.) without a contract bump.

### Secret lookup order

| Source | Where | Notes |
|---|---|---|
| `config('webhook.{prefix}.secret')` | `config/webhook.php` | The dedicated per-flow secret. |
| Elvis fallback to `config('webhook.outbound.secret')` | Same | Only for the `personas_snapshot` and `entidades_snapshot` prefixes — see `CrmWebhookSender::sendRaw()` lines 70-77. |
| `.env` env var | `PERSONA_SNAPSHOT_WEBHOOK_SECRET` or `ENTIDADES_SNAPSHOT_WEBHOOK_SECRET` | Defaults to `WEBHOOK_OUTBOUND_SECRET` if unset. |
| `.env.example` | L90-102 | Documented for operators. |

The fallback only applies to the two CQRS-mirror prefixes. Other
webhook sections (`n8n_pipeline`, the original outbound) keep their
dedicated secrets — a missing secret there is a real misconfiguration,
not a recoverable fallback.

### Verification snippet (Mercurio side)

```python
import hmac, hashlib

def verify(headers: dict, body: bytes) -> bool:
    expected = "sha256=" + hmac.new(
        key=SECRET.encode(),
        msg=body,
        digestmod=hashlib.sha256,
    ).hexdigest()
    return hmac.compare_digest(expected, headers["X-CRM-Signature"])
```

Use `hmac.compare_digest` (constant-time). Do NOT compare with `==`.

---

## 3. Retry / backoff

Webhooks are dispatched via `App\Infrastructure\Webhook\DispatchOutboundWebhookJob`
(lines 44-86). Queue name: `webhooks`. Behaviour:

| Knob | Value | Where |
|---|---|---|
| `tries` | `3` | `DispatchOutboundWebhookJob::$tries = 3` (line 48) |
| `backoff()` | `[60, 120, 180]` (seconds) | line 59-62 |
| HTTP timeout | `10` seconds | `CrmWebhookSender::sendRaw()` line 86 |
| Queue connection | `database` (per `.env`) | `QUEUE_CONNECTION=database` |

After the 3rd failure the job lands in the `failed_jobs` table with
the original payload + the response body. Operators replay manually
via `php artisan queue:retry <uuid>`.

**What counts as a failure**: HTTP transport failure (timeout, DNS,
connection refused), any non-2xx HTTP status, or a thrown exception
inside the job. Non-2xx responses are NOT swallowed at the
`CrmWebhookSender` layer — they propagate as `RuntimeException` so
the queue worker can retry.

**What is NOT a retry**: the listener itself never throws. The
`PersonasSnapshotEmitter` and `EntidadesSnapshotEmitter` catch every
`Throwable` (R-3) so a misconfigured webhook path can NEVER break
the originating REST write. If the listener catches an exception, the
job is never enqueued and there is nothing to retry — see the
structured log entries `personas_snapshot.listener_failed` /
`entidades_snapshot.listener_failed`.

---

## 4. Replay protection

Both events carry dedup keys so Mercurio can detect duplicate
deliveries. The two contracts use slightly different keys:

| Contract | Idempotency key | Source field |
|---|---|---|
| A `personas.snapshot.sync` | `(persona_id, occurred_at)` | `data.persona_id` + `data.occurred_at` |
| B `entidades.snapshot.sync` | `data.event_id` (UUIDv4) | `data.event_id` |

Persona's key is the `(id, timestamp)` pair because persona writes are
low-volume (a few per minute). Mercurio upserts on `(persona_id,
occurred_at)` and ignores re-deliveries with the same pair.

Entidad's key is a UUIDv4 because pivot mutations on
`entidad_relacion` can fire multiple events per second, and two
distinct events for the same entity within the same second would
collide on `(id, occurred_at)`. The UUID is generated when the use
case or observer constructs the `EntidadChanged` event — see
`App\Domain\Events\EntidadChanged::$event_id` (line 43).

`occurred_at` is **always** RFC3339 (ISO-8601 with timezone offset),
stamped at dispatch time in the application layer (`now()->toIso8601String()`).
Do NOT parse it as Unix timestamp — use a real ISO-8601 parser.

---

## 5. Kill switches

Operators can pause the webhook flow without redeploying by flipping
an env var. Three switches, one per flow + one global:

| Env var | Default | Effect when `false` | Where |
|---|---|---|---|
| `EMIT_PERSONA_SNAPSHOT_WEBHOOK` | `true` | `PersonasSnapshotEmitter` becomes a no-op, logs `personas_snapshot.skipped`. | `config/webhook.php` line 79. |
| `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK` | `true` | `EntidadesSnapshotEmitter` becomes a no-op, logs `entidades_snapshot.skipped`. | `config/webhook.php` line 111. |
| `EMIT_*_WEBHOOK` (globally) | n/a | No global switch — each flow has its own. To pause ALL outbound webhooks, set both env vars to `false`. | n/a |

There is no global kill switch by design — pausing one flow should
never silently pause another. The `EMIT_*_WEBHOOK` pattern uses the
`filter_var(env('...', true), FILTER_VALIDATE_BOOLEAN)` parser so
`false`, `0`, `no`, `off` all disable the flow.

The skip log shape is stable and grep-friendly:

```json
{
  "message": "personas_snapshot.skipped",
  "context": {
    "persona_id": 123,
    "action": "updated",
    "reason": "disabled"
  }
}
```

`entidades_snapshot.skipped` carries `entidad_id` instead of
`persona_id`.

---

## 6. Contract A — `personas.snapshot.sync`

The persona flow is the older of the two (PR-J). It was extended by
Commit 7 to carry a UUIDv4 `event_id` (added in parallel to the
entidad event).

### Event types

| `data.action` | When | Snapshot shape |
|---|---|---|
| `created` | After `StorePersonaUseCase` commits. | Full new persona row + primary email / phone / address. |
| `updated` | After `UpdatePersonaUseCase` commits AND `array_diff_assoc` finds an effective change (mirrors REQ-PSWH-006 — PATCH with no real change does NOT emit). | Full post-update persona row. |
| `deleted` | After `DestroyPersonaUseCase` soft-deletes. | Pre-delete snapshot + `deleted_at` ISO-8601 string so the receiver can reconcile its tombstone. |

### Wire envelope (full)

```json
{
  "event": "personas.snapshot.sync",
  "timestamp": "2026-09-09T14:23:01+00:00",
  "data": {
    "action": "updated",
    "persona_id": 4242,
    "event_id": "9d8e0c1a-5b6f-4a7e-8c9d-0e1f2a3b4c5d",
    "occurred_at": "2026-09-09T14:23:01+00:00",
    "snapshot": {
      "id": 4242,
      "identificacion_tipo": "CC",
      "identificacion_numero": "1234567890",
      "nombres": "Ana María",
      "apellidos": "Pérez Gómez",
      "tipo_persona": "Natural",
      "entidad_id": 17,
      "nombre_completo": "Ana María Pérez Gómez",
      "email_principal": "ana@example.com",
      "telefono_principal": "+57-1-555-1234",
      "direccion": "Calle 100 #15-20",
      "ciudad": "Bogotá",
      "pais": "Colombia",
      "created_at": "2026-01-15T10:00:00+00:00",
      "updated_at": "2026-09-09T14:23:01+00:00",
      "deleted_at": null,
      "relations": {
        "contacto_id": 88,
        "colaborador_id": null,
        "proveedor_id": null,
        "entidad_id": 17
      }
    }
  }
}
```

### Minimal payload (created event, persona with no relations yet)

```json
{
  "event": "personas.snapshot.sync",
  "timestamp": "2026-09-09T14:23:01+00:00",
  "data": {
    "action": "created",
    "persona_id": 4243,
    "event_id": "0a1b2c3d-4e5f-6789-abcd-ef0123456789",
    "occurred_at": "2026-09-09T14:23:01+00:00",
    "snapshot": {
      "id": 4243,
      "nombres": "Nuevo",
      "apellidos": "Contacto",
      "tipo_persona": "Natural",
      "nombre_completo": "Nuevo Contacto",
      "email_principal": null,
      "telefono_principal": null,
      "direccion": null,
      "ciudad": null,
      "pais": null,
      "relations": {
        "contacto_id": null,
        "colaborador_id": null,
        "proveedor_id": null,
        "entidad_id": null
      }
    }
  }
}
```

### Field reference

| Field | Type | Source in crm-laravel | Notes |
|---|---|---|---|
| `event` | string, constant | `PersonasSnapshotEmitter::handle()` line 53 | Always `personas.snapshot.sync`. |
| `timestamp` | RFC3339 string | `PersonaChanged::$occurred_at` | Stamped at dispatch time. |
| `data.action` | `'created' \| 'updated' \| 'deleted'` | `PersonaChanged::$action` | Lowercase, kebab-free. |
| `data.persona_id` | int | `PersonaChanged::$persona_id` | The persona's `personas.id`. Survives delete. |
| `data.event_id` | UUIDv4 string | `EntidadChanged::$event_id` (mirrored here for cross-flow dedup) | Commit 7. Optional for backward compat but recommended. |
| `data.occurred_at` | RFC3339 string | `PersonaChanged::$occurred_at` | Same value as `timestamp`. |
| `data.snapshot.*` | object | `PersonaResource::toArray($request)` at default depth (2) | See `app/Http/Resources/PersonaResource.php` lines 39-90. |
| `data.snapshot.tipo_persona` | `'Natural' \| 'Juridica'` | Linked `entidad.tipo_persona` via `Persona::getTipoPersonaAttribute()` accessor | Commit 5 dropped `personas.tipo_persona`; the value lives on the linked `entidad` row now. Defaults to `'Natural'`. |
| `data.snapshot.relations.entidad_id` | int or null | `entidad_persona` pivot (first row by entidad_id) | Commit 2 (fe99f70) — the pivot was renamed from `entidad_usuario` and re-keyed on `persona_id`. |
| `data.snapshot.email_principal` | string or null | `emails` table, primary row where `persona_id` matches | Commit 3 — replaces the dropped `personas.email_principal` column. |
| `data.snapshot.telefono_principal` | string or null | `telefonos` table, primary row | Same. |
| `data.snapshot.direccion` | string or null | `direcciones.direccion_principal`, primary row | Same. |

### What triggers it

The `PersonaChanged` event fires from:

1. `App\Application\UseCases\Persona\StorePersonaUseCase::execute()` — after the entity insert commits.
2. `App\Application\UseCases\Persona\UpdatePersonaUseCase::execute()` — after the entity update commits, only if the resulting attributes differ from the pre-update (mirrors REQ-PSWH-006 — silent on no-op PATCH).
3. `App\Application\UseCases\Persona\DestroyPersonaUseCase::execute()` — captures pre-delete snapshot, then soft-deletes.

Listener: `App\Infrastructure\Webhook\PersonasSnapshotEmitter::handle()` (lines 36-78). The listener is registered in `App\Providers\EventServiceProvider::$listen` (line 44).

---

## 7. Contract B — `entidades.snapshot.sync`

Mirrors Contract A but for entities. Added in Commit 7. Key
differences: (a) carries a UUIDv4 `event_id` as the canonical dedup
key; (b) `is_active` is derived from the open pivot row (the legacy
`entidad.estado` column was dropped in Commit 8); (c) pivot mutations
on `entidad_relacion` ALSO emit (with `action='updated'`) because they
flip `is_active`.

### Event types

| `data.action` | When | Trigger |
|---|---|---|
| `created` | First row insert on `entidad`. | `StoreEntidadUseCase::execute()`. |
| `updated` | Row update OR pivot mutation that flips `is_active` (open effective_to). | `UpdateEntidadUseCase::execute()` (only on effective change, mirrors persona's REQ-PSWH-006) OR `EntidadRelacionObserver` (created / updated where `effective_to` was changed / deleted). |
| `deleted` | Soft-delete. | `DestroyEntidadUseCase::execute()` — captures pre-delete snapshot. |

### Wire envelope (full)

```json
{
  "event": "entidades.snapshot.sync",
  "timestamp": "2026-09-09T15:00:00+00:00",
  "data": {
    "action": "updated",
    "entidad_id": 17,
    "event_id": "1f2e3d4c-5b6a-7980-1234-567890abcdef",
    "occurred_at": "2026-09-09T15:00:00+00:00",
    "snapshot": {
      "id": 17,
      "nombre": "Acme S.A.",
      "nombre_comercial": "Acme",
      "tipo_persona": "Juridica",
      "identificacion": "900123456-7",
      "email_principal": "contacto@acme.test",
      "telefono_principal": "+57-1-555-1234",
      "direccion_principal": "Calle 100 #15-20",
      "dominio": "acme.test",
      "is_active": true,
      "relaciones_count": 3,
      "contactos_count": 12,
      "oportunidades_count": 4,
      "usuarios_count": 5,
      "deleted_at": null
    }
  }
}
```

### Minimal payload (created, fresh entity, no pivot yet)

```json
{
  "event": "entidades.snapshot.sync",
  "timestamp": "2026-09-09T15:00:00+00:00",
  "data": {
    "action": "created",
    "entidad_id": 18,
    "event_id": "fedcba98-7654-3210-1234-567890abcdef",
    "occurred_at": "2026-09-09T15:00:00+00:00",
    "snapshot": {
      "id": 18,
      "nombre": "Marca Nueva",
      "nombre_comercial": null,
      "tipo_persona": "Juridica",
      "identificacion": "900999888-1",
      "email_principal": null,
      "telefono_principal": null,
      "direccion_principal": null,
      "dominio": null,
      "is_active": false,
      "relaciones_count": 0,
      "contactos_count": 0,
      "oportunidades_count": 0,
      "usuarios_count": 0,
      "deleted_at": null
    }
  }
}
```

### `is_active` derivation rule

`is_active` is **always derived from the open pivot row**, NOT from
the (now dropped) `entidad.estado` column. The rule lives in exactly
one place — `App\Infrastructure\Webhook\EntidadSnapshotBuilder::isActive()`
(lines 177-183):

```sql
SELECT 1
FROM entidad_relacion
WHERE entidad_id = ? AND effective_to IS NULL
LIMIT 1
```

Returns `true` iff at least one pivot row with `effective_to IS NULL`
exists for the entity. This mirrors `Entidad::getEstadoAttribute()`
(`app/Models/Entidad.php` lines 320-327) — both implementations
intentionally duplicate the rule rather than share a helper, because
the snapshot builder is pure data projection and shouldn't instantiate
the Eloquent model on the hot path.

### Field reference

| Field | Type | Source in crm-laravel | Notes |
|---|---|---|---|
| `event` | string, constant | `EntidadesSnapshotEmitter::handle()` line 54 | Always `entidades.snapshot.sync`. |
| `timestamp` | RFC3339 string | `EntidadChanged::$occurred_at` | Same as `data.occurred_at`. |
| `data.action` | `'created' \| 'updated' \| 'deleted'` | `EntidadChanged::$action` | |
| `data.entidad_id` | int | `EntidadChanged::$entidad_id` | Survives delete. |
| `data.event_id` | UUIDv4 string | `EntidadChanged::$event_id` | The canonical dedup key. Generated at event-construction time via `Str::uuid()`. |
| `data.occurred_at` | RFC3339 string | `EntidadChanged::$occurred_at` | |
| `data.snapshot.id` | int | `entidad.id` | |
| `data.snapshot.nombre` | string | `entidad.nombre` | |
| `data.snapshot.nombre_comercial` | string or null | `entidad.nombre_comercial` | |
| `data.snapshot.tipo_persona` | `'Natural' \| 'Juridica'` | `entidad.tipo_persona` | ENUM column, NOT NULL. |
| `data.snapshot.identificacion` | string or null | `entidad.identificacion` | Tax ID / national ID. |
| `data.snapshot.email_principal` | string or null | `emails.email` where `tipo='trabajo'` AND `es_principal=true` | Single indexed lookup. |
| `data.snapshot.telefono_principal` | string or null | `telefonos.numero` where `tipo='trabajo'` AND `es_principal=true` | Same. |
| `data.snapshot.direccion_principal` | string or null | `direcciones.direccion_principal` where `es_principal=true` | |
| `data.snapshot.dominio` | string or null | `presencia_online.url` where `tipo='web'` AND `es_principal=true` | |
| `data.snapshot.is_active` | bool | Derived — see "is_active derivation rule" above. | **Always reflects the open pivot state**, never the legacy `entidad.estado` column. |
| `data.snapshot.relaciones_count` | int | `COUNT(*)` on `entidad_relacion` where `entidad_id = ?` | |
| `data.snapshot.contactos_count` | int | `COUNT(*)` on `personas ⨝ contacto` where `personas.entidad_id = ?` | |
| `data.snapshot.oportunidades_count` | int | `COUNT(*)` on `oportunidad` where `entidad_id = ?` | |
| `data.snapshot.usuarios_count` | int | `COUNT(*)` on `entidad_persona ⨝ usuarios` where `entidad_persona.entidad_id = ?` | The pivot is keyed on `persona_id` (Commit 2 / fe99f70); the join hops via `usuarios.persona_id`. |
| `data.snapshot.deleted_at` | ISO-8601 string or null | `entidad.deleted_at` | Stamped only on `deleted` actions so receivers can differentiate tombstones from live rows. |

### What triggers it

`EntidadChanged` fires from:

1. `StoreEntidadUseCase::execute()` — action `created`.
2. `UpdateEntidadUseCase::execute()` — action `updated`, only on `array_diff_assoc` effective change.
3. `DestroyEntidadUseCase::execute()` — action `deleted` (pre-delete snapshot captured before soft-delete).
4. `EntidadRelacionObserver` — action `updated`, on pivot `created` / `updated(effective_to)` / `deleted`.

The observer is wired in `App\Providers\AppServiceProvider::boot()` (line 106).

**Important**: the observer is INTENTIONALLY bypassed by raw
`DB::table('entidad_relacion')->insert(...)` calls. The inversion
path (`StorePersonaUseCase::createEntidadForNaturalPersona`) and
the test factory (`CreatesEntidadForTesting::makeEntidad`) use raw
inserts so they emit a single `EntidadChanged` from the use case
rather than two (one from the observer + one from the use case).
Mercurio's dedup key (`event_id`) keeps this safe even if a future
change accidentally double-emits.

---

## 8. Contract C — `?depth=1|2|3` API projection

The projection contract is the deep-read path. When Mercurio needs the
deeper relation graph (e.g. "give me this contacto with its linked
persona + entidad snapshots"), it calls crm-laravel with `?depth=N`.

### Query param semantics

| Value | Projection level | What it means |
|---|---|---|
| `1` | **Shallow** | Bare entity. NO side queries, NO eager-loaded relations. Cheapest. For list endpoints. |
| `2` | **Default** | Entity + direct first-degree relations. The canonical detail-endpoint shape. |
| `3` | **Deep** | + nested second-degree relations (e.g. `oportunidad.detalles.producto`, `entidad.direcciones[]`). For webhook-style snapshots and the Mercury mirror. |
| absent / `null` / `''` | **Default (depth=2)** | The default. The canonical detail shape. |
| non-integer (`'abc'`, `'1.5'`) | **Default** | Clamped silently. No 4xx. |
| out-of-range (`0`, `4`, `-1`) | **Default** | Clamped silently. No 4xx. |
| `?depth=99` | **Default** | Clamped silently. The whole point is to be a HINT, not a contract — the projection MUST never return a 4xx because the caller asked for the wrong depth. |

The resolution is centralised in `App\Enums\ProjectionLevel::fromRequest()`
(lines 41-62). It uses `filter_var($raw, FILTER_VALIDATE_INT)` for
sanitization (rejects `"1.5"`, `"abc"`, `"1; DROP TABLE"`).

### Per-resource behaviour

| Resource | Endpoint | Shallow (depth=1) | Default (depth=2) | Deep (depth=3) |
|---|---|---|---|---|
| Persona | `GET /api/v1/personas/{id}` | 7 identity fields. NO `relations`, NO primary email/phone/address. | + `email_principal`, `telefono_principal`, `direccion`, `ciudad`, `pais`, `relations`. | + nested `entidad` snapshot (linked `entidad.nombre` + `identificacion`). |
| Oportunidad | `GET /api/v1/oportunidades/{id}` | Bare row. `entidad_nombre` sentinel `"#{$id}"`. NO `detalles`, NO `valor`. | + `detalles[]`, `valor` (sum of `vr_total`), flat `entidad_nombre` / `entidad_identificacion`. | + nested `entidad` object, `detalles[].producto` nested object. |
| Entidad | `GET /api/v1/entidades/{id}` | 6 identity fields. NO `direccion`/`email`/`telefono`/`dominio`/`ciudad_cod`. NO `cantidad_empleados` / `rut` / `logo` / `estado` / audit stamps. | + the principal-row lookups, + `cantidad_empleados`, `rut`, `logo`, `estado` (derived from pivot), audit stamps, count fields. | + nested `direcciones[]`, `emails[]`, `telefonos[]`, `presenciaOnline[]`, `documentos[]`, `relaciones[]` (full second-degree collections). |
| Seguimiento | `GET /api/v1/seguimientos/{id}` | Bare row. NO `entidad_nombre` / `contacto_nombre` / `oportunidad_codigo` / `autor_nombre`. | + the 4 accessors (resolved via single-row joins when the entity doesn't carry them). | + nested `persona` snapshot, nested `oportunidad` snapshot (incl. `detalles[]`). |
| Contacto | `GET /api/v1/contactos/{id}` | Bare row. NO `entidad_id` lookup, NO `entidad_nombre` accessor. | + `entidad_id` (from `entidad_persona` pivot) + `entidad_nombre`. | + nested `persona` snapshot (with primary email) + nested `entidad` snapshot. |

### What each level eagerly loads

The eager-load pattern at the controller layer (`EntidadController::show`,
`OportunidadController::show`, etc.) is matched to the depth level.
Shallow endpoints skip eager-load entirely (the resource doesn't ask
for relations anyway), Default endpoints load direct relations, Deep
endpoints load second-degree relations. This avoids N+1 in the deep
case and avoids wasted DB roundtrips in the shallow case.

The eager-load decision lives in the controllers, NOT in the
resources, because the resources work against both Eloquent models
(domain entities passed through the use case) and the controller is
where the model-vs-entity decision is made. Resources that don't
find a relation loaded fall back to single-row joins (e.g.
`SeguimientoResource::resolvedEntidadNombre()` lines 88-99).

### Why this exists

Before Commit 6 every endpoint embedded the full relation graph
unconditionally. List endpoints paid for N+1 queries they didn't
need. Deep-read endpoints (the Mercury mirror, the webhook snapshot
generator) wanted more graph than the canonical detail shape
exposed. The `?depth=` param lets Mercurio request exactly the shape
it needs without us inventing a new endpoint per use case.

---

## 9. Local dev / testing

### Pointing Mercurio's webhook URL at a local mock

Use `Http::fake()` in tests — no network round-trip needed:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'http://localhost:8000/api/webhook/personas-snapshot' => Http::response(['ok' => true], 200),
    'http://localhost:8000/api/webhook/entidades-snapshot' => Http::response(['ok' => true], 200),
]);
```

The fake applies to ANY URL matching the pattern, so you can use
specific URLs per test. See `tests/Feature/API/PersonaWebhookEmitterTest.php`
and `tests/Feature/API/EntidadWebhookEmitterTest.php` for canonical
examples.

For local dev with a real Mercurio instance pointing at crm-laravel,
override the URL via env:

```bash
# .env (local dev)
PERSONA_SNAPSHOT_WEBHOOK_URL=http://localhost:8000/api/webhook/personas-snapshot
ENTIDADES_SNAPSHOT_WEBHOOK_URL=http://localhost:8000/api/webhook/entidades-snapshot
EMIT_PERSONA_SNAPSHOT_WEBHOOK=true
EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=true
```

### `.env.example` keys

The full set of env vars Mercurio's integration touches (see
`.env.example` lines 85-102):

```bash
# Outbound shared secret (fallback for the two CQRS-mirror flows).
WEBHOOK_OUTBOUND_SECRET=your-webhook-secret-key

# Personas snapshot (Contract A).
EMIT_PERSONA_SNAPSHOT_WEBHOOK=true
PERSONA_SNAPSHOT_WEBHOOK_URL=
PERSONA_SNAPSHOT_WEBHOOK_SECRET=

# Entidades snapshot (Contract B).
EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=true
ENTIDADES_SNAPSHOT_WEBHOOK_URL=
ENTIDADES_SNAPSHOT_WEBHOOK_SECRET=
```

When `*_WEBHOOK_SECRET` is empty, the listener falls back to
`WEBHOOK_OUTBOUND_SECRET`. Operators who want a dedicated secret for
one flow can set just that var.

### Reproducing locally with the crm-laravel queue worker

The webhooks are dispatched onto the `webhooks` queue. Run the worker:

```bash
php artisan queue:work --queue=webhooks --tries=3
```

In production EasyPanel runs this as a daemon process. In local dev
you can run it manually or `composer dev` (which runs
`queue:listen` alongside `artisan serve`).

---

## 10. Known limitations

| Limitation | Workaround | Tracked in |
|---|---|---|
| Webhook payload and deep projection (`?depth=3`) do NOT share a builder. The webhook builds via `EntidadSnapshotBuilder` (a flat projection), the deep resource builds via `EntidadResource::toArray()` at depth 3 (a nested projection). They can drift if you add a field to one but not the other. | Document both shapes side-by-side (this file). Add a parity test if drift becomes a recurring bug. | n/a |
| Inversion paths (e.g. `StorePersonaUseCase::createEntidadForNaturalPersona`) intentionally emit TWICE for the persona flow if they ALSO write to the entidad via raw SQL — once from the use case, once from the `EntidadRelacionObserver` if the observer is later extended to entidades themselves. Currently the observer is scoped to `entidad_relacion` only, so the inversion path emits once per write target. | The UUIDv4 `event_id` makes accidental double-emits safe (Mercurio dedups). | n/a |
| The `personas.snapshot.sync` event_id was added in Commit 7 to match the entidad flow. Older receivers (Mercurio pre-v0.4) may not expect it. | Treat `event_id` as optional in Mercurio. v0.4+ reads it; older versions ignore it. | n/a |
| The deep projection at `?depth=3` for `Entidad` issues 6 separate collection queries (direcciones, emails, telefonos, presenciaOnline, documentos, relaciones). For an entity with thousands of rows in any of these collections, the response can balloon. | Document the cap. Add `LIMIT 1000` per collection if it becomes a problem in production. | n/a |
| The `dispatchWebhook($result, 'entidad.created', [...])` call in `EntidadController::store` is a SEPARATE webhook (the legacy flat envelope from `CrmWebhookSender::send`, NOT the snapshot emitter). This is the `n8n` / pipeline-etapa style outbound webhook — different secret, different config prefix. | Don't confuse the two. The snapshot emitter is the new Mercury flow; the legacy `dispatchWebhook` is for n8n. | n/a |
| `EntidadRelacionObserver::updated()` only emits when `effective_to` was changed. Pure audit-stamp advances (`created_by`, `updated_by`) are silent. | Don't expect every `entidad_relacion` write to emit. | See Commit 7 docs (`FIXUP-PENDING.md` Commit 7 section). |
| `Persona::getTipoPersonaAttribute()` reads from the linked `entidad.tipo_persona`. Unbound personas default to `'Natural'`. If you need to distinguish "explicitly Natural" from "unbound Natural", you have to inspect the underlying relation — there's no separate flag. | Add an `entidad_id` non-null check at the receiver if you need the distinction. | n/a |

---

## Cross-references

- OpenSpec mirror (formal spec format): `openspec/changes/tenant-data-model-correction/specs/mercurio-contracts/spec.md`
- Code anchors:
  - Domain events: `app/Domain/Events/{PersonaChanged,EntidadChanged}.php`
  - Listeners: `app/Infrastructure/Webhook/{PersonasSnapshotEmitter,EntidadesSnapshotEmitter}.php`
  - Snapshot builder (entidad): `app/Infrastructure/Webhook/EntidadSnapshotBuilder.php`
  - Webhook transport: `app/Infrastructure/Webhook/{CrmWebhookSender,DispatchOutboundWebhookJob}.php`
  - Config: `config/webhook.php`
  - Pivot observer: `app/Observers/EntidadRelacionObserver.php`
  - Projection enum: `app/Enums/ProjectionLevel.php`
  - Depth-aware resources: `app/Http/Resources/{BaseResource,PersonaResource,OportunidadResource,EntidadResource,SeguimientoResource,ContactoResource}.php`
  - Test patterns: `tests/Feature/API/{PersonaWebhookEmitterTest,EntidadWebhookEmitterTest,EntidadRelacionObserverTest,DepthProjectionTest}.php`
  - Smoke test for column drops (Commit 8): `tests/Feature/Database/LegacyColumnsDroppedTest.php`

## Change log

| Date | Change | Author |
|---|---|---|
| 2026-09-09 | Initial version (Consolidates Commits 5.5 / 6 / 7 / 8 of `tenant-data-model-correction`). | CRM team. |
