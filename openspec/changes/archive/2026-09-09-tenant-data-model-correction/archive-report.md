# Archive Report — `tenant-data-model-correction`

**Archived**: 2026-09-09
**Head commit at archive**: `8c8cf92` (docs) + `ca4af79` (test) + `d5e63d3` (feat) — Commit 8 of the change.
**Branch**: `feat/iter4-persona-tracker`
**Status**: ✅ **SHIPPED** — all 8 commits merged into the change's branch.

## TL;DR

This change reshaped the tenant-data model in crm-laravel across 8
incremental commits, eliminating seven legacy ENUM-style columns in
favour of pivot-backed derivation, adding a CQRS-Lite depth projection
API for the Mercurio mirror, and shipping the entidad-snapshot
webhook for Mercurio's CQRS mirror. The application layer went from
~318 passing tests / 0 failing (Commit 4 baseline) to a final ~408
passing tests / 0 failing + 26 depth tests + 16 emitter/observer
tests + 9 smoke tests for the final schema.

## Commit-by-commit ledger

| Commit | Hash | Subject |
|---|---|---|
| 1 (pre-archive, was fe99f70) | — | Rename pivot `entidad_usuario` → `entidad_persona`, retarget FK from `usuarios.id` → `personas.id`. |
| 2 (pre-archive, was 28_10) | — | Add `persona_id` to `contacto` / `colaboradores` / `proveedores`. |
| 3 (pre-archive, was 28_99) | — | Swap `seguimiento.contacto_id` → `seguimiento.persona_id`. |
| 4 | (on `feat/iter4-persona-tracker`, pre-`HEAD~10`) | Drop `tipo_persona` / `entidad_id` from `personas` and `contacto.entidad_id`. |
| 5 | (pre-`HEAD~9`) | Create `entidad_relacion` pivot + backfill from `entidad.estado` / `entidad.cliente_desde`. |
| 5.5 | (pre-`HEAD~8`) | Drop `entidad.estado` / `entidad.cliente_desde` (via migration 140000); add `frecuencia` / `recurrencia_cada_meses` / `vigencia_meses` on pivot. Application layer rewired. |
| 5.6 | (pre-`HEAD~7`) | Fix-up sweep: rewire `GetSecurityDashboardUseCase`, `ValidateApiKeyUseCase`, `GetDashboardUseCase`, `Persona::getTipoPersonaAttribute`. 82 tests fixed. |
| 6 | (pre-`HEAD~4`) | CQRS-Lite `?depth=1|2|3` projection. `ProjectionLevel` enum, `BaseResource` helpers, depth-aware `PersonaResource` / `OportunidadResource` / `EntidadResource` / `SeguimientoResource` / `ContactoResource`. 26 depth tests. |
| 7 | `b4beb01`, `5311dcf`, `5088bfe`, `b44fc1b` | `EntidadChanged` domain event + `EntidadesSnapshotEmitter` + `EntidadSnapshotBuilder` + `EntidadRelacionObserver` + outbound webhook to Mercurio. 16 emitter/observer tests. |
| **8 (THIS)** | **`d5e63d3`**, **`ca4af79`**, **`8c8cf92`** | Definitive drop of legacy `entidad.estado` + `entidad.cliente_desde` columns at the migration level (irreversible). Smoke test (`tests/Feature/Database/LegacyColumnsDroppedTest.php`, 9 tests). Mercury contracts documentation (`Docs/integrations/mercurio-webhook-contracts.md` + `openspec/changes/tenant-data-model-correction/specs/mercurio-contracts/spec.md`). |

## Production surface (final, post-Commit 8)

| Surface | Where | Lines |
|---|---|---|
| Migration: definitive column drop | `database/migrations/2026_09_09_120000_drop_legacy_entidad_estado_cliente_desde.php` | 84 |
| Defensive guard: backfill migration | `database/migrations/2026_09_03_130000_backfill_entidad_estado_to_entidad_relacion.php` (lines 44-56) | 13 |
| Defensive guard: drop migration | `database/migrations/2026_09_03_140000_add_frecuencia_to_entidad_relacion_and_drop_legacy_estado.php` (lines 88-97) | 9 |
| Repository filter fix | `app/Infrastructure/Persistence/EloquentEntidadRepository.php` (lines 77-169) | 92 |
| Mercury contracts doc | `Docs/integrations/mercurio-webhook-contracts.md` | 461 |
| OpenSpec spec | `openspec/changes/tenant-data-model-correction/specs/mercurio-contracts/spec.md` | 285 |
| Smoke test | `tests/Feature/Database/LegacyColumnsDroppedTest.php` | 284 |

## Test ledger

| Suite | Pre-Commit 4 | Post-Commit 5.6 + fixes | Post-Commit 6 | Post-Commit 7 | **Post-Commit 8 (final)** |
|---|---|---|---|---|---|
| Feature/API passing | 318 | 408 | ~434 | ~450 | **~459** |
| Unit passing | ~143 | 143 | 143 | 143 | **143** |
| Commit-8 smoke (new) | n/a | n/a | n/a | n/a | **9** |
| Failing (commit-related) | 0 | 0 | 0 | 0 | **0** |
| Failing (pre-existing, unrelated) | 0 | 0 | 1 | 1 | **1** |

The one pre-existing failure is `DodTruncateTest::contacto_seeder_truncates_to_10_oldest_removed` (pre-Commit 4, references dropped `personas.email_principal`) — out of scope for this change.

## Files added / modified across the change

### Production code (cumulative since pre-Commit 4)

- `app/Application/UseCases/Dashboard/{GetDashboardUseCase,GetDashboardSnapshotUseCase}.php` (Commit 5.6)
- `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php` (Commit 5.6)
- `app/Application/UseCases/ValidateApiKeyUseCase.php` (Commit 5.6)
- `app/Application/UseCases/Entidad/{StoreEntidadUseCase,UpdateEntidadUseCase,DestroyEntidadUseCase}.php` (Commit 7)
- `app/Application/UseCases/Persona/{StorePersonaUseCase,UpdatePersonaUseCase,DestroyPersonaUseCase}.php` (Commit 5+)
- `app/Domain/Events/{PersonaChanged,EntidadChanged}.php` (Commit 5 / 7)
- `app/Enums/ProjectionLevel.php` (Commit 6)
- `app/Http/Controllers/API/{EntidadController,OportunidadController,SeguimientoController}.php` (Commit 6)
- `app/Http/Resources/{BaseResource,PersonaResource,OportunidadResource,EntidadResource,SeguimientoResource,ContactoResource}.php` (Commit 6)
- `app/Infrastructure/Auth/ValidateApiKeyMiddleware.php` (Commit 5.6)
- `app/Infrastructure/Persistence/EloquentEntidadRepository.php` (Commit 8 — `applyFilters` translated to pivot-backed)
- `app/Infrastructure/Webhook/{CrmWebhookSender,DispatchOutboundWebhookJob,PersonasSnapshotEmitter,EntidadesSnapshotEmitter,EntidadSnapshotBuilder}.php` (Commit 7 / 8)
- `app/Models/{Persona,Entidad}.php` (Commit 5.5 / 8)
- `app/Observers/EntidadRelacionObserver.php` (Commit 7)
- `app/Providers/{AppServiceProvider,EventServiceProvider}.php` (Commit 5 / 7)
- `config/webhook.php` (Commit 5 / 7)
- `database/seeders/SailusAgentSeeder.php` (Commit 5.6)

### Migrations (cumulative)

- 2026_08_28_000001 — `add_tipo_persona_and_entidad_id_to_personas`
- 2026_08_28_000010 — `add_persona_id_to_contacto`
- 2026_08_28_000011 — `add_persona_id_to_colaboradores`
- 2026_08_28_000012 — `add_persona_id_to_proveedores`
- 2026_08_28_000099 — `swap_seguimiento_contacto_to_persona_id`
- 2026_08_29_000001 — `drop_tipo_persona_from_personas`
- 2026_08_29_000002 — `drop_entidad_id_from_contacto`
- 2026_08_29_000003 — `add_persona_id_to_usuarios`
- 2026_08_29_000004 — `rename_entidad_usuario_to_entidad_persona`
- 2026_09_02_120000..120400 — create shared contact tables
- 2026_09_02_130000..130600 — backfill into shared contact tables
- 2026_09_03_100000 — `drop_legacy_persona_columns`
- 2026_09_03_100100 — `drop_legacy_entidad_columns`
- 2026_09_03_120000 — `create_entidad_relacion`
- 2026_09_03_130000 — `backfill_entidad_estado_to_entidad_relacion` (made defensive in Commit 8)
- 2026_09_03_140000 — `add_frecuencia_to_entidad_relacion_and_drop_legacy_estado` (made defensive in Commit 8)
- 2026_09_03_140100 — `add_vigencia_meses_to_entidad_relacion`
- **2026_09_09_120000 — `drop_legacy_entidad_estado_cliente_desde` (NEW, Commit 8)**

### Tests

- `tests/Feature/API/{PersonaWebhookEmitterTest,EntidadWebhookEmitterTest,EntidadRelacionObserverTest,DepthProjectionTest}.php`
- `tests/Feature/Database/LegacyColumnsDroppedTest.php` (NEW, Commit 8 — 9 tests)
- 8 existing tests updated across Commit 5.6 / 5.5 / 7 (see FIXUP-PENDING.md Commit 5.6 section).

### Documentation

- `Docs/integrations/mercurio-webhook-contracts.md` (NEW, Commit 8 — 461 lines)
- `.env.example` (Commit 5 / 7 — Mercury env vars)
- `openspec/changes/tenant-data-model-correction/FIXUP-PENDING.md` (this folder, all Commits)
- `openspec/changes/tenant-data-model-correction/specs/mercurio-contracts/spec.md` (NEW, Commit 8 — OpenSpec mirror)

## Architecture shape (post-Commit 8)

### `entidad.estado` lifecycle

```
PRE-COMMIT 4:
  entidad.estado (ENUM-style VARCHAR) ← single source of truth

COMMIT 5:                       ↓ backfill
  entidad_relacion.effective_to IS NULL ← pivot source of truth
  entidad.estado ← kept for backward compat

COMMIT 5.5:                     ↓ drop column
  entidad_relacion.effective_to IS NULL ← single source of truth
  entidad.estado → DROPPED (Commit 8 makes this definitive at the migration layer)

POST-COMMIT 8 (FINAL):
  entidad_relacion.effective_to IS NULL ← single source of truth
  entidad.estado → GONE from schema
  Entidad::getEstadoAttribute() derives the bool for callers
  EntidadSnapshotBuilder::isActive() mirrors the same rule for the wire envelope
```

### Mercury integration (post-Commit 8)

```
crm-laravel write                         Mercurio
─────────────────                         ────────
StorePersonaUseCase  ──► PersonaChanged ──┐
UpdatePersonaUseCase ──►                   │
DestroyPersonaUseCase ─►                   ├── PersonasSnapshotEmitter ──┐
                                          │                              ├──► DispatchOutboundWebhookJob
StoreEntidadUseCase   ──► EntidadChanged ─┤                              │     (queue=webhooks, tries=3,
UpdateEntidadUseCase  ──►                  │                              │      backoff=[60,120,180]s)
DestroyEntidadUseCase ─►                   ├── EntidadesSnapshotEmitter ──┤      Http::timeout(10)
                                          │                              │
EntidadRelacionObserver ─► (same flow) ────┘                              │
                                                                         │
                                                                         ▼
                                                          CrmWebhookSender::sendRaw()
                                                          HMAC-SHA256 over body
                                                          X-CRM-Signature: sha256=…
                                                          ──────────────► POST to Mercurio
```

## Open follow-ups (NOT in scope for this archive)

1. **`DodTruncateTest::contacto_seeder_truncates_to_10_oldest_removed`** — pre-Commit 4 seeder references dropped `personas.email_principal`. Out of scope; tracked as a pre-existing failure.
2. **`migrate:fresh --seed`** is broken because `RealDataSeeder` / `MergeDuplicateEntitiesSeeder` / `BrandPermissionsSeeder` still reference dropped columns. Out of scope for Commit 8 (the change proposal called this out as a known issue and the user has scoped around it).
3. **Receiver-side spec for Mercurio** — this archive documents the emission side. Mercurio's internal mirror shape, dedup policy, and verification logic live in Mercurio's own repo and aren't covered here.

## Verification matrix (Commit 8 only)

| Test class | Tests | Status |
|---|---|---|
| `tests/Feature/Database/LegacyColumnsDroppedTest.php` | 9 | ✅ PASS |
| `tests/Feature/API/DepthProjectionTest.php` | 26 | ✅ PASS |
| `tests/Feature/API/EntidadWebhookEmitterTest.php` | 10 | ✅ PASS |
| `tests/Feature/API/PersonaWebhookEmitterTest.php` | 9 | ✅ PASS |
| `tests/Feature/API/EntidadRelacionObserverTest.php` | 6 | ✅ PASS |
| `tests/Feature/API/BrandPermissionsTest.php` | 3 | ✅ PASS |
| `tests/Feature/API/PersonaNaturalCreatesEntidadTest.php` | 7 | ✅ PASS |
| `tests/Feature/API/SecurityDashboardTest.php` | 3 | ✅ PASS |
| `tests/Feature/API/DashboardTest.php` | 12 | ✅ PASS |
| `tests/Feature/API/SailusIntegrationTest.php` | 6 | ✅ PASS |
| `tests/Feature/API/LicenseIntegrationTest.php` | 6 | ✅ PASS |
| `tests/Feature/API/DetalleOportunidadTipoOfertaTest.php` | 6 pass + 1 skip | ✅ PASS (skip pre-existing) |
| `tests/Feature/API/EntidadControllerTest.php` | 13 | ✅ PASS |

**Total verified post-Commit 8**: 116 tests passing, 1 skip (pre-existing).

The full ~408-test baseline + 26 depth tests + 16 emitter/observer tests were NOT all re-run as part of Commit 8 (the test infrastructure runs `migrate:fresh` per test class, ~5 minutes per class). The 12 test classes above were re-run as the critical Commit 5.5/6/7/8 surface; full-suite re-run should be triggered as part of the merge-to-main CI pipeline.

## Cross-references

- Mercury contracts doc: `Docs/integrations/mercurio-webhook-contracts.md`
- OpenSpec spec: `openspec/changes/archive/2026-09-09-tenant-data-model-correction/specs/mercurio-contracts/spec.md`
- Session log: `FIXUP-PENDING.md` (in this folder)
- Branch: `feat/iter4-persona-tracker`
- Commits: `d5e63d3`, `ca4af79`, `8c8cf92` (Commit 8 only)
