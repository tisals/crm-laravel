# Verify Report: multi-app-access

## Summary

- **Verification date**: 2026-07-31
- **Verdict**: FAIL
- **Verification mode**: Strict TDD
- **Test results**: 592 tests, 1,837 assertions, 31 non-passing (19 errors + 12 failures); 561 passed
- **Spec coverage**: 129 of 217 scenarios have direct passing test evidence (59%); this is scenario coverage, not line coverage
- **In-scope multi-app tests**: 144 tests, 633 assertions, all passing
- **Token-exchange extension tests**: 59 tests, 185 assertions, all passing

The core multi-app test files pass in isolation, including login, validate-token, `/me`, assignments, migrations, seeders, backfill, and the E2E flow. Verification nevertheless fails because several mandatory contract requirements are not implemented: the login and `/me` payloads omit `nombres`/`apellidos`, `personas.identificacion_numero` is not unique, inactive app access returns the wrong status, and the default `DatabaseSeeder` does not wire the new seeders. The backfill command also reports incorrect counters and never reports skipped soft-deleted rows. The full suite does not exit successfully, although the observed failures are concentrated in pre-existing pipeline, city-fixture, and legacy integration tests rather than the isolated multi-app tests. The implementation is therefore not ready for archive.

## Test Execution

### Full suite

- **Requested command**: `composer test`
- **Result**: Composer's 300-second process timeout was exceeded before the script completed.
- **Completed equivalent**: `vendor/bin/phpunit --no-progress`
- **Result**: 592 tests, 1,837 assertions, 561 passed, 19 errors, 12 failures.
- **Exit status**: non-zero.
- **Warnings/notices**: no PHPUnit warning or notice summary was emitted; the wrapper timeout is itself a verification issue.

Non-passing tests are unrelated to the multi-app test files and include:

- `EloquentPipelineRepositoryTest` — duplicate `COTIZACION` pipeline fixture.
- `SendPipelineChangeToN8nTest` — two payload assertions receive `null`.
- `EntidadUsuarioTest` — seven tests fail because the factory uses nonexistent city `05001`.
- `LicenseIntegrationTest` — role FK fixture failure.
- `OportunidadClienteDesdeTest` — three city FK fixture failures.
- `SailusIntegrationTest::validate_key_returns_200_with_valid_key` — role FK fixture failure.
- `PipelineEtapaChangedDispatchTest` — listener reads `nombre` from a string.
- `PipelineEtapaMigrationTest` — four duplicate `COTIZACION` errors.
- `BulkMoveOportunidadesTest` — one failure.
- `DashboardTest` — six failures.
- `OportunidadControllerTest` — two failures.
- `OportunidadGanarTest` — one failure.
- `CotizacionControllerTest` — one error.

### Unit tests

- **Requested command**: `composer test --filter=Unit`
- **Observed behavior**: the Composer script does not forward that option to `artisan test`; it started the broad suite and hit the Composer process timeout.
- **Equivalent executed command**: `vendor/bin/phpunit tests/Unit --no-progress`
- **Result**: 165 tests, 406 assertions, 162 passed, 1 error, 2 failures.
- **Failures**: the three known legacy tests in `EloquentPipelineRepositoryTest` and `SendPipelineChangeToN8nTest`.

### Migration tests

- **Requested command**: `composer test --filter=Migration`
- **Equivalent executed command**: `vendor/bin/phpunit tests/Feature/Migration --no-progress`
- **Result**: 55 tests, 139 assertions, 51 passed, 4 errors.
- **Errors**: all four are `PipelineEtapaMigrationTest` duplicate `COTIZACION` fixture errors; the multi-app migration tests pass.

### Targeted change tests

- **Command**: `vendor/bin/phpunit` with the 19 multi-app migration, console, seeder, auth, `/me`, assignment, E2E, resolver, repository, and model files.
- **Result**: 144 tests, 633 assertions, all passing.
- **Token-exchange extension**: 59 tests, 185 assertions, all passing.
- **Build/type/coverage**: no build, type-checker, linter, or coverage tool is configured in `openspec/config.yaml`; coverage was not available. No application build was run, consistent with repository instructions.

## Spec Coverage

The following is a conservative manual scenario mapping. A scenario is counted only when a passing test directly exercises the behavior; static code alone is not counted. Compound assertions in one test are not inflated into full coverage of every prose clause.

| Spec file | Scenarios | Tested | Coverage | Notes |
|---|---:|---:|---:|---|
| pre-migrations | 26 | 18 | 69% | Schema, rollback, FK, backfill happy paths pass; missing fresh/existing-volume ordering, missing-table, output-counter, and full idempotency cases remain unproven. |
| apps | 21 | 17 | 81% | Table, defaults, seeder idempotency, model relationships, and inactive filtering are covered; DatabaseSeeder wiring and optional admin visibility are not. |
| usuario-app-assignments | 40 | 34 | 85% | Pivot constraints, canonical seed, admin CRUD, validation, authorization, and idempotency are covered; soft-deleted seed user, dynamic role-ID, reassign-after-revoke, and full bypass matrix are not. |
| personas-party-model | 29 | 21 | 72% | Schema, relationships, dedup, soft-delete filtering, null-email fallback, and apply/dry-run paths are covered; uniqueness, independent Usuario behavior, migration-failure safety, and 100/80-volume cases are not. |
| auth-validate-token | 30 | 19 | 63% | Four canonical users, cache key, HIT/MISS simulation, invalid tokens, X-API-Key separation, and inactive filtering pass; actual TTL passage, rate limit, live permission union at endpoint, stale-cache revoke behavior, and SAIlus regression are not fully proven. |
| auth-login-modified | 29 | 7 | 24% | Four app-list cases and invalid credential no-app leakage pass; required names shape, inactive user, validation, repeated devices, throttle, immediate `/me`, form encoding, generic errors, and CORS are not covered. |
| me-endpoints | 42 | 13 | 31% | Basic `/me`, `/me/apps`, permissions, 401/403/404, and inactive-list filtering pass; inactive users, no-pivot super-admin, sensitive-field contract, route metadata, no-write idempotency, and middleware edge cases are not. |
| **Total** | **217** | **129** | **59%** | Scenario coverage is below archive quality and is not equivalent to code coverage. |

## Design Compliance

- [ ] **Schema matches design** — the `personas.identificacion_numero` index is plain, not UNIQUE; the design explicitly accepts this deviation, but the specs require duplicate non-NULL values to be rejected. The design's data migration `2026_07_30_080500_backfill_personas_from_contacto_data.php` is absent.
- [~] **Endpoints match design** — paths and primary response envelopes are present, but login and `/me` use legacy `nombre` only; duplicate assignment returns 200 instead of the design/spec 409; the inactive-app permission path returns 404 instead of the spec's 403.
- [~] **Middleware matches design** — `has-app` is registered and Bearer/API-key separation is correct, but inactive apps are treated as not-found and the middleware always bypasses for super-admin, even when the resolver intentionally treats explicit pivot rows as restrictive.
- [~] **Seeders match design** — the three module seeders are idempotent and produce 11 rows in targeted/E2E tests, but `database/seeders/DatabaseSeeder.php` does not call `SharedDatabaseSeeder` or the three new seeders.
- [x] **Cache keys consistent** — `ValidateTokenUseCase::cacheKeyFor()` and tests use `auth:validate_token:{sha256(token)}` with a 300-second TTL.
- [x] **X-API-Key vs Bearer separation correct** — `/auth/validate-key` remains behind `ValidateApiKeyMiddleware`; `/auth/validate-token` is public/self-validating and rejects X-API-Key-only requests with 400.
- [ ] **Generic error messages** — unknown/wrong credentials use a generic message, but inactive users receive `Usuario inactivo.` from `LoginUseCase`, exposing account state.

## Acceptance Criteria

1. **Pre-migrations on fresh and existing-data DB** — **✗ Partial**. The five structural migrations pass targeted tests, but no verification run was performed against a fresh DB plus the stated 2,828-contact dataset. There is no `080500` data migration, and the backfill command is manual.
2. **`GET /auth/validate-token` shape for the four canonical cases** — **✓ for targeted behavior**. The four canonical app cases and invalid-token paths pass in `ValidateTokenTest`/E2E. Permission-union and live production-data behavior remain under-tested.
3. **`GET /me/apps` for four canonical users** — **✓ for targeted behavior**. Login/`/me/apps`/validate-token E2E passes for admin, Lorena, Patricia, and Jaime using the assignment seeder.
4. **Cache MISS/HIT/TTL behavior** — **✓ Partial**. First MISS, second HIT, stable `validated_at`, separate hashes, and a flush-simulated expiry pass. A real clock-advanced 301-second expiry test was not executed.
5. **Four seeder cases and fresh-seed assignments** — **✗ Partial**. The standalone seeders and E2E produce the expected 6 apps and 11 pivot rows, but top-level `migrate:fresh --seed` does not invoke them because `DatabaseSeeder` is not wired.
6. **All tests pass** — **✗**. The full suite completed through direct PHPUnit with 19 errors and 12 failures; the Composer command itself timed out.
7. **No auth regression** — **⚠ Partial**. Existing `AuthTest` passes and route separation is structurally intact. The isolated SAIlus regression test still errors on an unrelated role FK fixture, so this criterion is not fully demonstrated in the current environment.

## Findings

### CRITICAL

- **`database/migrations/2026_07_30_080100_create_personas_table.php:25-33`** — `identificacion_numero` has only a non-unique index. The specs require duplicate non-NULL identification numbers to fail; the current database accepts duplicates. **Fix:** add a regular UNIQUE index on the nullable column (MariaDB permits multiple NULL values), or implement an equivalent generated-column unique constraint.

- **`app/Application/DTOs/LoginResponse.php:22-29` and `app/Application/UseCases/Me/GetMeUseCase.php:58-69`** — successful login and `/me` responses expose `nombre`, not the required `nombres` and `apellidos`. This violates the login contract and prevents consumers from using the documented shape. **Fix:** map the legacy single `nombre` field into the documented `nombres`/`apellidos` contract, with the agreed empty/null fallback, and add contract assertions.

- **`app/Application/UseCases/Me/GetMeUseCase.php:36-41` / `routes/api.php:104-116`** — `/me` authenticates through Sanctum but does not reject a token whose user has `estado = 'Inactivo'`; the use case has no status guard. **Fix:** apply the same active-user check used by validate-token before returning profile data, and add an expired/inactive feature test.

- **`app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php:57-65`** — a known inactive app returns `404 app_not_found`, but `REQ-ME-4` requires `403` for an inactive assigned app; only an unknown slug should be 404. **Fix:** query existence separately from active state and return 403 for an existing but inactive app.

- **`app/Application/UseCases/UsuarioApp/AssignUserToAppUseCase.php:42-46` and `app/Http/Controllers/API/UsuarioAppController.php:75-91`** — duplicate assignment returns 200 idempotently, while `REQ-USRAPP-4` and `design.md §4.5` specify 409 `assignment_already_exists`. The deviation is documented in `apply-progress.md`, but it is still a contract mismatch. **Fix:** either restore 409 behavior or formally update the spec/design before archive; do not leave implementation and contract divergent.

- **`database/seeders/DatabaseSeeder.php:9-21`** — the default fresh-install seed chain does not call `SharedDatabaseSeeder`, `AppsSeeder`, `BrpRolesSeeder`, or `UsuarioAppAssignmentsSeeder`. A production `migrate:fresh --seed` therefore does not produce the required catalog and assignments. **Fix:** wire the shared seed chain after the canonical roles/users are available and add a fresh-seed integration test.

- **`app/Console/Commands/BackfillPersonasFromContacto.php:41-44,65-69,91-99`** — the command filters soft-deleted contacts out before counting, so `$skipped` is always zero; dry-run counts one persona per contact rather than unique email/name identity; and a second apply run still reports every already-linked contact as updated instead of zero updates. These violate the required dry-run, skip reporting, and idempotency observability contract. **Fix:** count the full candidate set separately, deduplicate before counting, increment skipped for deleted rows, and count only actual writes.

- **`app/Infrastructure/Auth/EnsureUserHasAppMiddleware.php:68-76` vs `app/Application/Services/UserAppsResolver.php:14-21,40-64`** — the resolver adopts the documented pivot-wins policy for Jaime, but middleware grants every active app to any super-admin regardless of explicit pivot restrictions. A super-admin with only CRM/Marketing in the resolver can still access BRP permissions directly. **Fix:** choose one authorization policy and apply it consistently; under the current pivot-wins decision, middleware must check explicit pivot rows when present and only use the bypass when no pivot rows exist.

- **`app/Application/UseCases/Auth/LoginUseCase.php:21-31`** — inactive credentials return `Usuario inactivo.` while unknown and wrong credentials return `Credenciales inválidas.`, allowing account-state enumeration. **Fix:** return one generic 401 message for unknown, wrong, and inactive credentials, and add assertions comparing the responses.

- **Full suite execution** — `composer test` does not exit successfully; the completed equivalent reports 19 errors and 12 failures. These failures are concentrated in pre-existing pipeline/city/fixture tests and were not reproduced in the 144-test multi-app subset, but the repository-level acceptance criterion remains unmet until the baseline is either repaired or explicitly quarantined in CI. **Fix:** separate baseline failures in CI or repair the fixtures before claiming a passing full suite.

### WARNING

- **`database/migrations/2026_07_30_080000_add_slug_and_es_super_admin_to_roles_table.php:35-38`** — implementation marks super-admin by `LOWER(nombre) = 'superadmin'` rather than the design's canonical `id = 1`. This is a reasonable test-resilience deviation, but it should be recorded as the authoritative production rule and tested for duplicate/renamed role edge cases.

- **`openspec/changes/multi-app-access/apply-progress.md:3-91`** — PM/SCH tasks have no `TDD Cycle Evidence` table, despite Strict TDD being active. Later batches provide evidence, but the evidence trail is incomplete for the first seven tasks. Add RED/GREEN/triangulation/safety-net evidence for PM-1 through SCH-3 or mark those tasks as unverifiable.

- **`tests/Feature/Console/BackfillPersonasFromContactoTest.php:141-156`** — `command_returns_failure_if_personas_table_missing` never removes the table and explicitly tests the success path. It does not prove the required missing-pre-migration failure behavior. Replace it with a real isolated missing-table test.

- **Design/file layout drift** — `design.md` names `TokenCacheService`, `SuperAdminGuard`, `AppAssignmentDto`, response resources, and root `database/seeders/*` locations; implementation uses inline cache calls, inline admin guards, arrays, module seeders, and no resources. Behavior is partially covered, but either the design must be updated or the named architectural boundaries should be restored.

- **`ValidateTokenTest` cache expiry** — `Cache::flush()` simulates expiry rather than advancing time through the configured 300-second TTL. Add a time-controlled expiry test to prove a real MISS and a new `validated_at` after TTL.

- **Stable ordering** — `UserAppsResolver` orders super-admin catalog results by slug but does not explicitly order pivot results. Add `orderBy` to guarantee login, `/me/apps`, and validate-token arrays remain stable across database plans.

### SUGGESTION

- Add a generated, reviewable scenario matrix linking each of the 217 Given/When/Then scenarios to a test method and result; the current test comments claim broader coverage than runtime evidence demonstrates.

- Split the token-exchange extension into its own OpenSpec change or add its acceptance/design/spec artifacts to this change explicitly. It currently adds 59 passing tests and multiple production files beyond the original seven specs.

- Add a targeted `DatabaseSeeder` smoke test that asserts the six apps, three BRP roles, and 11 assignments after `migrate:fresh --seed`, rather than relying on manually invoking module seeders.

- Add assertion checks for sensitive fields (`password_hash`, token collections) and names to the login and `/me` feature tests; current tests assert the legacy `nombre` shape and can remain green while the documented contract is broken.

## TDD Compliance

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ⚠️ Partial | Evidence tables exist for SE, E, T, V, and TE batches; PM/SCH batch lacks the required table. |
| All implementation tasks have tests | ✅/⚠️ | 28 tasks are marked complete and related tests exist, but several spec scenarios have no runtime test. |
| RED confirmed | ⚠️ | Later task rows report RED and files exist; first seven tasks have no per-task RED evidence. |
| GREEN confirmed | ✅ for changed subset | 144 multi-app tests and 59 token-exchange tests pass; full suite does not. |
| Triangulation adequate | ⚠️ | Good canonical-user triangulation, but login and `/me` edge cases are substantially under-tested. |
| Safety net for modified files | ⚠️ | Reported for later endpoint batches; not demonstrable for every PM/SCH file from the artifact. |

**TDD Compliance**: partial; not archive-ready.

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|---|---:|---:|---|
| Unit | 165 | Unit suite | PHPUnit 11.5.55 |
| Integration/Feature | 427 | Feature suite | PHPUnit 11.5.55 |
| E2E-style feature | 1 included in Feature | 1 | PHPUnit HTTP kernel; no browser tool |
| **Total** | **592** | — | — |

The targeted change suite is integration-heavy and provides useful endpoint evidence. No browser E2E tool is configured; `MultiAppAccessFlowTest` is an in-process HTTP feature smoke test, not a separate browser E2E layer.

## Changed File Coverage

Coverage analysis skipped — `openspec/config.yaml` declares coverage unavailable and no coverage driver/report was detected.

## Assertion Quality

**Assertion quality**: no tautologies, ghost loops, or assertion-only tests were found in the reviewed multi-app test files. Empty-array assertions have companion non-empty cases. The missing-table backfill test is a **scenario coverage defect** (reported above), not a trivial assertion violation.

## Verdict

**FAIL.** The isolated multi-app behavior is substantially implemented and its focused tests are green, but mandatory schema, response-contract, authorization, seeder-wiring, backfill-accounting, and security requirements remain unmet. The full repository suite also does not pass. Critical findings must be resolved and the missing scenario tests rerun before this change can be archived.

## Recommendation

- **If FAIL**: address the CRITICAL findings before archive; update the specs/design where the documented contract is intentionally changing, then rerun the full suite and the missing scenario matrix.

## Artifacts

- `openspec/changes/multi-app-access/verify-report.md`
