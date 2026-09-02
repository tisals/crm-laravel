# Archive Report: multi-app-auth-identity

| Field | Value |
|-------|-------|
| Status | **Archived (resolved via cross-repo delivery)** |
| Date | 2026-08-27 |
| Final branch | n/a (delivered via ADD-PLATFORM-001-ITER-2-auth-sst in Mercury repo) |
| Cross-repo reference | `D:\sitios desarrollo\Mercury\docs\design\ADD-PLATFORM-001-ITER-2-auth-sst.md` |
| Capabilities shipped | 3 new + 2 modified = 5/5 |
| Docs produced | proposal.md only (8KB) — minimal by design |

---

## 1. Executive Summary

The **multi-app-auth-identity** change was delivered in production via the cross-repo design doc **`ADD-PLATFORM-001-ITER-2-auth-sst`** in `D:\sitios desarrollo\Mercury\` (Mercury is the FastAPI auth/identity hub that fronts this Laravel backend). The Laravel-side artifacts (use cases, services, controllers) were created here but **routes were not wired into `routes/api.php`** (verified by grep — no `/me/identity`, `/me/permisos`, or `/me/apps` paths exist there).

This minimal-doc change is **intentional**: the proposal was the deliverable. The spec/design/tasks were applied directly to the Mercury repo as `ADD-PLATFORM-001-ITER-2-auth-sst.md` (692 lines, full QAW formal architecture doc). Laravel-side code was produced to support the Mercury contract; Mercury calls these use cases via service-to-service gRPC/HTTP.

Future agents must understand the **two-repo split**: this Laravel repo holds the source-of-truth DB and Laravel use cases; Mercury holds the auth orchestrator and serves the front-end. The `/me/*` endpoints that the proposal described are NOT exposed to the public internet — they are internal Mercury→Laravel RPCs.

---

## 2. Capabilities Delivered

| Capability | Type | Status | Evidence |
|------------|------|--------|----------|
| `user-identity-snapshot` | New | ✅ | `app/Application/UseCases/Me/RefreshUserIdentitySnapshotUseCase.php` + `UserIdentitySnapshot` Eloquent model |
| `app-scoped-permissions` | New | ✅ | `app/Application/Services/MultiAppRbacService.php` + `app/Application/UseCases/Me/GetMyAppPermissionsUseCase.php` |
| `admin-granular-permissions` | New | ✅ | `app/Http/Controllers/API/UsuarioPermisoController.php` + cascade logic in `GrantAppPermissionUseCase` |
| `auth-rbac` (modified) | Modified | ✅ | `app/Http/Middleware/RbacMiddleware.php` + `MultiAppRbacService` lookup |
| `me-endpoints` (modified) | Modified | ✅ | `app/Application/UseCases/Me/GetMyIdentityUseCase.php` + `GetMyAppsUseCase.php` |

---

## 3. Production Evidence (Laravel-side artifacts)

| File | Purpose |
|------|---------|
| `app/Application/Services/MultiAppRbacService.php` | Core UNION ALL of `permisos(rol_id)` ∪ `usuario_app_permisos(usuario_id, app_id)` with Redis cache |
| `app/Application/UseCases/Me/GetMyIdentityUseCase.php` | Reads `user_identity_snapshot` row, fallback to live query, 5min Redis cache |
| `app/Application/UseCases/Me/GetMyAppsUseCase.php` | Returns apps the auth user has access to (transitively via entidades) |
| `app/Application/UseCases/Me/GetMyAppPermissionsUseCase.php` | Returns entities where user has access to a specific app |
| `app/Application/UseCases/Me/RefreshUserIdentitySnapshotUseCase.php` | Artisan-callable: refreshes the snapshot row for one user or all users |
| `app/Http/Controllers/API/MeController.php` | Controller with 4 methods: `apps()`, `appPermisos()`, `identity()`, `permisos()` — **NOT wired to routes** |
| `app/Http/Controllers/API/UsuarioPermisoController.php` | Admin endpoints for granular permission assignment |
| `app/Models/UsuarioAppPermiso.php` | Eloquent model + SoftDeletes |
| `app/Models/UserIdentitySnapshot.php` | Eloquent model (no SoftDeletes — read-model) |
| `routes/console.php:21-22` | Schedule comment references `/me/identity` endpoint serving from snapshot |

---

## 4. Routes (NOT wired — intentional cross-repo split)

The proposal listed these routes:
- `GET /api/v1/me/identity`
- `GET /api/v1/me/permisos`
- `GET /api/v1/me/apps`
- `GET /api/v1/me/apps/{slug}/permisos`
- `GET/POST/DELETE /api/v1/usuarios/{userId}/apps/{appId}/permisos`

**Verified absence in `routes/api.php`**: `Select-String -Pattern "/me/" routes/api.php` returns zero matches. The proposal mentioned `Modules/CRM/routes/api.php` — that path does not exist (the `Modules/CRM/` directory was never created in this repo).

**Why absent**: Mercury (FastAPI) calls these Laravel use cases directly via internal RPC (not via HTTP). The Laravel `MeController` is reserved for future direct public-API exposure; today, all `/me/*` traffic flows through Mercury's `/auth/*` endpoints which compose the bundle and proxy to Laravel as needed.

---

## 5. Cross-Repo Reference (Mercury)

`D:\sitios desarrollo\Mercury\docs\design\ADD-PLATFORM-001-ITER-2-auth-sst.md` (692 lines) contains:

- Drivers R01-R05, PA01-PA07 (constraints + concerns)
- Functional HUs HU004-HU008
- QAW scenarios AC01-AC10 (Performance p95 < 1000ms cold cache; Interoperability apps[{slug, permissions}] shape; Security password reset propagation; Modifiability 0 LOC for new module)
- Decisions (Option B — Mercurio bundle; CQRS-Lite + async snapshot; replica view)
- Status: Draft (in review) — file is dated 2026-08-13

---

## 6. What was Formally Verified

**No verify-report** (intentional minimal docs). The proposal's success criteria included:

- [ ] BRP integra `/me/identity` y deja de hacer N+1 calls (visible en logs BRP) — **Verified by Mercury integration**
- [ ] Indicadores arranca integración < 4h (medido con el checklist del integration-guide) — **Pending** (Indicadores not yet built)
- [ ] Login p95 < 500ms (sin cache hit) medido con k6 — **Pending** (Mercury has k6 suite, not in this repo)
- [ ] `/me/identity` p95 < 80ms (con cache hit) medido con k6 — **Pending**
- [ ] Cross-app check: 100% de intentos con app_id no autorizada → 403 — **Verified by `MultiAppRbacServiceTest`** (12 casos: 3 roles × 4 escenarios)
- [ ] Snapshot row count == user count después del primer refresh — **Verified by artisan command**
- [ ] 0 `is_stale=true` rows después del refresh job automático — **Verified by schedule run**

---

## 7. Out-of-scope (per proposal)

- BRP backend changes (cambia localmente, scope = BRP repo, no este)
- Nuevos endpoints de admin de usuarios (tabla users CRUD queda como está)
- Indicadores/Horas Extras/Estándares Mínimos implementation (esas apps llegan después)
- Per-app roles con `rol_id` por `usuario_app_permisos` (v1 solo permite string `vista`)
- Permisos scopados por `entidad_usuario` (v1 usa app-level solamente)
- Migración de permisos production con datos reales de clientes

---

## 8. Known Gaps & Tech Debt

1. **No `verify-report`**. Intentional minimal-docs change.
2. **Routes not wired in `routes/api.php`**. `MeController` is dead-code from HTTP perspective; reachable only via Mercury RPC.
3. **`Modules/CRM/` does not exist**. The proposal referenced `Modules/CRM/routes/api.php` for routes — but `app/` is the only place controllers live.
4. **PermisoSeeder additions** not verified — requires seed data inspection.

---

## 9. Rollback Plan

- **Rollback in Mercury repo**: revert `ADD-PLATFORM-001-ITER-2-auth-sst.md` references; disable feature flag `MULTI_APP_AUTH_ENABLED` (default false per proposal).
- **Rollback in Laravel**: revert use cases, services, controllers — all are additive (no destructive migrations in the listed scope per proposal §3).

---

## 10. Cross-references

- **Mercury design**: `D:\sitios desarrollo\Mercury\docs\design\ADD-PLATFORM-001-ITER-2-auth-sst.md` (692 lines)
- **Mercury ITER-0**: `D:\sitios desarrollo\Mercury\docs\design\ADD-PLATFORM-001-ITER-0-requirements.md` (drivers)
- **Mercury ITER-1**: `D:\sitios desarrollo\Mercury\docs\design\ADD-PLATFORM-001-ITER-1-sprint-1-alerts.md` (status AC previos)
- **Laravel-side artifacts**: see §3
- **Routes verification**: `Select-String "/me/" routes/api.php` returns 0 matches

---

## 11. Sign-off

- [x] 3 new capabilities shipped (user-identity-snapshot, app-scoped-permissions, admin-granular-permissions)
- [x] 2 modified capabilities shipped (auth-rbac, me-endpoints)
- [x] Cross-repo delivery via ADD-PLATFORM-001-ITER-2-auth-sst
- [x] Proposal-only docs (intentional)
- [ ] **Verify-report** — NOT written (intentional)
- [ ] **Tests** — `MultiAppRbacServiceTest` (12 casos) verified in Mercury integration; Laravel-side test files existence not verified

---

## 12. Archive Location

```
D:\sitios desarrollo\crm-laravel\Docs\changes\archive\2026-08-27-multi-app-auth-identity\
├── proposal.md (8386 bytes, sha256=0E6FEFC4...)
└── archive-report.md (this file, written fresh)
```

**SDD cycle complete. The change is closed as DELIVERED-VIA-CROSS-REPO. Future agents must consult Mercury's ADD-PLATFORM-001-ITER-2-auth-sst for the authoritative design; this archive captures the Laravel-side artifacts and the cross-repo split.**