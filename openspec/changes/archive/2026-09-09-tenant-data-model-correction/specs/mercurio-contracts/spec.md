# Spec: crm-laravel → Mercurio Integration Contracts

> **Cross-reference**: this spec mirrors the human-readable reference at
> `Docs/integrations/mercurio-webhook-contracts.md`. Both files must be
> updated together — the prose doc is the source of code-anchored truth,
> this spec is the source of formal requirements for `/sdd-verify`.

## Purpose

Capture the three stable contracts between `crm-laravel` and the
Mercurio CQRS mirror so they can be verified against the
implementation rather than against tribal knowledge:

1. **`personas.snapshot.sync`** webhook (Contract A) — persona
   create/update/delete → Mercurio's persona mirror.
2. **`entidades.snapshot.sync`** webhook (Contract B) — entidad
   create/update/delete + pivot mutations → Mercurio's entidad mirror.
3. **`?depth=1|2|3` API projection** (Contract C) — Mercurio's
   deep-read query param against the canonical REST endpoints.

## Scope

**In scope:**

- Wire shape (field names, types, dedup keys) for the three contracts.
- Authentication (HMAC-SHA256 over the raw body) and secret lookup.
- Retry/backoff semantics (`tries=3`, `[60,120,180]`s, `Http::timeout(10)`).
- Replay protection (UUIDv4 `event_id` on entidad events, `(id, occurred_at)` on persona events).
- Kill switches (`EMIT_PERSONA_SNAPSHOT_WEBHOOK`, `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK`).
- Depth-projection semantics (clamping, what each level loads, per-resource behaviour).
- Local dev hooks (`Http::fake`, `.env.example` keys).

**Out of scope:**

- Mercurio's internal storage shape (that's Mercurio's spec).
- Receiver-side verification logic — only the crm-laravel emission
  side is contracted here. See the Python snippet in
  `Docs/integrations/mercurio-webhook-contracts.md` section 2 for the
  reference verification.
- Per-flow rate limiting or batching.
- Webhook payload encryption (HTTPS only).
- Multi-tenant scoping inside the payload — the entity's tenant scope
  is implicit from `entidad_id` / `persona_id` (Mercurio joins on
  its own tenant table).

---

## Requirements

### REQ-WHK-001: HMAC-SHA256 over the raw body

The webhook transport (`CrmWebhookSender::sendRaw()`) MUST sign the
**exact JSON body bytes** sent on the wire with HMAC-SHA256, prefixed
with `sha256=`, and surface the digest in the `X-CRM-Signature`
header. The signature MUST be computed before the body is serialised
so the bytes signed and the bytes sent are identical.

- **Anchor**: `app/Infrastructure/Webhook/CrmWebhookSender.php` lines 64-98.
- **Test**: `tests/Unit/Infrastructure/Webhook/CrmWebhookSenderTest.php`.

#### Scenario 1.1: Default prefix and header name

- **Given** a payload `[1, 2, 3]` and secret `"abc"`
- **When** the sender signs the payload
- **Then** the `X-CRM-Signature` header value is `"sha256=" + hex(hmac_sha256('abc', '[1,2,3]'))`

#### Scenario 1.2: Secret fallback to `webhook.outbound.secret`

- **Given** `webhook.personas_snapshot.secret` is `null`
- **And** `webhook.outbound.secret` is `"outbound-secret"`
- **When** the sender signs a `personas_snapshot` payload
- **Then** the signature uses `"outbound-secret"` as the key

#### Scenario 1.3: Secret fallback does NOT apply to `n8n_pipeline`

- **Given** `webhook.n8n_pipeline.secret` is `null`
- **And** `webhook.outbound.secret` is `"outbound-secret"`
- **When** the sender signs an `n8n_pipeline` payload
- **Then** the signature uses `""` (empty key) because the fallback is scoped to the two CQRS-mirror prefixes only
- **And** the receiver rejects the signature as a real misconfiguration

### REQ-WHK-002: Retry semantics — `tries=3`, backoff `[60, 120, 180]`

`DispatchOutboundWebhookJob` MUST retry up to 3 times with the
backoff schedule `[60s, 120s, 180s]` and HTTP timeout 10s. After
the 3rd failure the job MUST land in `failed_jobs` with the original
payload and response body preserved.

- **Anchor**: `app/Infrastructure/Webhook/DispatchOutboundWebhookJob.php` lines 44-86.

#### Scenario 2.1: Non-2xx response triggers retry

- **Given** a webhook delivery that returns HTTP 500
- **When** the job processes the delivery
- **Then** the job throws `RuntimeException`
- **And** the queue worker retries with the documented backoff

#### Scenario 2.2: Transport failure (timeout) triggers retry

- **Given** the receiver times out (no response in 10s)
- **When** the job processes the delivery
- **Then** the sender returns `null`
- **And** the job throws `RuntimeException("Webhook transport failed for event ...")`
- **And** the queue worker retries

#### Scenario 2.3: HTTP 2xx terminates the job

- **Given** a webhook delivery that returns HTTP 200
- **When** the job processes the delivery
- **Then** the job completes silently with no retry

#### Scenario 2.4: 3 retries exhaust → `failed_jobs`

- **Given** 3 consecutive non-2xx responses
- **When** the queue worker processes the 3rd failure
- **Then** the job lands in `failed_jobs`
- **And** the row contains `payload`, `exception`, and the last response body

### REQ-WHK-003: Personas snapshot envelope (`personas.snapshot.sync`)

The `PersonasSnapshotEmitter` listener MUST emit an envelope with the
fields listed below. Field names are part of the public wire
contract.

- **Anchor**: `app/Infrastructure/Webhook/PersonasSnapshotEmitter.php` lines 36-78.
- **Test**: `tests/Feature/API/PersonaWebhookEmitterTest.php`.

#### Scenario 3.1: Wire envelope shape

- **Given** a successful persona create
- **When** the listener dispatches the job
- **Then** the wire payload has:
  - `event` = `"personas.snapshot.sync"`
  - `timestamp` (RFC3339)
  - `data.action` = `"created"`
  - `data.persona_id` (int)
  - `data.occurred_at` (RFC3339)
  - `data.snapshot` (object: `id`, `nombres`, `apellidos`, `tipo_persona`, `email_principal`, `telefono_principal`, `direccion`, `ciudad`, `pais`, `relations.{contacto_id,colaborador_id,proveedor_id,entidad_id}`)

#### Scenario 3.2: PATCH with no effective change → no event

- **Given** a persona updated via PUT with the SAME values Eloquent already has
- **When** the use case runs
- **Then** NO `PersonaChanged` event is dispatched
- **And** NO webhook is queued
- **Mirrors**: REQ-PSWH-006 (Commit 5.5 persona-style "no effective change" rule).

#### Scenario 3.3: DELETE captures pre-delete snapshot

- **Given** a persona soft-deleted
- **When** the use case runs
- **Then** the dispatched event has `action='deleted'`
- **And** the snapshot carries the pre-delete persona attributes
- **And** `snapshot.deleted_at` is a non-null RFC3339 string

#### Scenario 3.4: Idempotency key

- **Given** Mercurio needs to dedupe re-deliveries
- **When** the receiver decides whether to upsert
- **Then** the canonical dedup key is `(persona_id, occurred_at)`

### REQ-WHK-004: Entidades snapshot envelope (`entidades.snapshot.sync`)

Mirrors REQ-WHK-003 for entities. The entity event ADDITIONALLY carries
a UUIDv4 `event_id` so high-volume pivot mutations can be deduped.

- **Anchor**: `app/Infrastructure/Webhook/EntidadesSnapshotEmitter.php` lines 36-80.
- **Snapshot builder**: `app/Infrastructure/Webhook/EntidadSnapshotBuilder.php`.

#### Scenario 4.1: Wire envelope shape

- **Given** a successful entidad create
- **When** the listener dispatches the job
- **Then** the wire payload has:
  - `event` = `"entidades.snapshot.sync"`
  - `timestamp` (RFC3339)
  - `data.action` = `"created"`
  - `data.entidad_id` (int)
  - `data.event_id` (UUIDv4)
  - `data.occurred_at` (RFC3339)
  - `data.snapshot` (object: `id`, `nombre`, `nombre_comercial`, `tipo_persona`, `identificacion`, `email_principal`, `telefono_principal`, `direccion_principal`, `dominio`, `is_active`, `relaciones_count`, `contactos_count`, `oportunidades_count`, `usuarios_count`, `deleted_at`)

#### Scenario 4.2: `is_active` derivation rule

- **Given** the entity has at least one `entidad_relacion` row with `effective_to IS NULL`
- **When** the snapshot is built
- **Then** `snapshot.is_active` is `true`
- **Given** the entity has zero such rows (or no pivot rows at all)
- **Then** `snapshot.is_active` is `false`

#### Scenario 4.3: UUIDv4 `event_id`

- **Given** three pivot mutations on the same entity (create + update + delete)
- **When** the snapshot is dispatched each time
- **Then** each event carries a distinct UUIDv4 `event_id` (Mercurio dedups by this key)

#### Scenario 4.4: Pivot mutations emit `updated` events

- **Given** a pivot row inserted via `DB::table('entidad_relacion')->insert(...)`
- **When** the Eloquent observer fires (the use case path)
- **Then** the dispatched event has `action='updated'`
- **And** `snapshot.is_active` reflects the new open-row state

- **Given** a pivot row inserted via raw `DB::table('entidad_relacion')->insert(...)` from the inversion path
- **When** the inversion flow runs
- **Then** NO observer event fires (intentional bypass)
- **And** the use case emits a single `EntidadChanged` so Mercurio sees exactly one event per effective write

#### Scenario 4.5: PATCH with no effective change → no event

- **Given** an entidad updated via PUT with the SAME values Eloquent already has
- **When** the use case runs
- **Then** NO `EntidadChanged` event is dispatched
- **And** NO webhook is queued
- **Mirrors**: REQ-PSWH-006.

#### Scenario 4.6: Listener never throws (R-3)

- **Given** a misconfigured webhook path (missing secret, invalid URL, etc.)
- **When** the originating REST write commits
- **Then** the listener catches the `Throwable`
- **And** the originating REST write returns 2xx
- **And** the structured log `entidades_snapshot.listener_failed` is emitted

### REQ-WHK-005: Kill switches

Each snapshot flow MUST honour an env-var kill switch that, when
`false`, makes the listener a no-op. The default is `true` so an
unconfigured environment still emits.

- **Anchor**: `config/webhook.php` lines 77-118.

#### Scenario 5.1: Personas kill-switch

- **Given** `EMIT_PERSONA_SNAPSHOT_WEBHOOK=false`
- **When** a persona write commits
- **Then** the listener logs `personas_snapshot.skipped` with `{persona_id, action, reason: 'disabled'}`
- **And** NO `DispatchOutboundWebhookJob` is queued

#### Scenario 5.2: Entidades kill-switch

- **Given** `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=false`
- **When** an entidad write commits
- **Then** the listener logs `entidades_snapshot.skipped` with `{entidad_id, action, reason: 'disabled'}`
- **And** NO `DispatchOutboundWebhookJob` is queued

#### Scenario 5.3: Kill-switch is per-flow

- **Given** `EMIT_PERSONA_SNAPSHOT_WEBHOOK=false`
- **And** `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=true`
- **When** BOTH a persona AND an entidad write commit
- **Then** ONLY the entidad event fires
- **And** the persona listener stays silent

### REQ-PROJ-001: `?depth=1|2|3` projection semantics

The `?depth=` query param MUST control how much relation graph is
embedded in the API response. Unrecognised or out-of-range values
MUST clamp to `Default` (depth=2) without returning a 4xx.

- **Anchor**: `app/Enums/ProjectionLevel.php` lines 27-78.

#### Scenario 1.1: Missing param defaults to Default

- **Given** a `GET /api/v1/personas/123` with no `?depth=` param
- **When** the controller responds
- **Then** the response shape matches depth=2 (Default)

#### Scenario 1.2: Invalid value clamps to Default

- **Given** `?depth=99`
- **When** the controller responds
- **Then** the response shape matches depth=2 (Default)
- **And** the response is 200, not 4xx

#### Scenario 1.3: Negative value clamps to Default

- **Given** `?depth=-1`
- **When** the controller responds
- **Then** the response shape matches depth=2 (Default)

#### Scenario 1.4: Shallow strips relation lookups

- **Given** `?depth=1` on `GET /api/v1/entidades/123`
- **When** the controller responds
- **Then** the response includes only the 6 identity fields (`id`, `tipo_persona`, `tipo_id`, `identificacion`, `nombre`, `nombre_comercial`)
- **And** NO `direccion` / `email` / `telefono` / `dominio` / `ciudad_cod` lookups
- **And** NO `cantidad_empleados` / `rut` / `logo` / `estado`
- **And** NO nested `direcciones[]` / `emails[]` / `telefonos[]` / `presenciaOnline[]` / `documentos[]` / `relaciones[]`

#### Scenario 1.5: Deep surfaces nested collections

- **Given** `?depth=3` on `GET /api/v1/entidades/123`
- **When** the controller responds
- **Then** the response includes the nested `direcciones[]` / `emails[]` / `telefonos[]` / `presenciaOnline[]` / `documentos[]` / `relaciones[]` arrays

#### Scenario 1.6: Per-resource behaviour table

The `?depth=` semantics MUST follow the table in
`Docs/integrations/mercurio-webhook-contracts.md` section 8
("Per-resource behaviour") for these five resources:

- `Persona` — `app/Http/Resources/PersonaResource.php`
- `Oportunidad` — `app/Http/Resources/OportunidadResource.php`
- `Entidad` — `app/Http/Resources/EntidadResource.php`
- `Seguimiento` — `app/Http/Resources/SeguimientoResource.php`
- `Contacto` — `app/Http/Resources/ContactoResource.php`

### REQ-PROJ-002: Index endpoints honour shallow

When `?depth=1` is set on an INDEX endpoint (e.g. `GET /api/v1/entidades`),
the controller MUST skip the eager-load of relations so list responses
stay cheap.

- **Anchor**: each `*Controller::index` method, e.g. `app/Http/Controllers/API/OportunidadController.php` lines covering the index handler.

#### Scenario 2.1: Index with `?depth=1` skips eager-load

- **Given** `?depth=1` on `GET /api/v1/oportunidades`
- **When** the controller responds
- **Then** the SQL log shows NO eager-load of `entidad` or `detalles`
- **And** the response items carry only the bare row fields

### REQ-CFG-001: `.env.example` documents the integration

The `.env.example` MUST document the env vars the integration
touches, with a comment per var explaining its purpose and default.

- **Anchor**: `.env.example` lines 85-102.

#### Scenario 1.1: Required keys

The file MUST include:

- `EMIT_PERSONA_SNAPSHOT_WEBHOOK` (default `true`)
- `PERSONA_SNAPSHOT_WEBHOOK_URL` (default empty — falls back to `webhook.personas_snapshot.url` config default)
- `PERSONA_SNAPSHOT_WEBHOOK_SECRET` (default empty — falls back to `WEBHOOK_OUTBOUND_SECRET`)
- `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK` (default `true`)
- `ENTIDADES_SNAPSHOT_WEBHOOK_URL`
- `ENTIDADES_SNAPSHOT_WEBHOOK_SECRET`

---

## Cross-references

- Human-readable reference: `Docs/integrations/mercurio-webhook-contracts.md`
- Source files:
  - `app/Domain/Events/{PersonaChanged,EntidadChanged}.php`
  - `app/Infrastructure/Webhook/{PersonasSnapshotEmitter,EntidadesSnapshotEmitter,CrmWebhookSender,DispatchOutboundWebhookJob,EntidadSnapshotBuilder}.php`
  - `app/Observers/EntidadRelacionObserver.php`
  - `app/Enums/ProjectionLevel.php`
  - `app/Http/Resources/{BaseResource,PersonaResource,OportunidadResource,EntidadResource,SeguimientoResource,ContactoResource}.php`
  - `config/webhook.php`
  - `.env.example`
- Tests:
  - `tests/Unit/Infrastructure/Webhook/CrmWebhookSenderTest.php`
  - `tests/Feature/API/{PersonaWebhookEmitterTest,EntidadWebhookEmitterTest,EntidadRelacionObserverTest,DepthProjectionTest}.php`
  - `tests/Feature/Database/LegacyColumnsDroppedTest.php` (smoke for Commit 8's column drops referenced in this spec)
- OpenSpec change root: `openspec/changes/tenant-data-model-correction/`
- Related changes:
  - `crm-laravel-iter4-persona` (PR-J baseline for the persona event).
  - `tenant-data-model-fixes` (Commit 2 — pivot rename to `entidad_persona`).

## Change log

| Date | Change |
|---|---|
| 2026-09-09 | Initial version. Captures Commits 5.5 / 6 / 7 / 8 of `tenant-data-model-correction`. |
