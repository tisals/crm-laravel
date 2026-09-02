# Tasks: Pipelines CRUD + Webhook

> Phase 2 SAIlus — Implementación completa de Pipeline CRUD, PipelineEtapa nested CRUD + reorder,
> PipelineEtapaChanged event, webhook listener, bulk move, data migration, permisos, y frontend.

## Ground Rules

- **TDD estricto**: cada feature requiere test PRIMERO (RED → GREEN → REFACTOR)
- **Comando de tests**: `docker exec crm-laravel-dev php artisan test` (o `composer test`)
- **Frontend build**: `cd D:\sitios desarrollo\dashboard-crm && npm run build`
- **Commit final**: `feat(crm): add Pipeline/PipelineEtapa CRUD + bulk move + n8n webhook`
- **NO crear tablas nuevas** — `pipelines` y `pipeline_etapas` ya existen
- **Controllers van en módulo**: `Modules/CRM/app/Http/Controllers/`
- **UseCases/Repositories/Domain Entities**: `app/Application/UseCases/`, `app/Domain/`, `app/Infrastructure/`
- **Cada fase termina con**: `php artisan test --filter=<FilterName>` para confirmar 0 regresiones

---

## Phase 0: Setup (10 min)

- [x] TASK-0.1: Verify Docker container is running: `docker ps | findstr crm-laravel-dev`
- [x] TASK-0.2: Run baseline tests: `docker exec crm-laravel-dev php artisan test` — confirm 0 failures
- [x] TASK-0.3: Create branch: `git checkout -b feature/pipelines-crud-webhook`

---

## Phase 1: Module Route Refactor (20 min)

> Move PipelineController from `app/Http/Controllers/API/` to `Modules/CRM/app/Http/Controllers/`
> and register its routes in the module route file.

- [x] TASK-1.1: Move `PipelineController` from `app/Http/Controllers/API/PipelineController.php` to `Modules/CRM/app/Http/Controllers/PipelineController.php`
  - Update namespace from `App\Http\Controllers\API` to `Modules\CRM\Http\Controllers`
  - Keep `use App\Http\Controllers\API\Concerns\ApiResponse;` trait (or copy trait to module — verify pattern)
  - Keep `use Modules\CRM\Models\Pipeline;` import
  - Verify the `index()` method signature remains compatible
- [x] TASK-1.2: Remove `GET /api/v1/pipelines` route from `routes/api.php` line 214 (the `Route::get('/pipelines', ...)` line)
- [x] TASK-1.3: Register the moved `index` route in `Modules/CRM/routes/api.php` inside the existing `rbac` middleware group
  ```php
  Route::get('/pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
  ```
- [x] TASK-1.4: Smoke test: `docker exec crm-laravel-dev php artisan route:list | findstr pipelines` — confirm route active
- [x] TASK-1.5: Run: `docker exec crm-laravel-dev php artisan test` — confirm 0 regressions

---

## Phase 2: Domain Entities + Factories (TDD, 20 min)

> Pure PHP domain entities and Eloquent factories for tests.

- [x] TASK-2.1: RED — Write unit test `tests/Unit/Domain/PipelineTest.php`
  - `it_creates_pipeline_entity_from_array()` — assert properties match
  - `it_creates_pipeline_entity_with_defaults()` — assert habilitado defaults
- [x] TASK-2.2: GREEN — Create `app/Domain/Entities/Pipeline.php`
  - Properties: `id`, `nombre`, `codigo`, `habilitado`, `etapas`, `created_at`, `updated_at`
  - `fromArray()` and `toArray()` methods
- [x] TASK-2.3: RED — Write unit test `tests/Unit/Domain/PipelineEtapaTest.php`
  - `it_creates_etapa_entity_from_array()` — assert properties match
- [x] TASK-2.4: GREEN — Create `app/Domain/Entities/PipelineEtapa.php`
  - Properties: `id`, `pipeline_id`, `nombre`, `orden`, `habilitado`, `created_at`, `updated_at`
  - `fromArray()` and `toArray()` methods
- [x] TASK-2.5: Create `database/factories/PipelineFactory.php` (for `Modules\CRM\Models\Pipeline`)
  - Default: `nombre` → fake->unique()->word(), `codigo` → fake->unique()->regexify('[A-Z]{10}'), `habilitado` → true
- [x] TASK-2.6: Create `database/factories/PipelineEtapaFactory.php` (for `Modules\CRM\Models\PipelineEtapa`)
  - Default: `pipeline_id` → create factory, `nombre` → fake->word(), `orden` → 1, `habilitado` → true
  - Add `forPipeline(Pipeline $pipeline)` state method
- [x] TASK-2.7: Add `use Illuminate\Database\Eloquent\Factories\HasFactory;` trait to `Modules/CRM/app/Models/Pipeline.php` and `Modules/CRM/app/Models/PipelineEtapa.php`
- [x] TASK-2.8: Run: `php artisan test --filter='PipelineTest|PipelineEtapaTest'` — all pass

---

## Phase 3: Pipeline Repositories (TDD, 30 min)

> Repository interface + Eloquent implementation extending BaseRepository.

- [x] TASK-3.1: RED — Write unit test `tests/Unit/Infrastructure/Persistence/EloquentPipelineRepositoryTest.php`
  - 7 tests: find by id, null when missing, create, update, delete, list all, find by codigo
- [x] TASK-3.2: GREEN — Create `app/Domain/Repositories/PipelineRepositoryInterface.php`
  - Methods: all(), find(), findByCodigo(), create(), update(), delete()
- [x] TASK-3.3: GREEN — Create `app/Infrastructure/Persistence/EloquentPipelineRepository.php`
  - Extends `BaseRepository`, implements `PipelineRepositoryInterface`
  - Uses `Modules\CRM\Models\Pipeline` Eloquent model
- [x] TASK-3.4: Register binding in `app/Providers/AppServiceProvider.php`
- [x] TASK-3.5: REFACTOR — skipped (base implementation already clean, no common query scopes needed)
- [x] TASK-3.6: Run: `php artisan test --filter=EloquentPipelineRepositoryTest` — 7 passed

---

## Phase 4: Pipeline UseCases (TDD, 40 min)

> Single test file with Mockery for all UseCases.

- [x] TASK-4.1: RED — Write unit test `tests/Unit/Application/PipelineUseCaseTest.php` (14 tests covering all 5 UseCases)
  - ListPipelinesUseCase: returns array, returns empty when none
  - GetPipelineUseCase: returns entity, throws 404 when missing
  - CreatePipelineUseCase: creates valid, throws on empty nombre/codigo, throws on duplicate codigo
  - UpdatePipelineUseCase: updates valid, throws 404 when missing, throws on duplicate codigo, allows same codigo
  - DeletePipelineUseCase: deletes, throws 404 when missing
- [x] TASK-4.2: GREEN — Create all 5 UseCase classes in `app/Application/UseCases/Pipeline/`
  - ListPipelinesUseCase, GetPipelineUseCase, CreatePipelineUseCase, UpdatePipelineUseCase, DeletePipelineUseCase
- [x] TASK-4.3: Run: `php artisan test --filter=PipelineUseCaseTest` — 14 passed

---

## Phase 5: Pipeline Controller CRUD (TDD, 40 min)

> Form Requests, API Resources, Feature tests, and Controller methods.

- [x] TASK-5.1: RED — Write Feature test `tests/Feature/API/PipelineControllerTest.php`
  - 9 tests: list, create, create validates required, create validates unique codigo, show, show 404, update, delete, delete 404
- [x] TASK-5.2: GREEN — Create `app/Http/Requests/StorePipelineRequest.php`
- [x] TASK-5.3: GREEN — Create `app/Http/Requests/UpdatePipelineRequest.php`
- [x] TASK-5.4: GREEN — Create `app/Http/Resources/PipelineResource.php`
- [x] TASK-5.5: GREEN — Rewrite `Modules/CRM/app/Http/Controllers/PipelineController.php` with constructor DI of all 5 UseCases
- [x] TASK-5.6: Register all 5 CRUD routes in `Modules/CRM/routes/api.php`
- [x] TASK-5.7: Run: `php artisan test --filter=PipelineControllerTest` — 9 passed

---

## Phase 6: Pipeline Etapa Repositories (TDD, 25 min)

> Repository interface + Eloquent implementation.

- [ ] TASK-6.1: RED — Write unit test `tests/Unit/Infrastructure/EloquentPipelineEtapaRepositoryTest.php`
  - `it_finds_etapa_by_id()`, `it_returns_null_for_missing()`
  - `it_finds_by_pipeline()` — assert ordered by `orden`
  - `it_creates_etapa()` — assert auto-increment of `orden` (max+1)
  - `it_updates_etapa()`, `it_deletes_etapa()`
  - `it_reorders_etapas()` — assert orden values updated correctly
- [ ] TASK-6.2: GREEN — Create `app/Domain/Repositories/PipelineEtapaRepositoryInterface.php`
  ```php
  public function find(int $id): ?PipelineEtapa;
  public function findByPipeline(int $pipelineId): array;
  public function create(int $pipelineId, array $data): PipelineEtapa;
  public function update(int $id, array $data): PipelineEtapa;
  public function delete(int $id): bool;
  public function reorder(int $pipelineId, array $orderedIds): void;
  ```
- [ ] TASK-6.3: GREEN — Create `app/Infrastructure/Persistence/EloquentPipelineEtapaRepository.php`
  - Extends `BaseRepository`, implements `PipelineEtapaRepositoryInterface`
  - `getModelClass()` → `Modules\CRM\Models\PipelineEtapa::class`
  - `mapModelToEntity()` → `PipelineEtapaEntity::fromArray()`
  - `findByPipeline()` — query by `pipeline_id`, ordered by `orden` asc
  - `create()` — auto-set `orden` to max+1 for the pipeline (or 1 if first)
  - `reorder()` — validates IDs belong to pipeline, then `DB::transaction` with individual updates
- [ ] TASK-6.4: Register binding in `AppServiceProvider`:
  ```php
  use App\Domain\Repositories\PipelineEtapaRepositoryInterface;
  use App\Infrastructure\Persistence\EloquentPipelineEtapaRepository;
  $this->app->bind(PipelineEtapaRepositoryInterface::class, EloquentPipelineEtapaRepository::class);
  ```
- [ ] TASK-6.5: Run: `php artisan test --filter=EloquentPipelineEtapaRepositoryTest` — all pass

---

## Phase 7: Pipeline Etapa UseCases (TDD, 30 min)

- [ ] TASK-7.1: RED + GREEN — `StorePipelineEtapaUseCase` — test + implement
  - `execute(int $pipelineId, array $data): PipelineEtapa`
  - Validates parent pipeline exists (call `find()` first)
  - Returns entity
- [ ] TASK-7.2: RED + GREEN — `IndexPipelineEtapaUseCase` — test + implement
  - `execute(int $pipelineId): array`
- [ ] TASK-7.3: RED + GREEN — `ShowPipelineEtapaUseCase` — test + implement
  - `execute(int $id): ?PipelineEtapa`
  - Throws `NotFoundException` if null
- [ ] TASK-7.4: RED + GREEN — `UpdatePipelineEtapaUseCase` — test + implement
  - `execute(int $id, array $data): PipelineEtapa`
- [ ] TASK-7.5: RED + GREEN — `DestroyPipelineEtapaUseCase` — test + implement
  - `execute(int $id): bool`
  - Throws `NotFoundException` if false
- [ ] TASK-7.6: RED + GREEN — `ReorderPipelineEtapaUseCase` — test + implement
  - `execute(int $pipelineId, array $orderedIds): void`
  - Validates ALL IDs belong to the pipeline (422 if not)
  - Calls `$this->repository->reorder($pipelineId, $orderedIds)` inside `DB::transaction`
  - **Critical**: if `orderedIds` contains IDs not belonging to this pipeline, throw `ValidationException` with 422 and `invalid_ids` array
- [ ] TASK-7.7: Run: `php artisan test --filter=PipelineEtapaUseCaseTest` — all pass

---

## Phase 8: Pipeline Etapa Controller (TDD, 30 min)

- [ ] TASK-8.1: Create `Modules/CRM/app/Http/Controllers/PipelineEtapaController.php`
  - Constructor injection of all UseCases (or use method DI)
  - Methods: `index($pipelineId)`, `store($pipelineId, StorePipelineEtapaRequest)`, `show($id)`, `update($id, UpdatePipelineEtapaRequest)`, `destroy($id)`, `reorder($pipelineId, ReorderPipelineEtapaRequest)`
  - Use `ApiResponse` trait
- [ ] TASK-8.2: Create Form Requests:
  - `app/Http/Requests/StorePipelineEtapaRequest.php` — `nombre` required|string|max:100, `orden` integer|min:0|nullable, `habilitado` boolean|nullable
  - `app/Http/Requests/UpdatePipelineEtapaRequest.php` — same as store
  - `app/Http/Requests/ReorderPipelineEtapaRequest.php` — `ordered_ids` required|array|min:1, `ordered_ids.*` integer|exists:pipeline_etapas,id
- [ ] TASK-8.3: Create `app/Http/Resources/PipelineEtapaResource.php`
  - `toArray()` returns `id, pipeline_id, nombre, orden, habilitado, created_at, updated_at`
- [ ] TASK-8.4: RED — Write Feature test `tests/Feature/API/PipelineEtapaControllerTest.php`
  - Tests:
    - `it_lists_etapas_ordered()` — GET /api/v1/pipelines/1/etapas → 200
    - `it_creates_etapa_with_auto_orden()` — POST /api/v1/pipelines/1/etapas → 201, assert `data.orden` is next
    - `it_shows_an_etapa()` — GET /api/v1/pipelines/etapas/1 → 200
    - `it_updates_an_etapa()` — PUT /api/v1/pipelines/etapas/1 → 200
    - `it_deletes_an_etapa()` — DELETE /api/v1/pipelines/etapas/1 → 200
    - `it_reorders_etapas()` — PUT /api/v1/pipelines/1/etapas/reorder → 200, assert orden changed
    - `it_rejects_cross_pipeline_reorder()` — PUT with wrong IDs → 422
- [ ] TASK-8.5: Register routes in `Modules/CRM/routes/api.php` inside `rbac` group:
  ```php
  Route::get('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'index'])->name('pipeline-etapas.index');
  Route::post('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'store'])->name('pipeline-etapas.store');
  Route::get('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'show'])->name('pipeline-etapas.show');
  Route::put('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'update'])->name('pipeline-etapas.update');
  Route::delete('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'destroy'])->name('pipeline-etapas.destroy');
  Route::put('/pipelines/{pipeline}/etapas/reorder', [PipelineEtapaController::class, 'reorder'])->name('pipeline-etapas.reorder');
  ```
  > **Note**: Use `pipelines/etapas/{id}` for show/update/delete (un-nested path) as specified in the API contract.
- [ ] TASK-8.6: Run: `php artisan test --filter=PipelineEtapaControllerTest` — all pass

---

## Phase 9: PipelineEtapaChanged Event (TDD, 25 min)

> Domain event dispatched from OportunidadObserver when `pipeline_etapa_id` changes.

- [ ] TASK-9.1: RED — Write Feature test `tests/Feature/Events/PipelineEtapaChangedEventTest.php`
  - `setUp()` seeds PipelineSeeder, creates Oportunidad with `pipeline_etapa_id`
  - `it_dispatches_event_when_pipeline_etapa_id_changes()`:
    - Fake `PipelineEtapaChanged` event with `Event::fake()`
    - Update `pipeline_etapa_id` to a different value
    - Assert event dispatched with correct payload
  - `it_does_not_dispatch_when_other_field_changes()`:
    - Update only `total` field
    - Assert event NOT dispatched
  - `it_does_not_dispatch_when_pipeline_etapa_id_set_to_null()`:
    - Set `pipeline_etapa_id` to null
    - Assert event NOT dispatched (guard against null newEtapa)
- [ ] TASK-9.2: GREEN — Create `app/Events/PipelineEtapaChanged.php`
  ```php
  class PipelineEtapaChanged
  {
      public function __construct(
          public Oportunidad $oportunidad,
          public ?PipelineEtapa $oldEtapa,
          public PipelineEtapa $newEtapa,
      ) {}
  }
  ```
  - **Important**: Reference `Modules\CRM\Models\Oportunidad` and `Modules\CRM\Models\PipelineEtapa`
  - Make the event `ShouldDispatch` (implements `ShouldDispatch` or use the `Dispatchable` trait)
- [ ] TASK-9.3: GREEN — Modify `app/Observers/OportunidadObserver.php::updated()`
  - **After** existing `isDirty('pipeline_etapa_id')` check and existing `cliente_desde` logic
  - Add the event dispatch:
    ```php
    $oldEtapa = PipelineEtapa::find($oportunidad->getOriginal('pipeline_etapa_id'));
    $newEtapa = PipelineEtapa::find($oportunidad->pipeline_etapa_id);

    if ($newEtapa) {
        PipelineEtapaChanged::dispatch($oportunidad, $oldEtapa, $newEtapa);
    }
    ```
- [ ] TASK-9.4: Run: `php artisan test --filter=PipelineEtapaChangedEventTest` — all pass

---

## Phase 10: Webhook Listener (TDD, 35 min)

> Listener dispatches a queued webhook job with config prefix support.

- [ ] TASK-10.1: RED — Write test `tests/Feature/Events/PipelineEtapaChangedEventTest.php` (extend class or new file)
  - `it_queues_webhook_job_when_event_dispatched()`:
    - `Queue::fake()` + `Event::fake()` (but allow real event → listener)
    - Update `pipeline_etapa_id` on an Oportunidad
    - Assert `DispatchOutboundWebhookJob` was pushed with `event='pipeline.etapa.changed'` and `configPrefix='pipeline'`
  - `it_does_not_queue_webhook_when_url_not_configured()`:
    - Set `config('webhook.pipeline.url')` to empty string
    - Assert no job was dispatched
  - `it_includes_correct_payload_in_webhook_job()`:
    - Assert payload contains `oportunidad_id`, `old_etapa_nombre`, `new_etapa_nombre`, `pipeline_id`
- [ ] TASK-10.2: GREEN — Create `app/Infrastructure/Webhook/Listeners/SendPipelineWebhookListener.php`
  ```php
  class SendPipelineWebhookListener
  {
      public function handle(PipelineEtapaChanged $event): void
      {
          $configPrefix = 'pipeline';
          $url = config("webhook.{$configPrefix}.url");

          if (empty($url)) {
              Log::debug('Pipeline webhook URL not configured — skipping');
              return;
          }

          DispatchOutboundWebhookJob::dispatch(
              event: 'pipeline.etapa.changed',
              data: [
                  'oportunidad_id' => $event->oportunidad->id,
                  'previous_etapa_nombre' => $event->oldEtapa?->nombre,
                  'new_etapa_nombre' => $event->newEtapa->nombre,
                  'previous_etapa_id' => $event->oldEtapa?->id,
                  'new_etapa_id' => $event->newEtapa->id,
                  'pipeline_id' => $event->newEtapa->pipeline_id,
                  'entidad_id' => $event->oportunidad->entidad_id,
                  'contacto_id' => $event->oportunidad->contacto_id,
                  'user_id' => $event->oportunidad->updated_by ?? $event->oportunidad->created_by,
                  'dedup_key' => 'pipeline.etapa.changed:' . $event->oportunidad->id . ':' . now()->timestamp,
              ],
              configPrefix: $configPrefix,
          );
      }
  }
  ```
- [ ] TASK-10.3: GREEN — Register listener in `app/Providers/EventServiceProvider.php`:
  ```php
  use App\Events\PipelineEtapaChanged;
  use App\Infrastructure\Webhook\Listeners\SendPipelineWebhookListener;

  PipelineEtapaChanged::class => [
      SendPipelineWebhookListener::class,
  ],
  ```
- [ ] TASK-10.4: GREEN — Modify `app/Infrastructure/Webhook/DispatchOutboundWebhookJob.php`
  - Add `public string $configPrefix = 'outbound'` to constructor
  - Pass it to `$sender->send($this->event, $this->data, $this->configPrefix)`
- [ ] TASK-10.5: GREEN — Modify `app/Infrastructure/Webhook/CrmWebhookSender.php`
  - Change `send(string $event, array $data)` → `send(string $event, array $data, string $configPrefix = 'outbound')`
  - Replace `config('webhook.outbound.*')` with `config("webhook.{$configPrefix}.*")`
  - Guard: if URL is empty, log debug and return early
- [ ] TASK-10.6: Update `config/webhook.php` — add `pipeline` config block:
  ```php
  'pipeline' => [
      'url' => env('N8N_PIPELINE_WEBHOOK_URL', ''),
      'secret' => env('N8N_PIPELINE_WEBHOOK_SECRET', ''),
  ],
  ```
- [ ] TASK-10.7: Add env vars to `.env.example`:
  ```
  N8N_PIPELINE_WEBHOOK_URL=
  N8N_PIPELINE_WEBHOOK_SECRET=
  ```
- [ ] TASK-10.8: Run: `php artisan test --filter=PipelineEtapaChangedEventTest` — all pass

---

## Phase 11: Bulk Move (TDD, 40 min)

> Transactional bulk move endpoint with per-oportunidad event firing.

- [ ] TASK-11.1: RED — Write Feature test `tests/Feature/API/BulkMoveOportunidadesTest.php`
  - `setUp()` seeds PipelineSeeder, creates 3 oportunidades with etapa IDs
  - Tests:
    - `it_moves_multiple_oportunidades()`:
      - POST /api/v1/oportunidades/bulk-move-pipeline with `{ oportunidad_ids: [1,2,3], target_pipeline_etapa_id: 5 }`
      - Assert 200, `data.moved_count` = 3
      - Assert each oportunidad now has `pipeline_etapa_id = 5`
    - `it_rolls_back_on_invalid_id()`:
      - POST with `{ oportunidad_ids: [1, 999], target_pipeline_etapa_id: 5 }`
      - Assert 422, `invalid_ids` contains 999
      - Assert oportunidad 1 still has original `pipeline_etapa_id`
    - `it_fires_event_per_oportunidad()`:
      - `Event::fake()`
      - Move 3 oportunidades
      - Assert `PipelineEtapaChanged` dispatched 3 times
    - `it_rejects_without_bulk_move_permission()`:
      - Create Rol without `oportunidades.bulk-move`
      - Assert 403
- [ ] TASK-11.2: GREEN — Create `app/Application/UseCases/Oportunidad/BulkMoveOportunidadesToPipelineUseCase.php`
  - `execute(array $oportunidadIds, int $targetPipelineEtapaId): array`
  - Flow:
    1. Find all `Oportunidad` records (using Eloquent directly or repository)
    2. Validate all IDs exist — if any missing, throw ValidationException with invalid_ids
    3. Validate target etapa exists and is enabled
    4. `DB::transaction()`:
       - For each oportunidad, update `pipeline_etapa_id`
       - OportunidadObserver::updated() fires event per save
    5. Return `['moved_count' => count($oportunidadIds), 'oportunidad_ids' => $oportunidadIds]`
  - Use `Modules\CRM\Models\Oportunidad` model directly (observer is registered globally)
- [ ] TASK-11.3: Create `app/Http/Requests/BulkMoveOportunidadesRequest.php`
  - Rules: `oportunidad_ids` required|array|min:1, `oportunidad_ids.*` integer|exists:oportunidad,id, `target_pipeline_etapa_id` required|integer|exists:pipeline_etapas,id
- [ ] TASK-11.4: Create `Modules/CRM/app/Http/Controllers/BulkMoveOportunidadesController.php`
  - `__invoke(BulkMoveOportunidadesRequest $req, BulkMoveOportunidadesToPipelineUseCase $uc)`
  - Returns `$this->successResponse($uc->execute(...))`
- [ ] TASK-11.5: Register route in `Modules/CRM/routes/api.php` inside `rbac` group:
  ```php
  Route::post('/oportunidades/bulk-move-pipeline', BulkMoveOportunidadesController::class)
      ->name('oportunidades.bulk-move');
  ```
- [ ] TASK-11.6: Run: `php artisan test --filter=BulkMoveOportunidadesTest` — all pass

---

## Phase 12: Data Migration (estado → pipeline_etapa_id) (30 min)

> One-time idempotent migration to backfill `pipeline_etapa_id` for legacy records.

- [ ] TASK-12.1: RED — Write migration test `tests/Feature/Migration/PipelineEtapaMigrationTest.php`
  - `it_migrates_estado_to_pipeline_etapa_id()`:
    - Seed Cotización pipeline with etapas
    - Create Oportunidad with `estado='Borrador'`, `pipeline_etapa_id=null`
    - Run migration (call the migration's `up()` or artisan migrate)
    - Assert `pipeline_etapa_id` is now set to the Borrador etapa's id
  - `it_is_idempotent()`:
    - Run migration twice
    - Assert no changes on second run (verify via DB::getQueryLog or assert same counts)
  - `it_defaults_unmatched_estado_to_first_etapa()`:
    - Create Oportunidad with `estado='UnknownValue'`
    - Run migration
    - Assert `pipeline_etapa_id` = first etapa (orden=1) of Cotización
  - `it_skips_already_migrated_rows()`:
    - Create Oportunidad with `pipeline_etapa_id` already set
    - Run migration
    - Assert the row was not updated
- [ ] TASK-12.2: GREEN — Create migration file (if needed):
  - Check if `2026_06_04_192500_migrate_existing_opportunities_to_pipelines_and_stages.php` already handles this
  - If not, create `database/migrations/2026_06_07_000000_assign_pipeline_etapa_to_existing_oportunidades.php`
  - Mapping logic:
    ```php
    $mapping = [
        'prospecto'  => ['pipeline_codigo' => 'RECUPERACION', 'etapa_nombre' => 'Inicio'],
        'cotizacion' => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Borrador'],
        'negociacion'=> ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'En Negociación'],
        'ganado'     => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Aprobado'],
        'perdido'    => ['pipeline_codigo' => 'COTIZACION',   'etapa_nombre' => 'Rechazado'],
    ];
    ```
  - Find all Oportunidad where `pipeline_etapa_id IS NULL`
  - For each, look up `estado` in mapping → find etapa by `pipeline.codigo` + `etapa.nombre`
  - If matched, set `pipeline_id` and `pipeline_etapa_id`
  - If not matched, default to first etapa (orden=1) of Cotización
  - Log count of migrated rows
- [ ] TASK-12.3: After migration, remove `estado` from `Oportunidad::$fillable` (in `Modules/CRM/app/Models/Oportunidad.php`) — verify this doesn't break existing create/update flows that still send `estado`
- [ ] TASK-12.4: Run: `php artisan migrate:fresh --seed` and verify oportunidades have `pipeline_etapa_id`
- [ ] TASK-12.5: Run: `php artisan test --filter=PipelineEtapaMigrationTest` — all pass

---

## Phase 13: Default Pipelines Seeder (20 min)

> Idempotent seeder for Cotización and Recuperación pipelines with 5 etapas each.

- [ ] TASK-13.1: Create `database/seeders/PipelineSeeder.php`
  - **Cotización pipeline** (codigo: COTIZACION) with 5 etapas:
    | orden | nombre |
    |-------|--------|
    | 1 | Borrador |
    | 2 | Enviado |
    | 3 | En Negociación |
    | 4 | Aprobado |
    | 5 | Rechazado |
  - **Recuperación pipeline** (codigo: RECUPERACION) with 5 etapas:
    | orden | nombre |
    |-------|--------|
    | 1 | Inicio |
    | 2 | Con Cita |
    | 3 | En Negociación |
    | 4 | Aprobado |
    | 5 | Rechazado |
  - Use `updateOrInsert` for idempotency:
    ```php
    DB::table('pipelines')->updateOrInsert(
        ['codigo' => 'COTIZACION'],
        ['nombre' => 'Cotización', 'habilitado' => true]
    );
    ```
- [ ] TASK-13.2: Register in `DatabaseSeeder.php`:
  ```php
  $this->call(PipelineSeeder::class);
  ```
  Place it BEFORE `OportunidadSeeder` or any seeder that depends on pipelines.
- [ ] TASK-13.3: Run twice and confirm idempotent:
  ```bash
  docker exec crm-laravel-dev php artisan db:seed --class=PipelineSeeder
  docker exec crm-laravel-dev php artisan db:seed --class=PipelineSeeder
  ```
  Assert counts are the same.
- [ ] TASK-13.4: Run: `php artisan test` — confirm no regressions from existing tests that depend on PipelineSeeder

---

## Phase 14: Permissions Seeder (15 min)

> Add pipeline, etapa, and bulk-move permissions.

- [ ] TASK-14.1: Update `database/seeders/PermisoSeeder.php`
  - In the `$vistas` array, update `'pipelines'` from `['index']` to `['index', 'store', 'show', 'update', 'destroy']`
  - Add `'pipeline-etapas' => ['index', 'store', 'show', 'update', 'destroy']`
  - Update `'oportunidades'` to add `'bulk-move'`:
    ```php
    'oportunidades' => ['index', 'store', 'show', 'update', 'destroy', 'ganar', 'clonar', 'version', 'bulk-move'],
    ```
- [ ] TASK-14.2: Run and confirm no duplicates:
  ```bash
  docker exec crm-laravel-dev php artisan db:seed --class=PermisoSeeder
  ```
  - Verify new permissions exist: `pipelines.store`, `pipeline-etapas.index`, `oportunidades.bulk-move`, etc.
  - Run again and confirm no `duplicate entry` errors
- [ ] TASK-14.3: Run: `php artisan test` — confirm no regressions

---

## Phase 15: Frontend Routes — CRMLayout (35 min)

> Restructure frontend routes under `/crm/*` with shared layout.

- [ ] TASK-15.1: Create `dashboard-crm/src/pages/CRMLayout.tsx`
  - Renders sidebar (reuse existing Sidebar component) + `<Outlet />`
  - Uses React Router v6 `<Outlet />` for nested routes
  - Wraps children in a flex container: sidebar on left, content area on right
- [ ] TASK-15.2: Update `dashboard-crm/src/App.tsx` router config
  ```tsx
  <Route path="/crm" element={<CRMLayout />}>
    <Route index element={<Navigate to="/crm/oportunidad" replace />} />
    <Route path="dashboard" element={<DashboardPage />} />
    <Route path="pipelines" element={<PipelineAdmin />} />
    <Route path="oportunidad" element={<CRMPage />} />
    <Route path="contactos" element={<ContactosPage />} />
    <Route path="entidad" element={<EntidadPage />} />
  </Route>
  ```
- [ ] TASK-15.3: Move existing `/crm` route to `/crm/oportunidad` — update any hardcoded links
- [ ] TASK-15.4: Update `Sidebar.tsx` navigation items to point to new sub-routes:
  - `/crm/dashboard`, `/crm/pipelines`, `/crm/oportunidad`, `/crm/contactos`, `/crm/entidad`
- [ ] TASK-15.5: Run: `cd D:\sitios desarrollo\dashboard-crm && npm run build` — confirm no TS/build errors
- [ ] TASK-15.6: Manual test: navigate to each `/crm/*` route and confirm renders

---

## Phase 16: Frontend PipelineAdmin Page (40 min)

> Full admin page for pipelines with CRUD modals and etapa reorder.

- [ ] TASK-16.1: Create `dashboard-crm/src/pages/PipelineAdmin.tsx`
  - Fetch pipelines with `useQuery(['pipelines'])`
  - Display as table/card view with columns: nombre, codigo, habilitado, etapas count
  - Add "Create Pipeline" button → modal with form (nombre, codigo, habilitado)
  - Each row: Edit button → modal, Delete button → confirm dialog
  - Expandable section per row: list etapas with up/down reorder buttons
  - "Add Etapa" button per pipeline → modal with nombre
  - Etapa edit/delete inline or via modal
- [ ] TASK-16.2: Wire API calls (covers Phase 17 API integration):
  - `useMutation` for create/update/delete pipeline → invalidate `['pipelines']`
  - `useMutation` for create/update/delete etapa → invalidate `['pipelines']`
  - `useMutation` for reorder → invalidate `['pipelines']`
- [ ] TASK-16.3: Handle loading, empty, and error states
- [ ] TASK-16.4: Run: `cd D:\sitios desarrollo\dashboard-crm && npm run build` — confirm no errors

---

## Phase 17: Frontend API Integration (25 min)

> Wire all new endpoints into the frontend API layer.

- [ ] TASK-17.1: Add TypeScript interfaces to `dashboard-crm/src/api/types.ts`:
  ```typescript
  interface Pipeline {
    id: number;
    nombre: string;
    codigo: string;
    habilitado: boolean;
    etapas?: PipelineEtapa[];
    created_at: string;
    updated_at: string;
  }

  interface PipelineEtapa {
    id: number;
    pipeline_id: number;
    nombre: string;
    orden: number;
    habilitado: boolean;
    created_at: string;
    updated_at: string;
  }
  ```
- [ ] TASK-17.2: Add CRUD functions to `dashboard-crm/src/api/crmApi.ts`:
  ```typescript
  // Pipelines
  export const createPipeline = (data: Partial<Pipeline>) => api.post('/v1/pipelines', data);
  export const updatePipeline = (id: number, data: Partial<Pipeline>) => api.put(`/v1/pipelines/${id}`, data);
  export const deletePipeline = (id: number) => api.delete(`/v1/pipelines/${id}`);

  // Etapas
  export const createEtapa = (pipelineId: number, data: Partial<PipelineEtapa>) =>
    api.post(`/v1/pipelines/${pipelineId}/etapas`, data);
  export const updateEtapa = (id: number, data: Partial<PipelineEtapa>) =>
    api.put(`/v1/pipelines/etapas/${id}`, data);
  export const deleteEtapa = (id: number) => api.delete(`/v1/pipelines/etapas/${id}`);
  export const reorderEtapas = (pipelineId: number, ordered_ids: number[]) =>
    api.put(`/v1/pipelines/${pipelineId}/etapas/reorder`, { ordered_ids });

  // Bulk move
  export const bulkMoveOportunidades = (oportunidad_ids: number[], target_pipeline_etapa_id: number) =>
    api.post('/v1/oportunidades/bulk-move-pipeline', { oportunidad_ids, target_pipeline_etapa_id });
  ```
- [ ] TASK-17.3: Update `CRMPage.tsx` (now at `/crm/oportunidad`) to send `pipeline_etapa_id` on Kanban drag:
  - Change the `onDragEnd` handler to send `{ pipeline_etapa_id: targetId }` instead of `{ estado: string }`
  - During transition period, send BOTH: `{ pipeline_etapa_id: targetId, estado: etapaName }` for backward compatibility
- [ ] TASK-17.4: Add bulk move action to CRMPage:
  - Select rows in table view (checkbox column)
  - "Move to Rescue" button in top action bar
  - Target pipeline selector (dropdown showing available pipelines)
  - Confirm dialog → call `bulkMoveOportunidades()`
- [ ] TASK-17.5: Update `useMutation` hooks to invalidate `['pipelines']` query on success
- [ ] TASK-17.6: Run: `cd D:\sitios desarrollo\dashboard-crm && npm run build` — confirm no errors

---

## Phase 18: Email Draft — Mailrelay Autoresponder (60 min)

> Configure the autoresponder email templates that n8n will send via Mailrelay when an oportunidad changes etapa.
> Two pipelines need email drafts: **Llegada** (onboarding) and **Recuperación** (reactivation of cold clients).
> Canonical copies: `Docs/phase-18-email-draft.md` (Llegada) + `Docs/phase-18-reactivation-draft.md` (Recuperación).

### Pipeline Llegada (onboarding)

- [ ] TASK-18.1: Review the Llegada email draft with marketing team
- [ ] TASK-18.2: Import the HTML template into Mailrelay's template library
- [ ] TASK-18.3: Configure merge tags: `nombre`, `empresa`, `pipeline`, `etapa`, `oportunidad_id`, `asesor_nombre`, `asesor_telefono`, `url_cotizacion`
- [ ] TASK-18.4: Create 3 subject line variants (Default, Variant B urgency, Variant C social proof)
- [ ] TASK-18.5: Configure A/B test in Mailrelay (40/40/20 split)

### Pipeline Recuperación (reactivación de clientes fríos)

- [ ] TASK-18.6: Review the Recuperación email draft with marketing team
- [ ] TASK-18.7: Import the HTML template into Mailrelay's template library
- [ ] TASK-18.8: Configure merge tags: `nombre`, `empresa`, `dias_inactividad`, `asesor_nombre`, `asesor_telefono`, `url_consultoria`
- [ ] TASK-18.9: Create 3 subject line variants (urgency, social proof, value proposition)
- [ ] TASK-18.10: Configure send triggers for cold clients (3+ months inactive)

### Validación

- [ ] TASK-18.11: Test send both templates to internal email — verify merge tags render correctly
- [ ] TASK-18.12: Document the n8n workflow in `docs/n8n/pipeline-etapa-changed-workflow.md`
- [ ] TASK-18.13: Add footer unsubscribe link in both emails (RDLC compliance)

---

## Phase 19: Final Validation (20 min)

- [ ] TASK-19.1: Run all PHPUnit tests:
  ```bash
  docker exec crm-laravel-dev php artisan test
  ```
  — confirm 0 failures
- [ ] TASK-19.2: Run linter:
  ```bash
  docker exec crm-laravel-dev ./vendor/bin/pint
  ```
- [ ] TASK-19.3: Frontend build:
  ```bash
  cd D:\sitios desarrollo\dashboard-crm && npm run build
  ```
- [ ] TASK-19.4: Manual E2E test:
  1. Create pipeline (POST /pipelines) → 201
  2. Add 3 etapas (POST /pipelines/1/etapas) → 201 each
  3. Reorder etapas (PUT /pipelines/1/etapas/reorder) → 200
  4. Create oportunidad via existing endpoint → verify `pipeline_etapa_id` is set
  5. Update oportunidad to different etapa → verify event fires
  6. Bulk move 2 oportunidades → verify both moved + events fired
  7. Verify webhook logs (or queue) show dispatched jobs
- [ ] TASK-19.5: Check git status:
  ```bash
  git status
  ```
  — confirm only intended files changed
- [ ] TASK-19.6: Commit with conventional message:
  ```
  feat(crm): add Pipeline/PipelineEtapa CRUD + bulk move + n8n webhook
  ```
- [ ] TASK-19.7: Push branch and create PR:
  ```bash
  git push origin feature/pipelines-crud-webhook
  ```

---

## Summary

| Phase | Description | Tasks | Est. Time |
|-------|-------------|-------|-----------|
| 0 | Setup | 3 | 10 min |
| 1 | Module Route Refactor | 5 | 20 min |
| 2 | Domain Entities + Factories | 8 | 20 min |
| 3 | Pipeline Repositories | 6 | 30 min |
| 4 | Pipeline UseCases | 7 | 40 min |
| 5 | Pipeline Controller CRUD | 7 | 40 min |
| 6 | Pipeline Etapa Repositories | 5 | 25 min |
| 7 | Pipeline Etapa UseCases | 7 | 30 min |
| 8 | Pipeline Etapa Controller | 6 | 30 min |
| 9 | PipelineEtapaChanged Event | 4 | 25 min |
| 10 | Webhook Listener | 8 | 35 min |
| 11 | Bulk Move | 6 | 40 min |
| 12 | Data Migration | 5 | 30 min |
| 13 | Default Pipelines Seeder | 4 | 20 min |
| 14 | Permissions Seeder | 3 | 15 min |
| 15 | Frontend Routes — CRMLayout | 6 | 35 min |
| 16 | Frontend PipelineAdmin Page | 4 | 40 min |
| 17 | Frontend API Integration | 6 | 25 min |
| 18 | Email Draft (Mailrelay Autoresponder) | 13 | 60 min |
| 19 | Final Validation | 7 | 20 min |
| **Total** | | **~120 tasks** | **~10.5 hours** |

### Key Links

- **Proposal**: `openspec/changes/pipelines-crud-webhook/proposal.md`
- **Spec**: `openspec/changes/pipelines-crud-webhook/spec.md`
- **Design**: `openspec/changes/pipelines-crud-webhook/design.md`
- **Tasks**: `openspec/changes/pipelines-crud-webhook/tasks.md` ← you are here

### Test Command Reference

| Scope | Command |
|-------|---------|
| All tests | `docker exec crm-laravel-dev php artisan test` |
| Pipeline CRUD | `docker exec crm-laravel-dev php artisan test --filter=PipelineControllerTest` |
| Etapa CRUD | `docker exec crm-laravel-dev php artisan test --filter=PipelineEtapaControllerTest` |
| Bulk Move | `docker exec crm-laravel-dev php artisan test --filter=BulkMoveOportunidadesTest` |
| Event + Webhook | `docker exec crm-laravel-dev php artisan test --filter=PipelineEtapaChangedEventTest` |
| Repositories | `docker exec crm-laravel-dev php artisan test --filter=EloquentPipeline` |
| UseCases | `docker exec crm-laravel-dev php artisan test --filter=PipelineUseCaseTest` |
| Domain Entities | `docker exec crm-laravel-dev php artisan test --filter='PipelineTest|PipelineEtapaTest'` |
| Migration | `docker exec crm-laravel-dev php artisan test --filter=PipelineEtapaMigrationTest` |
| Frontend build | `cd D:\sitios desarrollo\dashboard-crm && npm run build` |
