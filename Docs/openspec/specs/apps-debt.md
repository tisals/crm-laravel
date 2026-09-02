# Debt Spec: Apps Catalog CRUD + Entity-Assignment Endpoints

## Purpose

Document the gap between the frontend's expectations and the backend's actual implementation for managing the `apps` catalog and assigning apps to entities. The frontend (`dashboard-crm/src/api/crmApi.ts:309-362`) calls 9 endpoints that **do not exist** in the backend today — every call returns 404. This blocks the catalog UI from rendering any apps and blocks assignment workflows entirely.

---

## Affected Scope

| Layer | State |
|-------|-------|
| Schema (`apps` table) | ✅ Exists (BIGINT id, VARCHAR slug UNIQUE, VARCHAR nombre, ENUM tipo, ENUM auth_type, BOOLEAN activo, TEXT descripcion, timestamps, softDeletes) |
| Schema (`app_entidad` pivot) | ✅ Exists (per `App::entidades()` withPivot of fecha_contrato, fecha_vencimiento, estado, notas, created_by) |
| Model (`App`) | ✅ Exists with `entidades()` BelongsToMany relation |
| Seeder (`AppsCatalogSeeder`) | ✅ Exists (8 apps seeded: crm, sailus, marketing, wp-plugin, la-llave, brp, mercurio, fama) |
| Controller (`AppController`) | ❌ Missing |
| Controller (`AppEntidadController`) | ❌ Missing |
| Routes (`/apps/*`, `/entidad/{id}/apps/*`) | ❌ Missing in `routes/api.php` |
| Frontend API client (`crmApi.ts:309-362`) | ⚠️ Calls defined; every call returns 404 today |

---

## Frontend Expectations (calls that 404)

Per `dashboard-crm/src/api/crmApi.ts:309-362`:

| # | Method | Endpoint | Function | Purpose |
|---|--------|----------|----------|---------|
| 1 | `GET` | `/apps` | `getApps()` | List catalog (paginated, filter by `search`, `tipo`, `activo`) |
| 2 | `GET` | `/apps/{id}` | `getApp(id)` | Get one app by ID |
| 3 | `POST` | `/apps` | `createApp(payload)` | Create new app |
| 4 | `PUT` | `/apps/{id}` | `updateApp(id, payload)` | Update app |
| 5 | `DELETE` | `/apps/{id}` | `deleteApp(id)` | Soft-delete app |
| 6 | `GET` | `/apps/{appId}/entidades` | `getEntidadesConApp(appId)` | List entities assigned to this app |
| 7 | `GET` | `/entidad/{entidadId}/apps` | `getAppAsignadas(entidadId)` | List apps assigned to this entity |
| 8 | `POST` | `/entidad/{entidadId}/apps/{appId}` | `assignAppToEntidad(entidadId, appId, payload)` | Assign app to entity (with fecha_contrato, fecha_vencimiento, estado, notas) |
| 9 | `DELETE` | `/entidad/{entidadId}/apps/{appId}` | `removeAppFromEntidad(entidadId, appId)` | Unassign app from entity |

---

## What's Missing (concrete deliverables)

### 1. `AppController` (`app/Http/Controllers/API/AppController.php`)

CRUD endpoints for the apps catalog. Follows the same Clean Architecture pattern as `EntidadController`:

- `index(Request)` — paginated list with filters (`search`, `tipo`, `activo`)
- `show($id)` — return one app
- `store(StoreAppRequest)` — create new app (validation: slug unique, nombre required, tipo ∈ enum, auth_type ∈ enum)
- `update($id, UpdateAppRequest)` — update app
- `destroy($id)` — soft-delete (uses `App::find()->delete()` which respects SoftDeletes trait)

### 2. `AppEntidadController` (`app/Http/Controllers/API/AppEntidadController.php`)

Assignment endpoints that operate on the `app_entidad` pivot:

- `indexByApp($appId)` — list entities assigned to this app (uses `App::find($appId)->entidades`)
- `indexByEntidad($entidadId)` — list apps assigned to this entity (uses `Entidad::find($entidadId)->apps`)
- `store(AssignAppRequest, $entidadId, $appId)` — assign with optional `fecha_contrato`, `fecha_vencimiento`, `estado`, `notas`
- `destroy($entidadId, $appId)` — unassign

### 3. Routes (`routes/api.php`)

Add under the existing `auth:sanctum` group with throttle middleware:

```php
// Apps catalog
Route::get('/apps', [AppController::class, 'index']);
Route::get('/apps/{id}', [AppController::class, 'show']);
Route::post('/apps', [AppController::class, 'store']);
Route::put('/apps/{id}', [AppController::class, 'update']);
Route::delete('/apps/{id}', [AppController::class, 'destroy']);

// Apps assigned to entities
Route::get('/apps/{appId}/entidades', [AppEntidadController::class, 'indexByApp']);
Route::get('/entidad/{entidadId}/apps', [AppEntidadController::class, 'indexByEntidad']);
Route::post('/entidad/{entidadId}/apps/{appId}', [AppEntidadController::class, 'store']);
Route::delete('/entidad/{entidadId}/apps/{appId}', [AppEntidadController::class, 'destroy']);
```

### 4. Form Requests (`app/Http/Requests/`)

- `StoreAppRequest` — validate slug uniqueness, required fields, enum values
- `UpdateAppRequest` — partial validation for updates
- `AssignAppRequest` — validate pivot fields (estado ∈ enum, fechas ISO 8601)

### 5. Use Cases (`app/Application/UseCases/`)

Following Clean Architecture pattern of `EntidadController`:

- `CreateApp`, `UpdateApp`, `DeleteApp`, `GetApp`, `ListApps`
- `AssignAppToEntidad`, `UnassignAppFromEntidad`, `ListAppsForEntidad`, `ListEntidadesForApp`

### 6. Tests (`tests/Feature/API/`)

- `AppControllerTest.php` — Feature tests for the 5 CRUD endpoints (auth, validation, soft-delete behavior)
- `AppEntidadControllerTest.php` — Feature tests for the 4 assignment endpoints (assignment, unassignment, query both directions)

---

## Why This Is Debt (not a new feature)

This is debt because:
1. **The frontend assumes these endpoints exist** — calls are defined and the UI is built assuming they return data.
2. **The schema is in place** — only the API layer is missing.
3. **The seeder works** — data exists, it just can't be queried through the API.
4. **User confirmed** — apps can be INSERTed directly to the DB (proven today), but the catalog UI shows nothing because `GET /apps` 404s.

---

## Acceptance Criteria for Closure

This debt is closed when:
- [ ] `GET /apps` returns paginated list of apps from the catalog (with filters)
- [ ] `GET /apps/{id}` returns one app
- [ ] `POST /apps` creates a new app and returns it
- [ ] `PUT /apps/{id}` updates an app
- [ ] `DELETE /apps/{id}` soft-deletes an app (subsequent queries don't return it)
- [ ] `GET /apps/{appId}/entidades` returns entities assigned to this app
- [ ] `GET /entidad/{entidadId}/apps` returns apps assigned to this entity
- [ ] `POST /entidad/{entidadId}/apps/{appId}` assigns with full pivot metadata
- [ ] `DELETE /entidad/{entidadId}/apps/{appId}` unassigns
- [ ] All 9 endpoints covered by Feature tests in `tests/Feature/API/`
- [ ] Frontend catalog UI renders the seeded apps without code changes
- [ ] Frontend assignment UI can assign/unassign apps to/from entities

---

## Estimated Effort

Following the existing Clean Architecture pattern (Controller → UseCase → Repository) with TDD:

| Component | Estimate |
|-----------|----------|
| `AppController` + Form Requests + Tests | 1 day |
| `AppEntidadController` + Form Requests + Tests | 1 day |
| Use Cases + Repository glue | 0.5 day |
| E2E verification (full Feature test pass + UI smoke test) | 0.5 day |
| **Total** | **~2-3 days** |

---

## Suggested SDD Cycle (when ready to address)

1. **explore** — map the full surface (this debt spec is a starting point)
2. **proposal** — recommend `apps-catalog-and-assignments` as a new change in `Docs/changes/`
3. **specs** — write proper specs (apps catalog CRUD + entity assignment)
4. **design** — Clean Architecture layers + auth/authorization model (who can create apps? super-admin only?)
5. **tasks** — TDD task breakdown per endpoint
6. **apply** — implement via `sdd-apply` sub-agent
7. **verify** — `sdd-verify` against specs
8. **archive** — sync delta specs to main `Docs/openspec/specs/`
