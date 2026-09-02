# Proposal: CRUD API Completo — Domain Replacement

## Intent

Replace the existing SaaS CRM schema (6 tables: users, organizations, contacts, organization_services, plans, tags) with the full 19-table ERP dictionary from `Docs/Diccionario_entidades.md`. This is a **complete domain replacement** — the existing AppSheet-based CRM moves into Laravel with a proper infrastructure.

## Scope

### In Scope
- 19 migrations (create all dictionary tables, drop old ones)
- 19 Eloquent models + 19 Domain entities + 19 repository interfaces
- 57 use cases (index/show/store/update/destroy per entity, plus specialized lookups)
- 19 controllers + 19 Form Requests + 19 API Resources
- Sanctum auth reconfigured for `usuarios` model (Spanish naming)
- Full CRUD routes under `/api/v1/` for all 19 entities
- Seeders for reference data (roles, ciudades, etiquetas)
- Delete: old migrations, models, entities, repos, use cases, controllers, requests, resources, tests for the 6 old entities
- Tests: Feature tests for all 19 endpoints + Unit tests for domain entities

### Out of Scope
- Webhook integration (diagnostic tool → CRM) — deferred; old webhook was for a different domain
- Outbound webhooks (CrmWebhookSender) — deferred until FastAPI integration is rebuilt
- License management (`canAddUser`, `trial_ends_at`) — belongs to new ERP domain model
- FastAPI integration — will be rebuilt against new API after migration
- Authentication UI (login/register) — Sanctum tokens remain the auth mechanism

## Capabilities

### New Capabilities
- `seguridad`: roles CRUD, permisos CRUD, usuarios CRUD (replaces App\Models\User)
- `maestros`: ciudades CRUD (read-only from DANE codes), productos CRUD, etiquetas CRUD
- `directorio`: entidad CRUD (replaces Organization), lugares_entidad CRUD, contacto CRUD (replaces Contact)
- `talento`: colaboradores CRUD, proveedores CRUD
- `crm`: oportunidad CRUD, detalle_oportunidad CRUD, seguimiento CRUD
- `operaciones`: servicios CRUD (replaces OrganizationService), detalle_servicios CRUD, orden_servicio CRUD
- `finanzas`: cuentas CRUD, movimientos CRUD

### Modified Capabilities
- None — this is a full replacement, no existing capabilities are modified

## Approach

**Big Bang replacement in 4 sequential phases:**

1. **Foundation** (seguridad + maestros): Drop old tables, create `roles`, `permisos`, `usuarios`, `ciudades`, `productos`, `etiquetas`. Reconfigure `config/auth.php` to point to `App\Models\Usuarios`. Update `HasApiTokens` usage. Seed reference data.
2. **Directorio + Talento**: `entidad`, `lugares_entidad`, `contacto`, `colaboradores`, `proveedores` — depends on phase 1 (FKs to ciudades, usuarios).
3. **CRM**: `oportunidad`, `detalle_oportunidad`, `seguimiento` — depends on phase 2 (FKs to entidad, contacto, usuarios).
4. **Operaciones + Finanzas**: `servicios`, `detalle_servicios`, `orden_servicio`, `cuentas`, `movimientos` — depends on phase 3 (FKs to oportunidad, contacto, proveedores).

Each phase produces: migration, model, entity, repo interface + implementation, use cases (CRUD), controller, requests, resources, routes, tests.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/migrations/` | Removed + 19 new | Drop 12 old, create 19 new |
| `app/Models/` | Removed + 19 new | Delete 6, create 19 |
| `app/Domain/Entities/` | Removed + 19 new | Delete 5, create 19 |
| `app/Domain/Repositories/` | Removed + 19 new | Delete 5, create 19 |
| `app/Infrastructure/Persistence/` | Removed + 19 new | Delete 5 repos, create 19 |
| `app/Application/UseCases/` | Removed + 57 new | Delete 16, create 57 |
| `app/Http/Controllers/API/` | Removed + 19 new | Delete 5, create 19 |
| `app/Http/Requests/` | Removed + 19 new | Create all |
| `app/Http/Resources/` | Removed + 19 new | Create all |
| `routes/api.php` | Rewrite | Replace all routes |
| `config/auth.php` | Modified | Point to `App\Models\Usuarios` |
| `config/sanctum.php` | Modified | Update token guard |
| `tests/` | Removed + 57 new | Delete 24 old, create 57+ |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Sanctum auth breaks if `usuarios` model doesn't implement `HasApiTokens` properly | Med | Keep `User.php` as alias that extends `Usuarios` or configure auth provider carefully |
| Data loss (existing DB is wiped with no rollback after fresh migration) | High | Export seed data from old tables before dropping; provide rollback script that restores old schema |
| Test infrastructure reliance on old seeders | High | Rewrite `DatabaseSeeder` for new schema; update `phpunit.xml` to use in-memory SQLite |
| Breaking FastAPI integration mid-development | High | This is expected — coordinate deployment cutover, do not deploy until all 4 phases pass tests |
| 57 use cases is massive surface area for bugs | Med | Use a code generator pattern for CRUD boilerplate; verify each entity with dedicated feature tests |

## Rollback Plan

1. Keep a snapshot of `database/migrations/`, `app/Models/`, `app/Domain/`, `app/Infrastructure/Persistence/`, `app/Application/UseCases/`, `app/Http/` before deletion using git branch (`git checkout -b pre-migration-snapshot`)
2. If migration fails at any phase: `git checkout -- <failed-files>` and restore old migrations, then `php artisan migrate:fresh` with old schema
3. If data is needed: restore from SQLite backup (taken before first migration) or from seed export
4. Full recovery: `git checkout pre-migration-snapshot` + `php artisan migrate:fresh --seed`

## Dependencies

- Laravel Sanctum must work with non-standard User model (`usuarios`)
- DANE codes dataset for `ciudades` table seeding (optional — can seed manually)
- No external packages needed; all CRUD uses existing Laravel primitives

## Success Criteria

- [x] All 19 migrations run without errors; no old tables remain
- [x] `php artisan route:list` shows all CRUD routes for 19 entities
- [x] Sanctum token generation works with `usuarios` model (not `User`)
- [x] All 19 POST routes accept valid payloads and return 201
- [x] All 19 GET routes return paginated lists
- [x] `php artisan test` passes all feature + unit tests (minimum 57 tests)
- [x] No dead code remains from old domain (check for stale imports, dead controllers)
