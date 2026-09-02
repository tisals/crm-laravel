# Spec: Pipelines CRUD + Webhook (Phase 2 SAIlus)

## Overview

Full REST CRUD for pipelines and nested etapas, a `PipelineEtapaChanged` event + queued n8n webhook, bulk move oportunidades endpoint, and one-time data migration from legacy `estado` to `pipeline_etapa_id`. Frontend adds PipelineAdmin page at `/crm/pipelines` and updates Kanban drag to use `pipeline_etapa_id`.

All new backend code follows Clean Architecture (Controllers → UseCases → Repository Interfaces → Eloquent Repositories). Strict TDD applies — every feature requires unit/feature tests first.

## Scope

**In scope:** Pipeline CRUD, PipelineEtapa nested CRUD + reorder, PipelineEtapaChanged event, SendPipelineWebhookListener, n8n webhook config, bulk move oportunidades, data migration (estado → pipeline_etapa_id), PipelineSeeder, PermisoSeeder updates, frontend PipelineAdmin page, frontend Kanban drag fix, route nesting under `/crm/`.

**Out of scope:** Lead scoring, Laravel Pipeline pattern for lead intake, cron jobs, direct Mailrelay API integration, soft deletes, archiving, pipeline-level RBAC.

---

## Requirements

### REQ-1: Pipeline CRUD

**As a** CRM admin with `pipelines.*` permissions
**I want** full REST CRUD for pipelines
**So that** I can manage sales stages configuration

| Method | Endpoint | Permission | Description |
|--------|----------|-----------|-------------|
| GET | `/api/v1/pipelines` | `pipelines.read` | List all pipelines with enabled etapas |
| POST | `/api/v1/pipelines` | `pipelines.create` | Create new pipeline |
| GET | `/api/v1/pipelines/{id}` | `pipelines.read` | Show pipeline with all etapas |
| PUT | `/api/v1/pipelines/{id}` | `pipelines.update` | Update pipeline |
| DELETE | `/api/v1/pipelines/{id}` | `pipelines.delete` | Delete pipeline (cascades to etapas via FK) |

#### Scenario 1.1: Create pipeline
- **Given** I am authenticated with `pipelines.create` permission
- **When** I POST to `/api/v1/pipelines` with `{ nombre: "Cotización", codigo: "cotizacion", habilitado: true }`
- **Then** status is 201
- **And** response envelope is `{ success: true, data: { id, nombre, codigo, habilitado, etapas, created_at, updated_at } }`

#### Scenario 1.2: List pipelines
- **Given** 2 pipelines exist
- **When** I GET `/api/v1/pipelines`
- **Then** status is 200
- **And** response returns `{ success: true, data: [...] }` with all pipelines ordered by `created_at` desc

#### Scenario 1.3: Show pipeline with etapas
- **Given** pipeline #1 exists with 3 etapas
- **When** I GET `/api/v1/pipelines/1`
- **Then** status is 200
- **And** `data.etapas` is an array of `{ id, pipeline_id, nombre, orden, habilitado }` ordered by `orden` asc

#### Scenario 1.4: Update pipeline
- **Given** pipeline #1 exists
- **When** I PUT `/api/v1/pipelines/1` with `{ nombre: "Pipeline Editado" }`
- **Then** status is 200
- **And** `data.nombre` equals "Pipeline Editado"

#### Scenario 1.5: Delete pipeline
- **Given** pipeline #1 exists with 2 etapas
- **When** I DELETE `/api/v1/pipelines/1`
- **Then** status is 200
- **And** pipeline and all its etapas are deleted from DB

#### Scenario 1.6: Validation — duplicate codigo
- **Given** pipeline with `codigo: "cotizacion"` exists
- **When** I POST `/api/v1/pipelines` with `{ nombre: "Otro", codigo: "cotizacion" }`
- **Then** status is 422
- **And** `error` contains `codigo` uniqueness violation

#### Scenario 1.7: Authorization — missing permission
- **Given** I am authenticated without `pipelines.create`
- **When** I POST `/api/v1/pipelines`
- **Then** status is 403

### REQ-2: Pipeline Etapa CRUD (nested)

**As a** CRM admin with `pipeline-etapas.*` permissions
**I want** to manage etapas nested under a pipeline
**So that** I can configure stages with ordering

| Method | Endpoint | Permission | Description |
|--------|----------|-----------|-------------|
| GET | `/api/v1/pipelines/{pipeline}/etapas` | `pipeline-etapas.read` | List etapas for pipeline |
| POST | `/api/v1/pipelines/{pipeline}/etapas` | `pipeline-etapas.create` | Create etapa (auto-assigns next `orden`) |
| GET | `/api/v1/pipelines/etapas/{id}` | `pipeline-etapas.read` | Show single etapa |
| PUT | `/api/v1/pipelines/etapas/{id}` | `pipeline-etapas.update` | Update etapa |
| DELETE | `/api/v1/pipelines/etapas/{id}` | `pipeline-etapas.delete` | Delete etapa |
| PUT | `/api/v1/pipelines/{pipeline}/etapas/reorder` | `pipeline-etapas.update` | Reorder all etapas atomically |

#### Scenario 2.1: Create etapa
- **Given** pipeline #1 exists with 2 etapas (orden 1, 2)
- **When** I POST `/api/v1/pipelines/1/etapas` with `{ nombre: "Aprobado" }`
- **Then** status is 201
- **And** `data.orden` is 3 (auto-incremented)

#### Scenario 2.2: List etapas ordered
- **Given** pipeline #1 has etapas with orden 3, 1, 2
- **When** I GET `/api/v1/pipelines/1/etapas`
- **Then** response returns etapas ordered by `orden` asc (1, 2, 3)

#### Scenario 2.3: Reorder etapas
- **Given** pipeline #1 has etapas with IDs [10, 20, 30] and orden [1, 2, 3]
- **When** I PUT `/api/v1/pipelines/1/etapas/reorder` with `{ ordered_ids: [30, 10, 20] }`
- **Then** status is 200
- **And** etapa 30 has orden 1, etapa 10 has orden 2, etapa 20 has orden 3

#### Scenario 2.4: Reorder — cross-pipeline validation
- **Given** pipeline #1 has etapa IDs [10, 20] and pipeline #2 has etapa ID [30]
- **When** I PUT `/api/v1/pipelines/1/etapas/reorder` with `{ ordered_ids: [10, 30] }`
- **Then** status is 422
- **And** error indicates etapa 30 does not belong to pipeline #1

### REQ-3: Bulk Move Oportunidades

**As a** CRM admin with `oportunidades.bulk-move` permission
**I want** to move multiple oportunidades to a target pipeline/etapa in one transaction
**So that** I can batch-rescue cold leads to the Recuperación pipeline

#### Scenario 3.1: Bulk move all succeed
- **Given** oportunidades [1, 2, 3] exist with `pipeline_etapa_id` in pipeline #1
- **Given** pipeline #2 exists with target etapa ID 5
- **When** I POST `/api/v1/oportunidades/bulk-move-pipeline` with `{ oportunidad_ids: [1, 2, 3], target_pipeline_etapa_id: 5 }`
- **Then** status is 200
- **And** `data.moved_count` is 3
- **And** all 3 oportunidades now have `pipeline_etapa_id = 5`
- **And** each oportunidad fired a `PipelineEtapaChanged` event

#### Scenario 3.2: Bulk move — some IDs invalid
- **Given** oportunidades [1, 2] exist and 999 does not
- **When** I POST with `{ oportunidad_ids: [1, 2, 999], target_pipeline_etapa_id: 5 }`
- **Then** status is 422
- **And** `error` lists 999 as invalid
- **And** no oportunidades were updated (transaction rolled back)

### REQ-4: PipelineEtapaChanged Event

**As a** developer
**I want** a `PipelineEtapaChanged` event dispatched when `pipeline_etapa_id` changes on Oportunidad
**So that** the n8n webhook listener can trigger autoresponder campaigns

#### Scenario 4.1: Event fires on etapa change
- **Given** an Oportunidad with `pipeline_etapa_id = 1`
- **When** `pipeline_etapa_id` is updated to 2 via any save path (API, seeder, console)
- **Then** `PipelineEtapaChanged` is dispatched
- **And** payload includes `oportunidad_id`, `previous_etapa_id`, `new_etapa_id`, `pipeline_id`, `user_id`

#### Scenario 4.2: Event does NOT fire on non-etapa update
- **Given** an Oportunidad
- **When** only `total` field is updated (not `pipeline_etapa_id`)
- **Then** `PipelineEtapaChanged` is NOT dispatched

### REQ-5: Webhook to n8n

**As a** system
**I want** to send a signed webhook to n8n when `PipelineEtapaChanged` fires
**So that** n8n can trigger the Mailrelay autoresponder campaign

#### Scenario 5.1: Webhook queued on event
- **Given** `N8N_PIPELINE_WEBHOOK_URL` is configured
- **When** `PipelineEtapaChanged` is dispatched
- **Then** `DispatchOutboundWebhookJob` is queued with event type `pipeline.etapa.changed`
- **And** payload contains `oportunidad_id`, `old_etapa_nombre`, `new_etapa_nombre`, `pipeline_id`, `entidad_id`, `contacto_id`

#### Scenario 5.2: Webhook gracefully disabled
- **Given** `N8N_PIPELINE_WEBHOOK_URL` is empty/null
- **When** `PipelineEtapaChanged` is dispatched
- **Then** no webhook job is queued
- **And** no error is thrown

#### Scenario 5.3: HMAC-SHA256 signature
- **Given** `N8N_PIPELINE_WEBHOOK_SECRET` is configured
- **When** the webhook request is sent to n8n
- **Then** `X-Webhook-Signature` header contains HMAC-SHA256 of the payload body

### REQ-6: Data Migration (estado → pipeline_etapa_id)

**As a** developer running deploy
**I want** a one-time idempotent migration that backfills `pipeline_etapa_id` from legacy `estado`
**So that** all existing oportunidades are assigned to a pipeline stage

#### Scenario 6.1: Migrate matching estado
- **Given** an Oportunidad with `estado = "Borrador"` and `pipeline_etapa_id IS NULL`
- **Given** Pipeline Cotización exists with etapa named "Borrador"
- **When** the migration runs
- **Then** the oportunidad gets `pipeline_id = cotizacion.id` and `pipeline_etapa_id = borrador.id`

#### Scenario 6.2: Migration is idempotent
- **Given** an Oportunidad with `pipeline_etapa_id` already set
- **When** the migration runs again
- **Then** the row is skipped (no update)

### REQ-7: Default Pipelines Seeding

**As a** fresh install
**I want** 2 default pipelines seeded idempotently
**So that** the system works out of the box

#### Scenario 7.1: Seed Cotización pipeline
- **Given** no pipelines exist
- **When** `PipelineSeeder` runs
- **Then** Pipeline `Cotización` (codigo: COTIZACION) exists with etapas: Borrador, Enviado, En Negociación, Aprobado, Rechazado (orden 1–5)

#### Scenario 7.2: Seed Recuperación pipeline
- **When** `PipelineSeeder` runs
- **Then** Pipeline `Recuperación` (codigo: RECUPERACION) exists with etapas: Inicio, Con Cita, En Negociación, Aprobado, Rechazado (orden 1–5)

#### Scenario 7.3: Idempotent — no duplicates
- **Given** pipelines already seeded
- **When** `PipelineSeeder` runs again
- **Then** no duplicate pipelines or etapas are created

### REQ-8: Permission Updates

**As a** system
**I want** `PermisoSeeder` to grant pipeline and etapa permissions
**So that** RBAC enforcement works for all new endpoints

- `pipelines` → `[index, store, show, update, destroy]`
- `pipeline-etapas` → `[index, store, show, update, destroy]`
- `oportunidades` → add `bulk-move`

All assigned to admin role. Route naming matches permission keys (`pipelines.index`, `pipeline-etapas.index`, etc.).

### REQ-9: Frontend Routes under /crm/*

**As a** user
**I want** all CRM pages nested under `/crm/*` with shared layout
**So that** navigation is consistent

| Route | Component | Status |
|-------|-----------|--------|
| `/crm/dashboard` | DashboardPage | Existing |
| `/crm/pipelines` | PipelineAdmin | **NEW** (11 sub-routes) |
| `/crm/oportunidad` | CRMPage | **MODIFIED** (moved from `/crm`) |
| `/crm/contactos` | ContactosPage | Existing |
| `/crm/entidad` | EntidadPage | Existing |

**PipelineAdmin sub-routes:** index (`/crm/pipelines`), create (`/new`), show (`/:id`), edit (`/:id/edit`), delete (`/:id/delete`, special action), etapas index (`/:id/etapas`), etapa create (`/:id/etapas/new`), etapa show (`/:id/etapas/:etapaId`), etapa edit (`/:id/etapas/:etapaId/edit`), etapa delete (`/:id/etapas/:etapaId/delete`, special action), etapas reorder (`/:id/etapas/reorder`, special action).

**CRMPage (modified):** Moved from `/crm` to `/crm/oportunidad`. Adds sub-route `/crm/oportunidad/bulk-move-pipeline` (special action). Updates Kanban drag to send `pipeline_etapa_id` instead of `estado` string.

---

## Acceptance Criteria

- [ ] All PHPUnit tests pass (`composer test`)
- [ ] All API endpoints return normalized `{ success, data, message? }` envelope
- [ ] Pipeline CRUD: 5 endpoints working with validation + auth
- [ ] PipelineEtapa CRUD: 6 endpoints (incl. reorder) with cross-pipeline validation
- [ ] Bulk move: transactional all-or-nothing, each oportunidad fires event
- [ ] PipelineEtapaChanged fires ONLY on etapa change, not on every save
- [ ] n8n webhook uses HMAC-SHA256, queued with 3 retries (60/120/180s backoff)
- [ ] Webhook disabled gracefully when URL not configured
- [ ] Migration is idempotent — running twice is safe
- [ ] PipelineSeeder is idempotent — running twice creates no duplicates
- [ ] Permissions granted in PermisoSeeder match route names
- [ ] Frontend routes nested under `/crm/`
- [ ] Kanban drag sends `pipeline_etapa_id`

## Non-Functional Requirements

- **Performance:** List endpoints < 300ms p95
- **Security:** All API endpoints require `auth:sanctum` + RBAC permission
- **Idempotency:** Webhook payload includes `dedup_key`, retried 3× with exponential backoff
- **Test coverage:** Every UseCase has unit test; every Controller has Feature test; events have fired-when-expected tests; migration has migration test

## TDD Requirements

- All UseCases MUST have unit tests first
- All Controllers MUST have Feature tests (RefreshDatabase + Sanctum token)
- Events MUST have a fired-when-expected test (`PipelineEtapaChangedEventTest`)
- Migration MUST have a test that runs on fresh DB
- Factories: `PipelineFactory`, `PipelineEtapaFactory` for test isolation
- Canonical test pattern: `PlanControllerTest.php` (RefreshDatabase, `#[Test]` attribute, `authenticate()` helper)
