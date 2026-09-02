# Spec: Opportunity Versioning

## Requirements

### R1 — Version columns
The `oportunidad` table MUST have three new columns:
- `parent_id` BIGINT UNSIGNED NULL FK → `oportunidad.id` ON DELETE SET NULL
- `version` INT DEFAULT 0
- `is_latest` BOOLEAN DEFAULT TRUE

Position: after `pipeline_etapa_id`.

### R2 — Codigo version parsing
`OportunidadCsvImportUseCase::parseCodigoVersion(string $codigo): array` MUST:
- Strip trailing ` vN` (case-insensitive, allowing multiple spaces) from codigo
- Return `[$base, $int]` where base is the codigo without the version suffix and int is the version number
- Version 0 (no suffix) is the default; `v1` = 1, `v2` = 2, etc.

### R3 — Post-process after import
`postProcessVersions()` MUST be called at the end of every `import()` invocation in `OportunidadCsvImportUseCase`. It MUST:
- Group all `oportunidad` rows by their base codigo (parsed via `parseCodigoVersion`)
- For each group: identify the row with the max version
- Mark that row: `is_latest=true`, `version=<max>`, `parent_id=NULL`, `estado='Activa'`
- Mark all other rows in the group: `is_latest=false`, `version=<parsed>`, `parent_id=<latest_id>`, `estado='Inactiva'`
- Return `['groups' => int, 'updated_activa' => int, 'updated_inactiva' => int]`

### R4 — Dashboard filter
`GetDashboardUseCase` MUST add `WHERE oportunidad.is_latest = true` to EVERY Oportunidad-derived aggregation query before applying date or commercial filters. The helper `applyActiveVersionFilter($query)` MUST be invoked in:
- `tasa_conversion` total + ganadas
- `oportunidadesPorMes` (12 months loop)
- `oportunidadesMontoPorMes` (sum vr_total, 12 months)
- `ventasMontoPorMes` (sum vr_total ACEPTADAS, 12 months)
- `entidadesConvertidasMes` (12 months)
- `oportunidadesPorEstado` (funnel summary)
- `getVentasData` funnel query
- `baseVentasQuery` (used for ventas_nuevas_mes, ventasPorMes, LTV)
- `getChartData` prospectos + montos (12 months each)

### R5 — Legacy data Artisan command
`php artisan crm:version-opportunities [--dry-run]` MUST:
- In normal mode: call `postProcessVersions()` and report counts
- In dry-run mode: report total oportunidades, currently inactive count, and versioned-codigos count without mutating
- Be idempotent (safe to run multiple times)

### R6 — Scopes
`Oportunidad` model MUST expose two scopes:
- `scopeLatestActiva($query)` — `is_latest=true AND estado='Activa'`
- `scopeLatest($query)` — `is_latest=true` only

### R7 — Factory defaults
`OportunidadFactory` MUST default to `is_latest=true`, `version=0`, `parent_id=null` so newly created opportunities are valid by default.

## Scenarios

### S1 — Single version opportunity
Given an opportunity with codigo `TEST-001` (no suffix)
When `postProcessVersions()` runs
Then that row has `is_latest=true`, `version=0`, `parent_id=null`, `estado='Activa'`

### S2 — Multiple versions of same opportunity
Given three rows with codigos `GC-01-2026-105`, `GC-01-2026-105 v1`, `GC-01-2026-105 v2`
When `postProcessVersions()` runs
Then `v2` is marked `is_latest=true`, `version=2`, `parent_id=null`, `estado='Activa'`
And `v0` and `v1` are marked `is_latest=false`, `parent_id=<v2.id>`, `estado='Inactiva'`

### S3 — Dashboard excludes superseded versions
Given the 3-version scenario above with vr_totals $1.74B / $993M / $596M
When the dashboard queries `sum(detalle_oportunidad.vr_total)` for that month
Then only $596M is summed (the v2 row)

### S4 — Idempotency
Given an opportunity that has already been processed
When `postProcessVersions()` runs again
Then the row's state is unchanged

### S5 — Production migration handling
Given a production DB where the columns already exist (from a previous pipeline migration)
When `php artisan migrate` runs
Then the new migration fails with "Duplicate column"
And the user marks the migration as ran manually via tinker insert into `migrations` table
And subsequent `migrate:status` shows the migration as Ran