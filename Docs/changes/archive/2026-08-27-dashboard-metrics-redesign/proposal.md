# Proposal: Dashboard Metrics Redesign

## Intent

Solve two bugs in the current dashboard metrics (conversion rate formula wrong, monthly sales showing SUM instead of average), restructure into two meaningful sections (Prospectos for marketing effectiveness, Ventas for sales effectiveness), add LTV indicator, funnel traceability by estado, monthly chart data, and an API endpoint to assign entities to commercial users (salespersons).

## Scope

### In Scope

1. **Bug fixes**: Conversion rate formula (ganadas / total_oportunidades), monthly sales (average of won deals, not SUM).
2. **Dashboard restructure**: Split response into `prospectos` and `ventas` sections with sub-metrics.
3. **Chart data**: Array of 12 months (Jan-Dec) with entity count, contact count, and total sales per month.
4. **LTV**: Lifetime Value = total revenue from won opportunities / distinct entities with at least one won opportunity.
5. **Funnel traceability**: Opportunities grouped by `estado` (Borrador/Enviada/Aceptada/Rechazada/Ganada/Perdida) with count and sum of `vr_total`.
6. **Entity-to-commercial assignment API**: CRUD endpoints on `entidad_usuario` pivot (attach/detach/list usuarios for an entidad, list entidades for a usuario).

### Out of Scope

- Frontend changes (API-only repo — FastAPI consumes this).
- RBAC/permission checks on the assignment endpoint (auth:sanctum only, no rbac middleware).
- Historical LTV trends (just current value).
- Real-time or cached dashboard (still computed on every request).

## Capabilities

### New Capabilities
- `dashboard-metrics`: Replaces the existing dashboard endpoint with restructured sections, chart data, LTV, and funnel traceability.
- `entidad-usuario-assignment`: API to manage many-to-many assignment between usuarios and entidades via the existing `entidad_usuario` pivot table.
- `dashboard-chart-data`: Monthly time-series data (Jan-Dec) combining entity/prospect counts and sales amounts.

### Modified Capabilities
- None (no existing dashboard spec to modify — this is the first).

## Approach

1. **Bug fixes** — Change `GetDashboardUseCase::getKpis()`:
   - Conversion rate: `$ganadas / $totalOportunidades` (not `$ganadas / ($ganadas + $perdidas)`).
   - Monthly sales: replace `SUM(vr_total)` with `AVG(vr_total)` on won opportunities of current month.
2. **Dashboard restructure** — Build a new `GetDashboardUseCase` (or significantly refactor it) returning two top-level keys:
   - `prospectos`: nuevos_leads_mes, conversion_rate, funnel (entity creation per month), entity chart data.
   - `ventas`: LTV, monthly_sales (average), funnel traceability by estado, sales chart data.
3. **Chart data** — New query grouping by month (Jan-Dec) using SQL-compatible month extraction. For each month: count of entities created, count of contacts created, sum of `vr_total` from won opportunities.
4. **LTV** — `DetalleOportunidad::whereHas('oportunidad', fn => estado=Ganada)->sum('vr_total') / Oportunidad::where('estado','Ganada')->distinct('entidad_id')->count('entidad_id')`.
5. **Funnel traceability** — Extend current `getOportunidadesPorEstado()` to include `SUM(detalle_oportunidad.vr_total)` per estado.
6. **Entity-to-commercial assignment** — New `EntidadUsuarioController` with:
   - `GET /entidad/{id}/usuarios` — list assigned usuarios
   - `POST /entidad/{id}/usuarios` — body: `{ usuario_ids: [1,2,3] }` sync without detach
   - `DELETE /entidad/{id}/usuarios/{usuarioId}` — remove assignment
   - `GET /usuarios/{id}/entidades` — list assigned entidades
7. **TDD** — Write tests first for each bug fix, new metrics, and assignment endpoint per existing patterns (`SecurityDashboardTest`, `RefreshDatabase`, Sanctum token).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Application/UseCases/Dashboard/GetDashboardUseCase.php` | Modified | Fix bugs, restructure response, add LTV + chart data + funnel amounts |
| `app/Http/Controllers/API/DashboardController.php` | Modified | May need adjustment if response shape changes significantly |
| `app/Http/Controllers/API/EntidadUsuarioController.php` | New | CRUD for entidad_usuario pivot assignment |
| `routes/api.php` | Modified | Add entidad-usuario and usuario-entidad routes |
| `tests/Feature/API/DashboardTest.php` | New | Tests for dashboard metrics (bugs + new sections) |
| `tests/Feature/API/EntidadUsuarioTest.php` | New | Tests for assignment endpoint |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| **Breaking change**: Frontend integration (FastAPI) relies on current response shape `{ kpi, oportunidades_por_estado, ventas_4_semanas, actividades_recientes }` | High | Coordinate deploy order: deploy backend first, then update FastAPI to consume new shape. Add a transition period if needed. |
| **Performance**: LTV and chart queries scan large tables (3601+ detalles, 2758+ oportunidades) | Low | These are pre-aggregated queries on indexed columns (estado, created_at, entidad_id). Acceptable for single-user dashboard. |
| **SQLite vs MariaDB compatibility**: Month extraction differs between drivers | Medium | Use `DB::raw()` with driver detection (existing pattern in `getVentas4Semanas`). |
| **Division by zero**: LTV when no won opportunities exist | Low | Guard with `if ($uniqueEntities > 0)` — return 0.0. |

## Rollback Plan

- Revert `GetDashboardUseCase.php` to previous version via `git checkout`.
- Delete `EntidadUsuarioController.php` and revert `routes/api.php` changes.
- Delete new test files.
- No migrations involved (no schema changes — `entidad_usuario` already exists).

## Dependencies

- Existing `entidad_usuario` pivot table and Eloquent relationships on `Entidad` and `Usuario` models (already working).
- No new database migrations required.

## Success Criteria

- [ ] Conversion rate shows correct %: `ganadas / total_oportunidades` (e.g., 12/2758 = 0.43%, not 66.7%).
- [ ] Monthly sales shows AVERAGE of won deal amounts, not SUM.
- [ ] Dashboard response returns `{ success, data: { prospectos: {...}, ventas: {...} } }`.
- [ ] `prospectos` contains: nuevos_leads_mes, conversion_rate, entities_per_month (chart), entities_created.
- [ ] `ventas` contains: LTV, monthly_sales (avg), funnel_by_estado (count + total per estado), sales_per_month (chart).
- [ ] LTV = 0.0 when no won opportunities exist (no division by zero).
- [ ] Bar chart data: array of 12 entries (Jan-Dec) with entity_count, contact_count, sales_total.
- [ ] Funnel includes `vr_total` sum per estado.
- [ ] `POST /entidad/{id}/usuarios` assigns usuarios correctly (sync without detach).
- [ ] `GET /entidad/{id}/usuarios` returns assigned usuarios.
- [ ] `DELETE /entidad/{id}/usuarios/{usuarioId}` removes assignment.
- [ ] `GET /usuarios/{id}/entidades` returns assigned entidades.
- [ ] TDD: tests written first, all pass.
- [ ] Existing feature tests not broken (regression check).
