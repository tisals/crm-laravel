# Opportunity Versioning

## Intent

Oportunidades are commonly edited over time, creating multiple versions of the same business deal (e.g., `GC-01-2026-105`, `GC-01-2026-105 v1`, `GC-01-2026-105 v2`). Currently, every version is counted in dashboard aggregations, sums, and reports, leading to massive double-counting (e.g., May 2026 shows $3.94B when the actual is $1.21B).

This change introduces a versioning model where each opportunity family has exactly one "active" version (the latest), and all superseded versions are flagged `Inactiva` and excluded from dashboard queries.

## Scope

**In scope:**
- New columns: `parent_id` (FK to self), `version` (int), `is_latest` (bool) on `oportunidad`
- `postProcessVersions()` runs after every CSV import — groups by base codigo, marks max version as Activa + is_latest=true + parent_id=NULL, others as Inactiva + is_latest=false + parent_id=latest_id
- Dashboard queries (11 of them in `GetDashboardUseCase`) apply `is_latest=true` filter
- New Artisan command `crm:version-opportunities` for legacy data
- Model scopes: `Oportunidad::latestActiva()` and `scopeLatest()`
- Unit tests covering the regex parser and post-processing logic

**Out of scope:**
- Detail view of an opportunity (which already filters by id) — no change
- Kanban / list view filtering (only dashboard metrics, which were the visible problem)
- Migration of opportunities that don't follow the `vN` codigo convention (they get version=0 and stay Activa)

## Approach

1. **Migration**: standalone file `2026_07_10_000000_add_versioning_columns_to_oportunidad.php` adding the three columns. The original pipeline migration already created them in some environments (production DB), so the new migration must be idempotent-safe via the migrations table tracking.

2. **Import flow**: `OportunidadCsvImportUseCase::import()` ends with a call to `postProcessVersions()` which re-reads all rows and groups by base codigo (stripping the ` vN` suffix via regex).

3. **Read paths**: `GetDashboardUseCase` uses a helper `applyActiveVersionFilter($query)` that adds `WHERE oportunidad.is_latest = true` to every Oportunidad query before applying date/commercial filters.

4. **Legacy data**: `php artisan crm:version-opportunities` iterates all opportunities and applies the same post-process. Idempotent.

5. **Visualization**: the inactive versions are not hidden in the detail view (they're reachable via the parent/child relationships) but they're excluded from all aggregation paths.