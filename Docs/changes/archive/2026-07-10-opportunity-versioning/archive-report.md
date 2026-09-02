# Archive Report — Opportunity Versioning

**Date**: 2026-07-10
**Status**: ✅ Completed and deployed to production

## Summary

Opportunities in the `oportunidad` table now have versioning: the latest version of each codigo family stays `Activa`, superseded versions are marked `Inactiva`, and all dashboard aggregations filter out the inactive ones.

## Impact

- **Before**: 1 opportunity with 3 versions counted 3 times in sums (e.g. GC-01-2026-105: $3.33B)
- **After**: Only the latest version counts (GC-01-2026-105 v2: $596M)
- **Global funnel**: 1,192 opps → 1,097 opps (95 superseded duplicates removed)
- **Funnel amount**: $9.02B → $5.79B ($3.23B double-counting eliminated)

## Files Changed

| File | Change |
|------|--------|
| `database/migrations/2026_07_10_000000_add_versioning_columns_to_oportunidad.php` | NEW — adds parent_id, version, is_latest |
| `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php` | + parseCodigoVersion(), postProcessVersions() |
| `app/Application/UseCases/Dashboard/GetDashboardUseCase.php` | + applyActiveVersionFilter() applied to 11 queries |
| `Modules/CRM/app/Models/Oportunidad.php` | + scopeLatestActiva(), scopeLatest() |
| `app/Console/Commands/VersionOpportunities.php` | NEW — crm:version-opportunities Artisan command |
| `database/factories/OportunidadFactory.php` | defaults updated |
| `tests/Unit/Application/UseCases/Oportunidad/OportunidadVersioningTest.php` | NEW — 8 unit tests |

## Production Status

- Migration: ✅ Ran (batch [2])
- Legacy data: ✅ Versioned (200 Inactivas / 2030 grupos)
- Container: ✅ OpCache cleared
- Frontend: pending user verification of dashboard numbers

## Git Commits

- `9337a6e` fix(migration): separate migration file for versioning columns
- `37000f2` feat: crm:version-opportunities command for production legacy versioning
- `70dd453` feat: versionado de oportunidades (post-process en import, scopes, dashboard filter, tests)

## Notes for Future Agents

1. **Migration pattern**: never add column additions to an already-run migration. Use a new file.
2. **Production DB quirk**: columns existed before the new migration ran. Always check `DESCRIBE oportunidad` in prod before assuming migration is needed.
3. **The post-process is auto-invoked** at the end of `import()`, so future CSV imports don't need manual intervention.
4. **The `crm:reset --force` seeder flow** will trigger post-process automatically through the seeders' import calls.
5. **Dashboard queries must always call `applyActiveVersionFilter()`** — when adding a new Oportunidad query, include it.