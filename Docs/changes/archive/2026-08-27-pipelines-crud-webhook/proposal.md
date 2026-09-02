# Proposal: Pipelines CRUD + Webhook to n8n (Mailrelay)

## Intent

This is Phase 2 of the SAIlus integration PRD-ADR. Phase 1 established the multi-pipeline schema: `pipelines`, `pipeline_etapas` tables, FK columns on `oportunidad`, auto-resolve logic in the `Oportunidad::saving()` hook, and a Kanban UI that consumes `GET /pipelines` to generate dynamic columns from etapas. What's missing is the ability to **manage** pipelines and etapas (create, edit, delete, reorder) and a **stage-change webhook** that triggers the marketing autoresponder campaign.

The business driver is a marketing campaign targeting women aged 25-44 who are system administrators, HR managers, accountants, or business owners at companies needing SG-SST services. When an Oportunidad changes pipeline stage (e.g., from "Borrador" to "Enviada"), the system must fire a webhook to n8n, which then calls the Mailrelay API to trigger the appropriate autoresponder email sequence. This automation directly supports the campaign's goal of nurturing leads through the pipeline without manual intervention.

The change delivers four capabilities: (1) full REST CRUD for pipelines and their etapas, (2) a `PipelineEtapaChanged` event dispatched from the existing observer, (3) a queued webhook job targeting a configurable n8n URL, and (4) a frontend admin UI (React/TanStack Query) for managing pipelines/etapas with inline forms and up/down reorder buttons.

## Scope

### In Scope

1. **Backend: Pipeline CRUD** — Add `store`, `show`, `update`, `destroy` methods to `PipelineController` following Clean Architecture (UseCases, Repository interfaces, Eloquent implementations, FormRequests, API Resources, Factories).
2. **Backend: PipelineEtapa Nested CRUD** — New `PipelineEtapaController` with full CRUD nested under `pipelines/{pipeline}/etapas`, using the same architecture. Includes a dedicated `reorder` endpoint (`PUT /pipelines/{pipeline}/etapas/reorder`) that accepts an ordered array of etapa IDs.
3. **New Event: `PipelineEtapaChanged`** — `App\Events\PipelineEtapaChanged` dispatched from `OportunidadObserver::updated()` when `isDirty('pipeline_etapa_id')` is true. Carries the `Oportunidad`, old `PipelineEtapa`, and new `PipelineEtapa`.
4. **New Listener: `SendPipelineWebhookListener`** — `App\Infrastructure\Webhook\Listeners\SendPipelineWebhookListener` fires `DispatchOutboundWebhookJob` with event type `pipeline.etapa.changed` and the full payload (oportunidad ID, old stage name, new stage name, pipeline name, entidad/contacto references).
5. **Configuration: n8n webhook URL** — New `N8N_PIPELINE_WEBHOOK_URL` and `N8N_PIPELINE_WEBHOOK_SECRET` env vars, new `webhook.pipeline` config section in `config/webhook.php`. The `CrmWebhookSender` will be extended to accept a config prefix, or we instantiate a separate one for the pipeline webhook.
6. **Permissions Update** — `PermisoSeeder` extended with `store`, `show`, `update`, `destroy` for `pipelines` and full CRUD for `pipeline-etapas`.
7. **Routes** — Full RESTful routes for pipelines and nested etapas under the existing `rbac` middleware group in `Modules/CRM/routes/api.php`. Remove the existing `GET /pipelines` route from `routes/api.php` (moved to module).
8. **Events mapping** — Register `PipelineEtapaChanged` → `SendPipelineWebhookListener` in `EventServiceProvider`.
9. **Frontend: TypeScript interfaces** — Add `Pipeline`, `PipelineEtapa` interfaces to `src/api/types.ts`. Update `Oportunidad` interface to include `pipeline_id`, `pipeline_etapa_id`, `pipeline_nombre`, `etapa_nombre`. Remove the hardcoded `OportunidadEstado` union type (now dynamic).
10. **Frontend: API functions** — Add CRUD functions for pipelines and etapas in `src/api/crmApi.ts` (create, update, delete, get by ID, reorder).
11. **Frontend: PipelineAdmin page** — New admin page/section for managing pipelines and etapas with inline forms, expandable etapa sections, and simple up/down reorder buttons (no drag & drop). Accessible from the sidebar navigation.
12. **Frontend: Update CRMPage Kanban** — Update the Kanban to use dynamic etapas from the API (already partially done), and fix `updateOportunidadEstado` to pass `pipeline_etapa_id` instead of `estado` string when dragging cards.
13. **Strict TDD** — Every backend feature gets a Feature test (`PipelineControllerTest`, `PipelineEtapaControllerTest`, `PipelineEtapaChangedEventTest`). Every domain class gets a Unit test where applicable. Factories for Pipeline and PipelineEtapa created for test isolation.

### Out of Scope

- **Lead Scoring (`AssignScoreAction`)** — Deferred to a future change.
- **Pipeline Pattern for lead intake** — The Laravel Pipeline pattern for `IngestLeadAction` is Phase 3 work.
- **`POST /api/v1/contacto/{id}/interaccion`** — Bot feedback endpoint not included.
- **Cron job for cold lead detection** — Phase 3 of PRD-ADR, including `DispatchFollowupsCommand`.
- **Direct Mailrelay API integration** — The webhook goes to n8n, and n8n is responsible for calling Mailrelay. This change does NOT add Mailrelay SDK or API calls to the Laravel codebase.
- **Soft deletes on Pipeline/PipelineEtapa** — The existing models don't use soft deletes, and the business doesn't need them yet. Deletes are hard deletes. This can be added later if needed.
- **Archiving pipelines/etapas** — Disabling via `habilitado = false` is sufficient.
- **Pipeline-level access controls** — No per-pipeline RBAC. If a user has `pipelines.*` permission, they see/edit all pipelines.
- **Frontend: OportunidadEstado union type removal from other components** — Only the types file and CRMPage will be updated; other components that reference the union type will be refactored only if they break.
- **Versioning / migration tooling** — No tool to migrate all oportunidad records between pipelines.

## Approach

### Backend Architecture

All new code follows the established Clean Architecture pattern in `app/` (Controllers → UseCases → Repository Interfaces → Eloquent Repositories).

#### 1. Repository Layer

Create two new repository interfaces and implementations:

| Interface | Implementation | Domain |
|-----------|---------------|--------|
| `App\Domain\Repositories\PipelineRepositoryInterface` | `App\Infrastructure\Persistence\EloquentPipelineRepository` | `Modules\CRM\Models\Pipeline` |
| `App\Domain\Repositories\PipelineEtapaRepositoryInterface` | `App\Infrastructure\Persistence\EloquentPipelineEtapaRepository` | `Modules\CRM\Models\PipelineEtapa` |

**PipelineRepositoryInterface** methods:
- `find(int $id): ?Pipeline`
- `findAll(array $filters = []): Collection`
- `create(array $data): Pipeline`
- `update(int $id, array $data): Pipeline`
- `delete(int $id): bool`
- `findWithEtapas(int $id): ?Pipeline` (eager load etapas ordered)

**PipelineEtapaRepositoryInterface** methods:
- `find(int $id): ?PipelineEtapa`
- `findByPipeline(int $pipelineId): Collection`
- `create(int $pipelineId, array $data): PipelineEtapa`
- `update(int $id, array $data): PipelineEtapa`
- `delete(int $id): bool`
- `reorder(int $pipelineId, array $orderedIds): void` — updates `orden` column based on array position

**Registration** in `AppServiceProvider::register()`:
```php
$this->app->bind(PipelineRepositoryInterface::class, EloquentPipelineRepository::class);
$this->app->bind(PipelineEtapaRepositoryInterface::class, EloquentPipelineEtapaRepository::class);
```

#### 2. Use Cases

Create the following UseCases under `App\Application\UseCases\Pipeline\`:

| UseCase | Method | Description |
|---------|--------|-------------|
| `IndexPipelineUseCase` | `execute(): Collection` | Returns all pipelines with enabled etapas (refactors current controller logic) |
| `ShowPipelineUseCase` | `execute(int $id): Pipeline` | Get single pipeline with all etapas |
| `StorePipelineUseCase` | `execute(array $data): Pipeline` | Create pipeline |
| `UpdatePipelineUseCase` | `execute(int $id, array $data): Pipeline` | Update pipeline |
| `DestroyPipelineUseCase` | `execute(int $id): bool` | Delete pipeline (cascades to etapas via FK) |
| `IndexPipelineEtapaUseCase` | `execute(int $pipelineId): Collection` | List etapas for a pipeline |
| `ShowPipelineEtapaUseCase` | `execute(int $id): PipelineEtapa` | Get single etapa |
| `StorePipelineEtapaUseCase` | `execute(int $pipelineId, array $data): PipelineEtapa` | Create etapa (auto-assigns next `orden`) |
| `UpdatePipelineEtapaUseCase` | `execute(int $id, array $data): PipelineEtapa` | Update etapa |
| `DestroyPipelineEtapaUseCase` | `execute(int $id): bool` | Delete etapa |
| `ReorderPipelineEtapaUseCase` | `execute(int $pipelineId, array $orderedIds): void` | Reorder etapas |

**Auto-ordering logic** for `StorePipelineEtapaUseCase`: when `orden` is not provided in the request, compute `max(orden) + 1` for the pipeline.

**Reorder algorithm**: Accept `{ ordered_ids: [3, 1, 2] }`. Iterate the array and set `orden = index + 1` for each ID. Validate all IDs belong to the specified pipeline. Wrap in a DB transaction.

#### 3. Controllers

Extend the existing `PipelineController` and create a new `PipelineEtapaController`:

**`PipelineController`** (moved/refactored) — `Modules/CRM/app/Http/Controllers/PipelineController.php` (moved from `app/Http/Controllers/API/`):
- Inject `IndexPipelineUseCase`, `ShowPipelineUseCase`, `StorePipelineUseCase`, `UpdatePipelineUseCase`, `DestroyPipelineUseCase` via constructor.
- `index()` — delegates to `IndexPipelineUseCase` instead of direct Eloquent query.
- `show(int $id)` — new.
- `store(StorePipelineRequest $request)` — new.
- `update(int $id, UpdatePipelineRequest $request)` — new.
- `destroy(int $id)` — new.

**`PipelineEtapaController`** (new) — `Modules/CRM/app/Http/Controllers/PipelineEtapaController.php`:
- Inject all 6 etapa UseCases via constructor.
- `index(int $pipelineId)`, `show(int $id)`, `store(StorePipelineEtapaRequest $request, int $pipelineId)`, `update(int $id, UpdatePipelineEtapaRequest $request)`, `destroy(int $id)`.
- `reorder(ReorderPipelineEtapaRequest $request, int $pipelineId)` — dedicated reorder action.

Both controllers use the `ApiResponse` trait for consistent `{ success, data, message }` envelope.

#### 4. Form Requests

| Class | Rules |
|-------|-------|
| `App\Http\Requests\StorePipelineRequest` | `nombre` (required, string, max:100), `codigo` (required, string, max:50, unique:pipelines), `habilitado` (boolean, optional) |
| `App\Http\Requests\UpdatePipelineRequest` | Same as store but `codigo` unique ignores current ID |
| `App\Http\Requests\StorePipelineEtapaRequest` | `nombre` (required, string, max:100), `orden` (integer, min:0, optional), `habilitado` (boolean, optional) |
| `App\Http\Requests\UpdatePipelineEtapaRequest` | Same as store |
| `App\Http\Requests\ReorderPipelineEtapaRequest` | `ordered_ids` (required, array, min:1), `ordered_ids.*` (integer, exists:pipeline_etapas,id) |

#### 5. API Resources

| Resource | Fields |
|----------|--------|
| `App\Http\Resources\PipelineResource` | `id`, `nombre`, `codigo`, `habilitado`, `etapas` (collection of PipelineEtapaResource, only when loaded), `created_at`, `updated_at` |
| `App\Http\Resources\PipelineEtapaResource` | `id`, `pipeline_id`, `nombre`, `orden`, `habilitado`, `created_at`, `updated_at` |

#### 6. Routes

Add to `Modules/CRM/routes/api.php` inside the existing `rbac` middleware group (requires `use Modules\CRM\Http\Controllers\PipelineController;`, `use Modules\CRM\Http\Controllers\PipelineEtapaController;`, and `use Modules\CRM\Http\Controllers\BulkMoveOportunidadesController;` at the top). The existing `GET /pipelines` route must be removed from `routes/api.php` (around line 214) — it moves to the module file.

```php
// Pipelines CRUD
Route::get('/pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
Route::post('/pipelines', [PipelineController::class, 'store'])->name('pipelines.store');
Route::get('/pipelines/{id}', [PipelineController::class, 'show'])->name('pipelines.show');
Route::put('/pipelines/{id}', [PipelineController::class, 'update'])->name('pipelines.update');
Route::delete('/pipelines/{id}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');

// Pipeline Etapas (nested)
Route::get('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'index'])->name('pipeline-etapas.index');
Route::post('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'store'])->name('pipeline-etapas.store');
Route::get('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'show'])->name('pipeline-etapas.show');
Route::put('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'update'])->name('pipeline-etapas.update');
Route::delete('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'destroy'])->name('pipeline-etapas.destroy');
Route::put('/pipelines/{pipeline}/etapas/reorder', [PipelineEtapaController::class, 'reorder'])->name('pipeline-etapas.reorder');

// Bulk move oportunidades
Route::post('/oportunidades/bulk-move-pipeline', [BulkMoveOportunidadesController::class, 'bulkMove'])->name('oportunidades.bulk-move');
```

Route naming follows the existing convention (`pipelines.index`, `pipeline-etapas.index`, etc.) matching the PermisoSeeder pattern.

#### 7. Factories

| Factory | File | Fields |
|---------|------|--------|
| `database/factories/PipelineFactory.php` | `Modules/CRM/DatabaseFactories/PipelineFactory.php` (or in `database/factories/`) | `nombre`, `codigo` (unique), `habilitado` (default true) |
| `database/factories/PipelineEtapaFactory.php` | Same location | `pipeline_id` (factory for Pipeline), `nombre`, `orden` (auto-increment), `habilitado` (default true) |

Add `HasFactory` trait to both `Pipeline` and `PipelineEtapa` models if not already present.

#### 8. PipelineEtapaChanged Event + Listener

**New Event**: `App\Events\PipelineEtapaChanged`:
```php
class PipelineEtapaChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Oportunidad $oportunidad,
        public ?PipelineEtapa $oldEtapa,
        public PipelineEtapa $newEtapa,
    ) {}
}
```

**Modify `OportunidadObserver::updated()`** — After the existing `cliente_desde` logic, dispatch:
```php
if ($oportunidad->isDirty('pipeline_etapa_id')) {
    $oldEtapa = PipelineEtapa::find($oportunidad->getOriginal('pipeline_etapa_id'));
    $newEtapa = PipelineEtapa::find($oportunidad->pipeline_etapa_id);
    
    PipelineEtapaChanged::dispatch($oportunidad, $oldEtapa, $newEtapa);
    
    // ... existing cliente_desde logic stays ...
}
```

**New Listener**: `App\Infrastructure\Webhook\Listeners\SendPipelineWebhookListener`:
```php
class SendPipelineWebhookListener
{
    public function handle(PipelineEtapaChanged $event): void
    {
        DispatchOutboundWebhookJob::dispatch('pipeline.etapa.changed', [
            'oportunidad_id' => $event->oportunidad->id,
            'oportunidad_codigo' => $event->oportunidad->codigo,
            'pipeline_id' => $event->oportunidad->pipeline_id,
            'old_etapa_id' => $event->oldEtapa?->id,
            'old_etapa_nombre' => $event->oldEtapa?->nombre,
            'new_etapa_id' => $event->newEtapa->id,
            'new_etapa_nombre' => $event->newEtapa->nombre,
            'entidad_id' => $event->oportunidad->entidad_id,
            'contacto_id' => $event->oportunidad->contacto_id,
        ]);
    }
}
```

**Config extension** in `config/webhook.php`:
```php
'pipeline' => [
    'url' => env('N8N_PIPELINE_WEBHOOK_URL', 'http://localhost:3000/webhook/pipeline'),
    'secret' => env('N8N_PIPELINE_WEBHOOK_SECRET', 'your-n8n-secret'),
],
```

**Extend `CrmWebhookSender`** to accept an optional config prefix:
- Option A: Add a `$configPrefix` parameter to `send()` (e.g., `config("webhook.{$prefix}")`).
- Option B: Create a subclass `PipelineWebhookSender` that overrides config keys.

Option A is preferred (minimal change, backward compatible): add a 3rd optional param `string $configPrefix = 'outbound'`. When `'pipeline'` is passed, it reads `config('webhook.pipeline.*')` instead of `config('webhook.outbound.*')`.

**Modify `DispatchOutboundWebhookJob`** to accept an optional config prefix and pass it to `CrmWebhookSender`:
```php
public function __construct(
    public string $event,
    public array $data,
    public string $configPrefix = 'outbound',
) { ... }
```

**Register in `EventServiceProvider`**:
```php
PipelineEtapaChanged::class => [
    SendPipelineWebhookListener::class,
],
```

#### 9. Permissions

Update `PermisoSeeder`:
```php
'pipelines' => ['index', 'store', 'show', 'update', 'destroy'],
'pipeline-etapas' => ['index', 'store', 'show', 'update', 'destroy'],
```

#### 10. Tests

| Test Class | File | Scope |
|------------|------|-------|
| `Tests\Feature\API\PipelineControllerTest` | `tests/Feature/API/PipelineControllerTest.php` | Full CRUD: index, store, show, update, destroy. Auth + RBAC. Validation errors. |
| `Tests\Feature\API\PipelineEtapaControllerTest` | `tests/Feature/API/PipelineEtapaControllerTest.php` | Full CRUD + reorder endpoint. Validation (belongs to pipeline). |
| `Tests\Feature\Events\PipelineEtapaChangedEventTest` | `tests/Feature/Events/PipelineEtapaChangedEventTest.php` | Observer dispatches event on etapa change. Listener fires webhook job. Payload correctness. |
| `Tests\Unit\Domain\PipelineTest` | `tests/Unit/Domain/PipelineTest.php` | Factory creation, relationships, scopes (if added). |
| `Tests\Unit\Domain\PipelineEtapaTest` | `tests/Unit/Domain/PipelineEtapaTest.php` | Factory creation, relationship to pipeline, orden defaults. |

Test pattern (following `OportunidadControllerTest.php`):
- `RefreshDatabase` trait.
- `#[Test]` attribute (PHPUnit 11).
- `setUp()`: seed `PipelineSeeder`, create auth token via `authenticate()` helper.
- Pipeline-specific seeder data for CRUD tests.
- Assert JSON structure, status codes, and response envelope.

#### 11. Data Migration: Assign Pipeline/Etapa to Existing Oportunidades

A one-time data migration (`database/migrations/2026_06_07_000000_assign_pipeline_etapa_to_existing_oportunidades.php`) will backfill `pipeline_id` and `pipeline_etapa_id` for all existing `oportunidad` records where `pipeline_etapa_id IS NULL`:

- Match each record's `estado` string (e.g., `'Borrador'`, `'Enviado'`, `'Aprobado'`) against `etapa.nombre` in the **Pipeline Cotización** (`codigo = 'COTIZACION'`), case-insensitive, trimmed.
- If a matching etapa is found, set `pipeline_id` and `pipeline_etapa_id` accordingly.
- If no match, default to the first etapa (`orden = 1`) of Pipeline Cotización.
- This migration is safe to run only once — it checks `WHERE pipeline_etapa_id IS NULL` before updating.
- After this migration, the `estado` field is removed from `Oportunidad`'s `$fillable` (or kept nullable for legacy reports only). All new writes use `pipeline_etapa_id`.

#### 12. Pipeline Seeder: Default Pipelines

Update `database/seeders/PipelineSeeder.php` to ensure the following two pipelines exist, using `updateOrInsert` / `firstOrCreate` patterns for idempotency:

**Pipeline Cotización** (`codigo: 'COTIZACION'`):
1. Borrador (orden 1)
2. Enviado (orden 2)
3. En Negociación (orden 3)
4. Aprobado (orden 4)
5. Rechazado (orden 5)

**Pipeline Recuperación** (`codigo: 'RECUPERACION'`):
1. Inicio (orden 1)
2. Con Cita (orden 2)
3. En Negociación (orden 3)
4. Aprobado (orden 4)
5. Rechazado (orden 5)

The seeder must be idempotent — running `php artisan db:seed --class=PipelineSeeder` twice does not create duplicates.

#### 13. Bulk Move to Rescue Pipeline

A high-volume marketing campaign needs to move cold leads (3+ months inactive) to the **Pipeline de Recuperación** in a single action from the oportunidades table view.

**Backend**:

- **New Controller**: `Modules/CRM/app/Http/Controllers/BulkMoveOportunidadesController.php` (in module)
- **New UseCase**: `App\Application\UseCases\Oportunidad\BulkMoveOportunidadesToPipelineUseCase`
- **New Request**: `App\Http\Requests\BulkMoveOportunidadesRequest`
- **New Endpoint**: `POST /api/v1/oportunidades/bulk-move-pipeline`

Request body:
```json
{
  "oportunidad_ids": [1, 2, 3],
  "target_pipeline_id": 5,
  "target_etapa_id": null
}
```

Validations:
- All `oportunidad_ids` must exist and belong to the current user's organization.
- `target_pipeline_id` must exist and have `habilitado = true`.
- If `target_etapa_id` is provided, it must belong to `target_pipeline_id`. If omitted, the oportunidad moves to the pipeline's first etapa (`orden = 1`).

Response:
```json
{
  "success": true,
  "data": {
    "moved_count": 3,
    "oportunidad_ids": [1, 2, 3]
  }
}
```

**Transaction handling**: The entire operation is wrapped in a DB transaction. If any oportunidad fails to update (FK violation, etc.), the entire batch is rolled back.

**Event dispatch**: Each moved oportunidad fires its own `PipelineEtapaChanged` event individually. This gives n8n one webhook per opportunity, enabling granular Mailrelay sequence tracking and easier retry semantics. (Alternative considered: a single bulk event, but individual events were chosen for traceability.)

**Permission**: A new permission `oportunidades.bulk-move-pipeline` is added to `PermisoSeeder`. Alternatively, reuse `oportunidades.update` if granularity is not required.

**Test class**: `Tests\Feature\API\BulkMoveOportunidadesTest`

**Frontend**:

- **`src/pages/CRMPage.tsx`**: Add a checkbox column to the table view. When ≥1 row is selected, a floating "Acciones en lote" action bar appears.
- **Action button**: "Mover a Pipeline de Rescate" — only visible to users with `oportunidades.bulk-move-pipeline` permission.
- **Confirmation modal**: "Se moverán N oportunidades al Pipeline de Recuperación. ¿Continuar?"
- **On confirm**: Call the bulk endpoint, show success toast "N oportunidades movidas", invalidate `['oportunidades']` and `['pipelines']` queries.
- **`src/api/crmApi.ts`**: Add `bulkMoveOportunidades(oportunidad_ids, target_pipeline_id, target_etapa_id?)` function.

### Frontend

#### 1. TypeScript Interfaces (`src/api/types.ts`)

```typescript
export interface PipelineEtapa {
  id: number
  pipeline_id: number
  nombre: string
  orden: number
  habilitado: boolean
  created_at: string
  updated_at: string
}

export interface Pipeline {
  id: number
  nombre: string
  codigo: string
  habilitado: boolean
  etapas: PipelineEtapa[]
  created_at: string
  updated_at: string
}

// Update Oportunidad interface:
// - Remove `estado: OportunidadEstado` → replace with `pipeline_id?: number`, `pipeline_etapa_id?: number`, `pipeline_nombre?: string`, `etapa_nombre?: string`
// - Keep OportunidadEstado for backward compatibility but mark as deprecated
```

#### 2. API Functions (`src/api/crmApi.ts`)

```typescript
// Pipelines CRUD
export async function getPipelines(): Promise<ApiResponse<Pipeline[]>>
export async function getPipeline(id: number): Promise<ApiResponse<Pipeline>>
export async function createPipeline(data: CreatePipelineInput): Promise<ApiResponse<Pipeline>>
export async function updatePipeline(id: number, data: UpdatePipelineInput): Promise<ApiResponse<Pipeline>>
export async function deletePipeline(id: number): Promise<ApiResponse<null>>

// Pipeline Etapas CRUD
export async function getEtapas(pipelineId: number): Promise<ApiResponse<PipelineEtapa[]>>
export async function createEtapa(pipelineId: number, data: CreateEtapaInput): Promise<ApiResponse<PipelineEtapa>>
export async function updateEtapa(id: number, data: UpdateEtapaInput): Promise<ApiResponse<PipelineEtapa>>
export async function deleteEtapa(id: number): Promise<ApiResponse<null>>
export async function reorderEtapas(pipelineId: number, orderedIds: number[]): Promise<ApiResponse<null>>
```

#### 3. PipelineAdmin Page (`src/pages/PipelineAdmin.tsx`)

New page accessible at `/crm/pipelines` from the sidebar navigation (under the CRM section):

- **Pipeline list**: Table or cards showing all pipelines with columns: nombre, codigo, habilitado, # etapas, created_at.
- **Create/Edit pipeline**: Modal form with fields: nombre, codigo, habilitado (toggle).
- **Delete pipeline**: Confirmation dialog with warning: "Esto eliminará el pipeline y todas sus etapas. Las oportunidades asociadas quedarán sin etapa asignada."
- **Etapa management per pipeline**: For each pipeline row, an expandable section that lists its etapas in `orden` order.
- **Create/Edit etapa**: Inline form (within the expanded section) with fields: nombre, habilitado (toggle). `orden` is auto-assigned as `max(orden) + 1` on create; for reordering, use simple "↑ Mover arriba" / "↓ Mover abajo" buttons (no drag & drop on this page).
- **Delete etapa**: Confirmation dialog.
- **State management**: React state + TanStack Query `useQuery`/`useMutation`. Cache invalidation on mutations.

**Note**: This admin page does NOT use drag & drop. Drag & drop is reserved for the Kanban in `CRMPage.tsx`. Reordering here uses simple "move up/down" buttons that call the reorder endpoint with the new ordered array.

#### 4. Update CRMPage Kanban

The CRM Kanban (`CRMPage.tsx`) already has drag & drop implemented via `@dnd-kit`. Currently, when a card is dragged to a different column, it sends `{ estado: 'Borrador' }` (a hardcoded string). The change is:

- Replace `OportunidadEstado` hardcoded union with dynamic etapas from `pipeline.etapas`.
- Fix `updateOportunidadEstado(id, estado)` to send `{ pipeline_etapa_id: 3 }` instead of `{ estado: 'Borrador' }` when dragging cards between columns.
- The Kanban columns already generate from `pipeline.etapas` — they should continue working once the API returns proper data.

## Affected Areas

### Backend (Laravel)

| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/API/PipelineController.php` → `Modules/CRM/app/Http/Controllers/PipelineController.php` | **Moved + Modified** | Move to module (namespace change from `App\Http\Controllers\API` to `Modules\CRM\Http\Controllers`), add store, show, update, destroy; refactor index to use UseCase |
| `Modules/CRM/app/Http/Controllers/PipelineEtapaController.php` | **New** | Full CRUD + reorder (in module) |
| `app/Application/UseCases/Pipeline/` | **New directory** | 11 UseCase classes |
| `app/Domain/Repositories/PipelineRepositoryInterface.php` | **New** | Repository contract |
| `app/Domain/Repositories/PipelineEtapaRepositoryInterface.php` | **New** | Repository contract |
| `app/Infrastructure/Persistence/EloquentPipelineRepository.php` | **New** | Eloquent implementation |
| `app/Infrastructure/Persistence/EloquentPipelineEtapaRepository.php` | **New** | Eloquent implementation |
| `app/Http/Requests/StorePipelineRequest.php` | **New** | Validation |
| `app/Http/Requests/UpdatePipelineRequest.php` | **New** | Validation |
| `app/Http/Requests/StorePipelineEtapaRequest.php` | **New** | Validation |
| `app/Http/Requests/UpdatePipelineEtapaRequest.php` | **New** | Validation |
| `app/Http/Requests/ReorderPipelineEtapaRequest.php` | **New** | Validation |
| `app/Http/Resources/PipelineResource.php` | **New** | API Resource |
| `app/Http/Resources/PipelineEtapaResource.php` | **New** | API Resource |
| `app/Events/PipelineEtapaChanged.php` | **New** | Event class |
| `app/Infrastructure/Webhook/Listeners/SendPipelineWebhookListener.php` | **New** | Listener |
| `app/Infrastructure/Webhook/CrmWebhookSender.php` | **Modified** | Add config prefix support |
| `app/Infrastructure/Webhook/DispatchOutboundWebhookJob.php` | **Modified** | Accept optional configPrefix param |
| `app/Providers/EventServiceProvider.php` | **Modified** | Register event→listener mapping |
| `app/Providers/AppServiceProvider.php` | **Modified** | Bind new repository interfaces |
| `app/Observers/OportunidadObserver.php` | **Modified** | Dispatch PipelineEtapaChanged event |
| `Modules/CRM/routes/api.php` | **Modified** | Add 12 new routes (5 pipeline CRUD, 6 pipeline-etapas CRUD+reorder, 1 bulk-move) inside existing `rbac` middleware group |
| `routes/api.php` | **Modified** | Remove existing `GET /pipelines` route (moved to module) |
| `config/webhook.php` | **Modified** | Add `pipeline` section |
| `.env.example` | **Modified** | Add `N8N_PIPELINE_WEBHOOK_URL`, `N8N_PIPELINE_WEBHOOK_SECRET` |
| `database/seeders/PermisoSeeder.php` | **Modified** | Add full CRUD perms for pipelines + etapas |
| `database/factories/PipelineFactory.php` | **New** | Factory for tests |
| `database/factories/PipelineEtapaFactory.php` | **New** | Factory for tests |
| `Modules/CRM/Models/Pipeline.php` | **Modified** | Add `HasFactory` trait |
| `Modules/CRM/Models/PipelineEtapa.php` | **Modified** | Add `HasFactory` trait |
| `tests/Feature/API/PipelineControllerTest.php` | **New** | Full test suite |
| `tests/Feature/API/PipelineEtapaControllerTest.php` | **New** | Full test suite |
| `tests/Feature/Events/PipelineEtapaChangedEventTest.php` | **New** | Event dispatch + listener test |
| `tests/Unit/Domain/PipelineTest.php` | **New** | Unit tests |
| `tests/Unit/Domain/PipelineEtapaTest.php` | **New** | Unit tests |
| `app/Application/UseCases/Oportunidad/BulkMoveOportunidadesToPipelineUseCase.php` | **New** | Bulk move oportunidades to target pipeline |
| `app/Http/Requests/BulkMoveOportunidadesRequest.php` | **New** | Validate bulk move request |
| `database/migrations/2026_06_07_000000_assign_pipeline_etapa_to_existing_oportunidades.php` | **New** | One-time data migration for legacy estado records |
| `database/seeders/PipelineSeeder.php` | **Modified** | Add default pipelines (Cotización, Recuperación) with idempotent upsert |
| `tests/Feature/API/BulkMoveOportunidadesTest.php` | **New** | Bulk move feature test |

### Frontend (dashboard-crm)

| Area | Impact | Description |
|------|--------|-------------|
| `src/api/types.ts` | **Modified** | Add Pipeline, PipelineEtapa interfaces; update Oportunidad; deprecate OportunidadEstado |
| `src/api/crmApi.ts` | **Modified** | Add pipeline CRUD, etapa CRUD, reorder API functions |
| `src/pages/PipelineAdmin.tsx` | **New** | Admin page with table/cards for pipeline CRUD, expandable etapa sections, modal forms, up/down reorder buttons (no drag & drop) |
| `src/pages/CRMPage.tsx` | **Modified** | Already has drag & drop via @dnd-kit; update drag payload to send `pipeline_etapa_id` instead of `estado` string; use dynamic etapas for Kanban columns |
| `src/App.tsx` or router config | **Modified** | Refactor: move `/crm` to `/crm/oportunidad`, add `/crm/pipelines`, ensure all 5 CRM routes are registered under a shared layout |
| `src/pages/CRMPage.tsx` | **Modified** | Add checkbox column, floating "Acciones en lote" bar, bulk move to Pipeline de Rescate |
| `src/api/crmApi.ts` | **Modified** | Add `bulkMoveOportunidades` function |

## Key Design Decisions

1. **Webhook payload goes to n8n, not Mailrelay directly** — The Laravel back-end only knows about n8n. n8n is responsible for calling the Mailrelay API. This keeps the CRM decoupled from the email marketing provider and makes the autoresponder logic configurable without CRM deploys. The `CrmWebhookSender` already supports HMAC-SHA256 signing and is queued — ideal for this purpose.

2. **Extend existing `CrmWebhookSender` with config prefix rather than a new sender class** — Adding an optional `$configPrefix` parameter to `CrmWebhookSender::send()` and `DispatchOutboundWebhookJob` avoids code duplication. The pipeline webhook goes to a different URL (`N8N_PIPELINE_WEBHOOK_URL`) but uses the same signing and retry machinery. If the payload format diverges significantly later, we can create a dedicated sender at that point.

3. **Dispatch `PipelineEtapaChanged` from the Observer, not the Controller** — The observer already detects `isDirty('pipeline_etapa_id')` and is registered globally. Dispatching from the observer ensures the event fires regardless of how the Oportunidad is saved (from the API, from a console command, from a webhook, or from a future import). This is more reliable than adding dispatch calls to every controller action.

4. **Etapa reordering as a dedicated `PUT /pipelines/{pipeline}/etapas/reorder` endpoint** — Rather than making reordering implicit in `PUT /etapas/{id}` (which would require N requests to reorder N stages), the dedicated endpoint accepts an ordered array of IDs and updates all rows in a single DB transaction. This pattern is used both by the up/down buttons in PipelineAdmin and by the @dnd-kit Kanban in CRMPage. The route name `pipeline-etapas.reorder` maps to a dedicated permission if fine-grained control is needed later.

5. **Separate permission for pipeline CRUD vs etapa CRUD** — `pipelines.*` and `pipeline-etapas.*` are separate permission prefixes. This allows granting a user the ability to reorder stages without creating/deleting pipelines, or granting pipeline management without exposing other admin features. SuperAdmin/Admin still get wildcard `*`.

6. **Models stay in `Modules/CRM/Models`, everything else in `app/`** — The existing convention places models in the nwidart module (`Modules/CRM/Models/`) but all controllers, UseCases, repositories, events, listeners, requests, and resources in `app/`. This keeps the Clean Architecture layers unified while respecting the module structure for models. The `HasFactory` trait will reference a factory in `database/factories/` (or module-specific factory directory depending on nwidart config).

7. **Frontend admin page is a React component, not a separate route module** — Adding a new `PipelineAdmin.tsx` component with its own route at `/crm/pipelines` follows the existing pattern where pages are top-level components in `src/pages/`. No need for a separate module or lazy loading — the app is not large enough to justify it.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Breaking existing Kanban drag** if `updateOportunidadEstado` payload changes from `{ estado }` to `{ pipeline_etapa_id }` without backward compat | **High** | High — Kanban users can't move cards | Phase the change: first add `pipeline_etapa_id` support alongside `estado`, then deprecate `estado` after frontend is deployed. Test both paths. |
| **n8n webhook URL misconfiguration** causes silent failures (existing `CrmWebhookSender` logs but does not throw) | **Medium** | Medium — marketing autoresponder stops working | Add a health-check or test button in admin UI (deferred). For now, ensure clear logging with distinct event tags. |
| **Observer fires duplicate PipelineEtapaChanged events** if both the model `saving()` hook and the observer `updated()` fire for the same change | **Low** | Low — duplicate webhook calls to n8n | The observer's `updated()` is the canonical fire point. The `saving()` hook in the model only resolves pipeline/etapa IDs and does NOT dispatch events. No overlap expected. |
| **Drag-and-drop reordering reorders etapas across different pipelines** if the `ordered_ids` array contains IDs from multiple pipelines | **Low** | Medium — data corruption in etapa ordering | Validate all IDs belong to the specified pipeline inside `ReorderPipelineEtapaUseCase` before updating. Return 422 if any ID is invalid. |
| **Factory namespace confusion** with nwidart/laravel-modules — factories in `database/factories/` vs `Modules/CRM/DatabaseFactories/` | **Medium** | Low — tests won't find factories | Check existing module factories for the correct namespace. If nwidart uses `Modules\CRM\DatabaseFactories\`, place them there. Add `HasFactory` with the proper namespace. |
| **Bulk move fails midway** if some oportunidad IDs are invalid or belong to wrong org | **Medium** | Medium — partial move, inconsistent state | Wrap in DB transaction. Validate all IDs upfront before any update. Return 422 with invalid IDs if any fail. |

## Rollback Plan

- **Database**: No new migrations needed (tables already exist). Rollback is code-only.
- **Backend**: Delete all new files (UseCases, Repositories, Controllers, Requests, Resources, Factories, Events, Listeners, Tests). Revert modified files via `git checkout`: `PipelineController.php`, `CrmWebhookSender.php`, `DispatchOutboundWebhookJob.php`, `OportunidadObserver.php`, `EventServiceProvider.php`, `AppServiceProvider.php`, `routes/api.php`, `Modules/CRM/routes/api.php`, `config/webhook.php`, `.env.example`, `PermisoSeeder.php`. Revert model files (`HasFactory` addition).
- **Frontend**: Revert modified files via `git checkout`: `types.ts`, `crmApi.ts`, `CRMPage.tsx`, `App.tsx`/router config. Delete `PipelineAdmin.tsx`.
- **Cleanse config**: Remove `N8N_PIPELINE_WEBHOOK_URL` and `N8N_PIPELINE_WEBHOOK_SECRET` from `.env` if they were added.

## Dependencies

- Existing `pipelines` and `pipeline_etapas` tables (already migrated).
- Existing `Pipeline` and `PipelineEtapa` models in `Modules/CRM/Models/`.
- Existing `OportunidadObserver` with `isDirty('pipeline_etapa_id')` detection.
- Existing `CrmWebhookSender` + `DispatchOutboundWebhookJob` for queued webhook dispatch.
- Existing `config/webhook.php` structure.
- Existing `PermisoSeeder` pattern.
- Existing `EventServiceProvider` pattern.
- Frontend: `@dnd-kit` already in `package.json` (used by the existing CRM Kanban in `CRMPage.tsx`, NOT by PipelineAdmin).
- Frontend: TanStack Query already configured for API data fetching.
- Queue driver must be running (defaults to `database` in dev, `sync` in tests).

## Success Criteria

- [ ] **Pipeline CRUD**: All 5 CRUD endpoints (index, store, show, update, destroy) work correctly, return proper JSON envelope, validate input, and enforce RBAC permissions.
- [ ] **PipelineEtapa CRUD**: All 5 CRUD endpoints + reorder endpoint work correctly. Reorder updates `orden` column atomically. Validation ensures etapas belong to the correct pipeline.
- [ ] **PipelineEtapaChanged event**: Dispatched when `Oportunidad::pipeline_etapa_id` changes via any save path (API, seeder, console). Payload includes old and new etapa names.
- [ ] **n8n webhook**: When `PipelineEtapaChanged` fires, `DispatchOutboundWebhookJob` is queued with event type `pipeline.etapa.changed` and complete payload. HTTP call goes to `N8N_PIPELINE_WEBHOOK_URL` with HMAC-SHA256 signature.
- [ ] **Permissions**: `PermisoSeeder` grants `pipelines.index/store/show/update/destroy` and `pipeline-etapas.index/store/show/update/destroy` to non-admin roles. Admin/SuperAdmin wildcard `*` still covers them.
- [ ] **Tests pass**: All new and existing tests pass (`composer test`). Minimum 90% coverage on new code paths.
- [ ] **Frontend PipelineAdmin page**: Displays all pipelines, allows create/edit/delete, shows etapas per pipeline in expandable sections, allows up/down reorder, updates persist to API.
- [ ] **Frontend Kanban**: Drag-and-drop in Kanban updates `pipeline_etapa_id` (not `estado` string). Kanban columns generate dynamically from pipeline etapas.
- [ ] **Backward compatibility**: Existing `GET /pipelines` still returns the same shape. Existing clients (FastAPI) that consume this endpoint do not break.
- [ ] **No regressions**: `OportunidadControllerTest`, `OportunidadClienteDesdeTest`, and all existing tests pass without modification.
- [ ] **Bulk move endpoint**: `POST /api/v1/oportunidades/bulk-move-pipeline` moves N opportunities in a single transaction, fires individual `PipelineEtapaChanged` events, returns moved count.
- [ ] **Bulk edit UI**: Table view shows checkboxes, floating action bar appears on selection, "Mover a Pipeline de Rescate" action works and shows success feedback.
- [ ] **Data migration**: All existing opportunities without `pipeline_etapa_id` get assigned based on `estado` match or default to first etapa of Cotización.
- [ ] **Seeder idempotency**: Running `php artisan db:seed --class=PipelineSeeder` twice does not create duplicate pipelines/etapas.

## Resolved Decisions

1. **Frontend routes — CRM Module Route Structure** (all nested under `/crm/`):

   The CRM module uses a nested route structure. All routes share the CRM layout (sidebar with links to each section). The complete structure is:

   | Route | Page | Status | Purpose |
   |-------|------|--------|---------|
   | `/crm/dashboard` | DashboardPage | Existing (unchanged) | KPIs, metrics, charts for CRM |
   | `/crm/pipelines` | PipelineAdmin | **NEW (this change)** | CRUD pipelines + etapas with up/down reorder |
   | `/crm/oportunidad` | CRMPage (renamed/moved) | **MODIFIED (this change)** | Kanban with drag & drop + table with bulk edit. Currently lives at `/crm` and should be moved to `/crm/oportunidad`. |
   | `/crm/contactos` | DirectorioPage or ContactosPage | Existing (unchanged) | List of contacts |
   | `/crm/entidad` | EntidadPage | Existing (unchanged) | List of companies/prospects |

   **Routing change required as part of this proposal**:
   - The current `/crm` route (showing the Kanban/table) must be moved to `/crm/oportunidad`.
   - A new `/crm` index route should redirect to `/crm/dashboard` (or `/crm/oportunidad` — to be confirmed in specs).
   - The sidebar navigation should be updated to link to all 5 routes under the CRM section.
2. **Test data strategy**: CRUD tests build on the existing `PipelineSeeder` (which uses `updateOrInsert` / `firstOrCreate` and is idempotent). Tests seed once in `setUp()` and modify the seeded data as needed.
3. **Etapa reorder payload**: Frontend sends the full ordered array of all etapa IDs. Backend overwrites `orden` in a single transaction.
4. **`estado` legacy field**: Hard cutover. The `estado` field is removed from `Oportunidad` fillable; a one-time data migration assigns `pipeline_id` and `pipeline_etapa_id` to existing records based on `estado` match.
5. **Bulk move webhook events**: Each oportunidad in a bulk move fires its own `PipelineEtapaChanged` event (n8n gets one webhook per opportunity, enabling granular Mailrelay sequence tracking).
6. **Pipeline Seeder data**: Two default pipelines — `Cotización` (Borrador, Enviado, En Negociación, Aprobado, Rechazado) and `Recuperación` (Inicio, Con Cita, En Negociación, Aprobado, Rechazado).
7. **Controller placement — Move PipelineController to module**: `PipelineController` is moved from `app/Http/Controllers/API/` to `Modules/CRM/app/Http/Controllers/` with a namespace change. All Pipeline routes are registered in `Modules/CRM/routes/api.php` (not `routes/api.php`). The existing `GET /pipelines` route is removed from `routes/api.php`. Rationale: models already live in the module, modular monolith requires cohesion by module. Impact: small refactor (file move + namespace change + route file update). No breaking changes — same URL, same response shape.

## Backend Route Architecture

This change enforces the modular monolith pattern: when a model lives in `Modules/CRM/app/Models/`, its controller and routes MUST live in the module too. The current codebase has a legacy split (some controllers in `app/Http/Controllers/API/`, some in `Modules/CRM/app/Http/Controllers/`) which this change will NOT fully resolve — it focuses on the Pipeline domain only.

### Affected Files for this Change

**Move (refactor):**
- `app/Http/Controllers/API/PipelineController.php` → `Modules/CRM/app/Http/Controllers/PipelineController.php` (change namespace from `App\Http\Controllers\API` to `Modules\CRM\Http\Controllers`)
- The existing `GET /api/v1/pipelines` route in `routes/api.php` (line 214) will be MOVED to `Modules/CRM/routes/api.php`

**Create:**
- `Modules/CRM/app/Http/Controllers/PipelineEtapaController.php` (new)
- `Modules/CRM/app/Http/Controllers/BulkMoveOportunidadesController.php` (new, for the special action)

**Update:**
- `Modules/CRM/routes/api.php` — add Pipeline CRUD (resource), PipelineEtapa CRUD (resource nested under pipeline), reorder action, and bulk-move action
- `routes/api.php` — REMOVE the existing `GET /pipelines` route (it's moved to the module)

### Future Refactor (NOT in this change)
- Oportunidad, Entidad, Cotizacion, Seguimiento controllers/routes should also be moved to `Modules/CRM/` in future changes
- This is a non-breaking, gradual refactor

## Frontend Sub-Route Structure

The frontend lives in a **separate React project** at `D:\sitios desarrollo\dashboard-crm\` and uses **React Router v6** (`react-router-dom` ^6.22.0) with `BrowserRouter` + nested `<Routes>`. Currently, the CRM routes are **flat** (e.g., `/crm` → CRMPage, `/contactos` → ContactosPage, `/directorio` → EntidadPage). This proposal nests all CRM pages under `/crm/*` with a shared layout.

The complete sub-route tree for each CRM page is documented below. Sub-routes marked **NEW** are part of THIS change; **MODIFIED** are existing routes that change behavior; **EXISTING** are documented for completeness only.

### `/crm/dashboard` (Existing — no changes in this proposal)

| Sub-route | Component / Action | Status |
|-----------|-------------------|--------|
| `/crm/dashboard` | index — KPIs, charts | Existing |

### `/crm/pipelines` (NEW — this change)

| Sub-route | Component / Action | Status |
|-----------|-------------------|--------|
| `/crm/pipelines` | index — list of pipelines | **NEW** |
| `/crm/pipelines/new` | create pipeline form | **NEW** |
| `/crm/pipelines/:id` | show/detail view (read-only summary) | **NEW** |
| `/crm/pipelines/:id/edit` | edit pipeline form | **NEW** |
| `/crm/pipelines/:id/delete` | special action: delete (confirmation dialog) | **NEW** |
| `/crm/pipelines/:id/etapas` | nested index: list etapas of this pipeline | **NEW** |
| `/crm/pipelines/:id/etapas/new` | create etapa form | **NEW** |
| `/crm/pipelines/:id/etapas/:etapaId` | show etapa | **NEW** |
| `/crm/pipelines/:id/etapas/:etapaId/edit` | edit etapa form | **NEW** |
| `/crm/pipelines/:id/etapas/:etapaId/delete` | special action: delete etapa | **NEW** |
| `/crm/pipelines/:id/etapas/reorder` | special action: reorder etapas (up/down buttons, calls PUT `/pipelines/{id}/etapas/reorder`) | **NEW** |

### `/crm/oportunidad` (MODIFIED — this change)

| Sub-route | Component / Action | Status |
|-----------|-------------------|--------|
| `/crm/oportunidad` | index — Kanban view of selected pipeline (default) + table toggle | **MODIFIED** (moved from `/crm`) |
| `/crm/oportunidad/new` | create oportunidad form | **MODIFIED** (was at `/crm/new` or modal) |
| `/crm/oportunidad/:id` | show/detail (slide panel or page) | **MODIFIED** |
| `/crm/oportunidad/:id/edit` | edit form | **MODIFIED** |
| `/crm/oportunidad/:id/delete` | special action: delete | **MODIFIED** |
| `/crm/oportunidad/:id/etapa` | special action: change etapa (PATCH/PUT, Kanban drag & drop) | **MODIFIED** (was `estado` string, now `pipeline_etapa_id`) |
| `/crm/oportunidad/bulk-move-pipeline` | special action: bulk move to rescue pipeline (POST, table checkboxes) | **NEW** |
| `/crm/oportunidad/:id/cotizar` | special action: create cotización (future, not this change) | Existing (deferred) |
| `/crm/oportunidad/:id/clonar` | special action: clone (backend exists) | Existing |
| `/crm/oportunidad/:id/ganar` | special action: mark as won (backend exists) | Existing |
| `/crm/oportunidad/:id/version` | special action: version (backend exists) | Existing |

### `/crm/contactos` (Existing — not in this change, documented for completeness)

| Sub-route | Component / Action | Status |
|-----------|-------------------|--------|
| `/crm/contactos` | index — list of contacts | Existing |
| `/crm/contactos/new` | create contact form | Existing |
| `/crm/contactos/:id` | show contact detail | Existing |
| `/crm/contactos/:id/edit` | edit contact | Existing |
| `/crm/contactos/:id/delete` | delete contact | Existing |
| `/crm/contactos/:id/acciones` | special action: log action (Llamada/Correo/Reunion/Nota) | Existing |
| `/crm/contactos/:id/seguimientos` | list seguimientos | Existing |

### `/crm/entidad` (Existing — not in this change, documented for completeness)

| Sub-route | Component / Action | Status |
|-----------|-------------------|--------|
| `/crm/entidad` | index — list of companies/prospects | Existing |
| `/crm/entidad/new` | create entity form | Existing |
| `/crm/entidad/:id` | show detail (slide panel) | Existing |
| `/crm/entidad/:id/edit` | edit entity | Existing |
| `/crm/entidad/:id/delete` | delete entity | Existing |
| `/crm/entidad/:id/contactos` | special action: assign/manage contactos | Existing |
| `/crm/entidad/:id/oportunidades` | nested oportunidades of this entity | Existing |

### Frontend Routing Library

The frontend (`dashboard-crm`) uses **React Router v6** with the following current pattern in `src/App.tsx`:

```tsx
<BrowserRouter>
  <Routes>
    {/* Public routes */}
    <Route path="/login" element={<LoginPage />} />
    {/* Protected routes */}
    <Route path="/*" element={<ProtectedLayout />} />
  </Routes>
</BrowserRouter>
```

Inside `ProtectedLayout`, routes are currently **flat**:
```tsx
<Routes>
  <Route path="/dashboard" element={<DashboardPage />} />
  <Route path="/crm" element={<CRMPage />} />
  <Route path="/contactos" element={<ContactosPage />} />
  <Route path="/directorio" element={<DirectorioPage />} />
  {/* ... other routes */}
</Routes>
```

**This proposal requires migrating to a nested route structure under `/crm/`:**
```tsx
<Routes>
  <Route path="/crm" element={<CRMLayout />}>
    <Route index element={<Navigate to="/crm/dashboard" replace />} />
    <Route path="dashboard" element={<DashboardPage />} />
    <Route path="pipelines" element={<PipelineAdmin />} />
    <Route path="oportunidad" element={<CRMPage />} />
    <Route path="contactos" element={<ContactosPage />} />
    <Route path="entidad" element={<EntidadPage />} />
  </Route>
  {/* Non-CRM routes remain flat */}
</Routes>
```

The `CRMLayout` component would render the CRM sidebar (with links to all 5 sections) and an `<Outlet />` for the nested route content. The sidebar is already implemented in `Sidebar.tsx` but currently links to flat paths — those links must be updated to `/crm/*` paths.

**Note on implementation**: The existing `DirectorioPage` component (currently serving as the entidad page) and `ContactosPage` will be re-used under the new `/crm/entidad` and `/crm/contactos` paths respectively. No new components needed for existing pages — only `PipelineAdmin` is a new component. The `CRMPage` moves from `/crm` to `/crm/oportunidad` without code changes to the page itself.
