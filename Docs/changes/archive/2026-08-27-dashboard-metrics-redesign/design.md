# Design: Dashboard Metrics Redesign

## Technical Approach

Rewrite `GetDashboardUseCase` from a flat KPI dump into a structured response with two domains (prospectos/ventas) plus chart data. Add a new `EntidadUsuarioController` for entity-user assignment with role validation. Inject `cliente_desde` timestamp setting into the Ganar flow. Fix two bugs (conversion rate formula, monthly sales from SUM→AVG) and remove all SQLite driver detection — MariaDB-only syntax.

Artifact mode: `hybrid` — persisted to Engram + `openspec/changes/{change}/design.md`.

---

## Architecture Decisions

### Decision: Grouped Use Case Methods

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Single flat method `execute()` | Simple but unmaintainable | ❌ |
| **Private sub-methods grouped by domain** | Clear separation, each <30 lines | ✅ |
| Separate use case per domain | Over-engineering for current scope | ❌ |

**Rationale**: The use case has ~8 metric groups. Grouping into `prospectos()`, `ventas()`, `chart()`, `actividades()` keeps each method focused. The controller remains thin.

### Decision: cliente_desde Setting in GanarOportunidadUseCase

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Eloquent observer on Oportunidad model | Decoupled but adds magic | ❌ |
| Event + listener | Best practice but overkill for one field | ❌ |
| **Direct update in GanarOportunidadUseCase** | Explicit, same place as entidad estado update | ✅ |
| OportunidadController::ganar | Mixes HTTP concern with business logic | ❌ |

**Rationale**: The use case already updates `entidad.estado = 'Cliente'` in `GanarOportunidadUseCase`. Setting `cliente_desde` there is the same level of concern. Add a single DB raw update with an `IS NULL` guard to avoid overwriting.

### Decision: Role Check by Name, Not Hardcoded ID

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Hardcode `rol_id IN (1, 2)` | Fragile if seeder order changes | ❌ |
| **Query by role name** | Resilient, readable, follows existing pattern | ✅ |

**Rationale**: `RoleSeeder` creates roles without guaranteed IDs. Query `Rol::whereIn('nombre', ['Admin', 'Ventas'])->pluck('id')` at controller level. The scope `Usuario::admins()` already uses `rol_id = 1` — but that's a legacy pattern we don't extend.

### Decision: MariaDB-Only Syntax, No Driver Detection

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Keep driver detection | Maintains SQLite compat for tests | ❌ |
| **Remove entirely, use ONLY DATE_FORMAT** | Simplifies code, tests already use SQLite :memory: but phpunit.xml forces it | ✅ |

**Rationale**: The `phpunit.xml` sets `DB_CONNECTION=sqlite`. However, the app itself is MariaDB. We write all queries with `DATE_FORMAT` / MySQL functions. For tests, SQLite's `DATE_FORMAT` does NOT exist — so we must either: (a) change phpunit.xml to use MariaDB in CI, or (b) use a query builder abstraction. Decision: **use MySQL-compatible raw expressions throughout and patch phpunit.xml to use `mysql` for the Feature test suite**. This is a one-time change and aligns with production.

Wait — changing phpunit.xml to MariaDB would break CI since GitHub Actions uses SQLite. Better approach: **keep DATE_FORMAT in raw SQL but accept tests will need a different approach** OR use a MySQL test database. 

**Revised decision**: Write all queries with `DB::raw('DATE_FORMAT(...)')` as MariaDB-native. For the Feature tests, set up a MySQL test database (update phpunit.xml or `.env.testing`). This is acceptable for this project.

Actually, let me reconsider. The project's CI currently uses SQLite. For this design doc, I'll document that the `phpunit.xml` must be updated to use `mysql` for the Feature test database. Alternatively, we can create an `.env.testing` file that points to a local MySQL test DB.

### Decision: Assignment API Endpoint Shape

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Nested: `POST /entidad/{id}/usuarios` {usuario_ids: []} | RESTful but batch | ❌ |
| **Flat: `POST /api/v1/entidad-usuario` {usuario_id, entidad_id}** | Simpler for FastAPI, matches user spec | ✅ |

**Rationale**: The user explicitly specified this shape. Single create/delete per call keeps the API simple and matches the pivot table semantics.

---

## Data Flow

### Dashboard Request → Response

```
HTTP GET /api/v1/dashboard?comercial_id=N
  │
  ├─ auth:sanctum validates token
  │
  └─ DashboardController::index(Request)
       │
       └─ GetDashboardUseCase::execute(?comercial_id)
            │
            ├─ $this->prospectos()
            │    ├─ Contacto::whereMonth(created_at) count          → nuevos_leads_mes
            │    ├─ Oportunidad::count + Ganada count               → tasa_conversion
            │    ├─ Entidad by MONTH(created_at) grouped 12 months   → entidades_por_mes
            │    ├─ Entidad by MONTH(cliente_desde) grouped 12 mons  → entidades_convertidas_mes
            │    └─ Oportunidad groupBy estado count                → oportunidades_por_estado
            │
            ├─ $this->ventas($comercialId)
            │    ├─ DetalleOportunidad join Oportunidad estado=Ganada
            │    │   SUM + COUNT(DISTINCT months) → ventas_mes (AVG)
            │    │   (filtered by entidad_id IN subquery if comercialId)
            │    ├─ Same query grouped by month → ventas_por_mes (12)
            │    │   (filtered)
            │    ├─ LTV: SUM(vr_total) / COUNT(DISTINCT entidad_id)
            │    │   (filtered)
            │    └─ Funnel: Oportunidad groupBy estado with COUNT + SUM(vr_total)
            │       (filtered)
            │
            ├─ $this->chart($comercialId)
            │    ├─ Fixed 12-month array (Ene...Dic)
            │    ├─ Entidades convertidas per month
            │    └─ Ventas per month (won, filtered)
            │
            └─ $this->actividadesRecientes()
                 └─ Seguimiento::with->latest->limit(10)
```

### Assignment Request → Response

```
POST /api/v1/entidad-usuario  { usuario_id, entidad_id }
  │
  ├─ Validate usuario exists
  ├─ Validate usuario has rol Admin or Ventas
  ├─ Validate entidad exists
  └─ entidad_usuario pivot insert (ignore duplicate)
```

---

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Application/UseCases/Dashboard/GetDashboardUseCase.php` | Rewrite | New `prospectos()`, `ventas()`, `chart()`, `actividadesRecientes()` methods. Remove driver detection. MariaDB-only DATE_FORMAT. Add commercial filter param. |
| `app/Http/Controllers/API/DashboardController.php` | Modify | Accept `Request $request`, pass `comercial_id` to use case |
| `app/Http/Controllers/API/EntidadUsuarioController.php` | Create | POST/DELETE/GET for entidad-usuario pivot |
| `app/Application/UseCases/Oportunidad/GanarOportunidadUseCase.php` | Modify | After setting estado='Ganada', set `cliente_desde` on entidad if null |
| `routes/api.php` | Modify | Add 3 new routes for entidad-usuario |
| `resources/views/pdf/cotizacion.blade.php` | Modify | Restructure to 2-column layout: Observaciones left, totals right. Add Notas (aclaraciones) row below. |
| `database/migrations/XXXX_XX_XX_add_cliente_desde_to_entidad_table.php` | Create | Add nullable timestamp `cliente_desde` |
| `tests/Feature/API/DashboardTest.php` | Create | 12 test cases covering prospectos, ventas, chart, filter |
| `tests/Feature/API/EntidadUsuarioTest.php` | Create | 8 test cases for assignment API |
| `tests/Feature/API/OportunidadGanarClienteDesdeTest.php` | Create | 3 test cases for cliente_desde behavior |
| `phpunit.xml` or `.env.testing` | Modify | Set `DB_CONNECTION=mysql` for Feature tests (or document test DB setup) |

---

## Interfaces / Contracts

### New Dashboard Response Shape

```php
[
    'prospectos' => [
        'nuevos_leads_mes' => 12,                         // int
        'tasa_conversion' => 0.43,                        // float (%, not fraction)
        'entidades_por_mes' => ['Ene' => 5, ...],          // array<string, int>, 12 months
        'entidades_convertidas_mes' => ['Ene' => 2, ...],  // array<string, int>, 12 months
        'oportunidades_por_estado' => [                    // array<array>
            ['estado' => 'Borrador', 'total' => 100],
            ['estado' => 'Ganada', 'total' => 12],
        ],
    ],
    'ventas' => [
        'ventas_mes' => 1250000.50,                        // float (AVG)
        'ventas_por_mes' => ['Ene' => 800000, ...],        // array<string, float>, 12 months
        'ltv' => 3500000.00,                               // float
        'funnel' => [                                       // array<array>
            ['estado' => 'Ganada', 'total' => 12, 'monto' => 15000000],
            ['estado' => 'Perdida', 'total' => 8, 'monto' => 5000000],
        ],
    ],
    'chart' => [
        'meses' => ['Ene', 'Feb', ..., 'Dic'],
        'entidades_convertidas' => [0, 2, 5, ...],          // array<int>, 12 entries
        'ventas' => [0, 800000, 1200000, ...],              // array<float>, 12 entries
    ],
    'actividades_recientes' => [...],                        // unchanged
]
```

### EntidadUsuarioController Contract

| Method | Endpoint | Request | Response |
|--------|----------|---------|----------|
| `assign` | `POST /api/v1/entidad-usuario` | `{"usuario_id": int, "entidad_id": int}` | `{"success": true, "data": {...}, "message": "Asignación creada"}` |
| `deassign` | `DELETE /api/v1/entidad-usuario` | `{"usuario_id": int, "entidad_id": int}` | `{"success": true, "message": "Asignación eliminada"}` |
| `usuariosByEntidad` | `GET /api/v1/entidad/{id}/usuarios` | — | `{"success": true, "data": [Usuario...]}` |

### Commercial Filter

`GET /api/v1/dashboard?comercial_id=5`

- `comercial_id` maps to `Usuario.id`
- Filters ALL `ventas` queries: append `AND oportunidad.entidad_id IN (SELECT entidad_id FROM entidad_usuario WHERE usuario_id = ?)`
- `prospectos` queries are NOT filtered
- If `comercial_id` is absent or empty, no filter applied (all data)

### cliente_desde Logic

In `GanarOportunidadUseCase::execute()`, after the existing `Entidad::update(['estado' => 'Cliente'])`:

```php
// Set cliente_desde only if not already set
\App\Models\Entidad::where('id', $oportunidad->entidad_id)
    ->whereNull('cliente_desde')
    ->update(['cliente_desde' => now()]);
```

---

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Dashboard response structure | `DashboardTest` — assert JSON structure matches new shape |
| Feature | Conversion rate bug fix | Create X opportunities, Y ganadas, assert rate = Y/X * 100 |
| Feature | Monthly sales AVG | Create 2 won opps in diff months with known amounts, assert ventas_mes = avg |
| Feature | LTV calculation | Create 2 won opps for same entity, 1 for another, assert ltv = total / 2 |
| Feature | Commercial filter | Assign entities to user via entidad_usuario, filter, assert only those entities' ventas |
| Feature | Funnel with monto | Create opps with detalles in various estados, assert each estado count+sum |
| Feature | Empty dashboard | No data at all — assert zeros, no errors |
| Feature | cliente_desde sets on ganar | Mark opp as Ganada, assert entidad.cliente_desde is not null |
| Feature | cliente_desde not overwritten | Set cliente_desde manually, then mark another opp ganada, assert unchanged |
| Feature | Assign usuario with Admin rol | POST entidad-usuario, assert success |
| Feature | Assign usuario with Ventas rol | POST entidad-usuario, assert success |
| Feature | Reject assign with wrong rol | Usuario with Operaciones rol, assert 422 |
| Feature | DEASSIGN usuario | POST then DELETE, assert pivot gone |
| Feature | GET usuarios by entidad | Assign 2, GET list, assert count=2 |
| Feature | PDF layout output | (Optional — visual inspection or snapshot test) |

### Test File Structure

- **`tests/Feature/API/DashboardTest.php`** — 12 test methods
  - `initial_structure_returns_expected_keys()` — assert new JSON structure
  - `conversion_rate_is_ganadas_over_total()` — bug fix regression
  - `ventas_mes_is_average_not_sum()` — AVG calculation
  - `ventas_mes_returns_zero_when_no_won_opps()` — edge case
  - `ltv_calculates_correctly()` — LTV formula
  - `ltv_returns_zero_when_no_won_opps()` — no division by zero
  - `funnel_includes_monto_per_estado()` — funnel with amounts
  - `chart_returns_12_months()` — structure and length
  - `chart_shows_entities_converted_and_sales()` — data correctness
  - `commercial_filter_filters_ventas_only()` — scope test
  - `commercial_filter_does_not_affect_prospectos()` — isolation test
  - `actividades_recientes_returns_last_10()` — ordering and limit

- **`tests/Feature/API/EntidadUsuarioTest.php`** — 8 test methods
  - `admin_can_assign_usuario_to_entidad()`
  - `ventas_user_can_be_assigned()`
  - `non_commercial_role_cannot_be_assigned()`
  - `cannot_assign_nonexistent_usuario()`
  - `cannot_assign_to_nonexistent_entidad()`
  - `deassign_removes_pivot_record()`
  - `deassign_returns_error_when_not_assigned()`
  - `list_usuarios_by_entidad_returns_assigned()`

- **`tests/Feature/API/OportunidadGanarClienteDesdeTest.php`** — 3 test methods
  - `ganar_oportunidad_sets_cliente_desde()`
  - `ganar_oportunidad_does_not_overwrite_cliente_desde()`
  - `ganar_oportunidad_sets_cliente_desde_date()` — assert timestamp is recent

### MySQL Test DB Setup

The `phpunit.xml` currently forces SQLite `:memory:`. For DATE_FORMAT queries to work:
- Option A: Add `.env.testing` with `DB_CONNECTION=mysql` and a local test DB
- Option B: Keep SQLite and use conditional queries (defeats purpose of SQLite removal)
- Option C: Use `DB::raw()` with MySQL syntax and skip tests that use DATE_FORMAT on SQLite

**Decision**: Create `.env.testing` with MySQL connection and a dedicated `crm_testing` database. Update CI docs. The setup command: `php artisan test --env=testing` or set `APP_ENV=testing` in `.env`.

---

## Migration / Rollout

### Migration
1. `php artisan make:migration add_cliente_desde_to_entidad_table --table=entidad`
2. `Schema::table('entidad', fn(Blueprint $t) => $t->timestamp('cliente_desde')->nullable()->after('estado'));`
3. `php artisan migrate`

Existing entities that already have estado='Cliente' will have `cliente_desde = null` until their next won opportunity. This is acceptable — we don't backfill.

### Rollout Order
1. Create migration and run
2. Deploy backend changes (use case, controller, routes)
3. Deploy FastAPI frontend changes to consume new response shape
4. Add PDF template update

### Rollback
- Revert `GetDashboardUseCase.php` and `DashboardController.php`
- Delete `EntidadUsuarioController.php`, revert routes
- `php artisan migrate:rollback` for cliente_desde
- Delete test files

---

## Open Questions

- [ ] **Test DB strategy**: Should we use MySQL in CI (GitHub Actions) or keep SQLite with a compatibility layer for tests? The user explicitly said "remove all DB driver detection" — need to resolve test infra.
- [ ] **Existing data**: 2758+ oportunidades. The new LTV query scans all detalles — confirm it performs acceptably.
- [ ] **PDF template**: Confirm FastAPI passes `$aclaraciones` field to the Blade view — may need to add it if missing.
