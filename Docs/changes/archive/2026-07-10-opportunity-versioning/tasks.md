# Tasks: Opportunity Versioning

## Implementation

- [x] Create migration `database/migrations/2026_07_10_000000_add_versioning_columns_to_oportunidad.php` with the 3 columns
- [x] Add `scopeLatestActiva()` and `scopeLatest()` to `Modules/CRM/app/Models/Oportunidad.php`
- [x] Add `parseCodigoVersion()` method to `OportunidadCsvImportUseCase`
- [x] Add `postProcessVersions()` method to `OportunidadCsvImportUseCase`
- [x] Call `postProcessVersions()` at the end of `import()` in `OportunidadCsvImportUseCase`
- [x] Add `applyActiveVersionFilter()` helper to `GetDashboardUseCase`
- [x] Apply filter to all 11 Oportunidad queries in `GetDashboardUseCase`
- [x] Update `database/factories/OportunidadFactory.php` defaults
- [x] Create `app/Console/Commands/VersionOpportunities.php` with `--dry-run` support
- [x] Write 8 unit tests in `tests/Unit/Application/UseCases/Oportunidad/OportunidadVersioningTest.php`

## Production Deploy

- [x] Push migration + code to main
- [x] Wait for EasyPanel deploy
- [x] Run `php artisan migrate` (fails as expected — columns exist)
- [x] Mark migration as ran via `php artisan tinker --execute='...'`
- [x] Run `php artisan crm:version-opportunities` to version legacy data
- [x] Run `php artisan optimize:clear` to refresh opcache

## Verification

- [x] All 8 unit tests pass against MariaDB test env
- [x] Pre-existing data versioned: 200 Inactivas / 2030 groups / batch [2] confirmed
- [x] Funnel global: 1,192 opps → 1,097 opps (95 duplicates removed)
- [x] Sample opportunity GC-01-2026-105: $3.33B → $596M (only v2 counted)