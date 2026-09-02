# Proposal: multi-app-auth-identity

## Intent

Habilitar el HUB-micro-saas (BRP + Indicadores + Horas Extras + Estándares Mínimos) para autenticar contra el CRM con un único login y permisos scopados por app. Hoy el modelo de permisos es global-por-rol, lo cual no escala a multi-app con permisos diferenciados. Sin esto, cada app nueva (Indicadores, HE, EM) tendría que reinventar auth, multiplicando superficie de bug y trabajo por app.

## Scope

### In Scope

- Schema `usuario_app_permisos` + tabla pivote + migration data desde defaults del rol
- Schema `user_identity_snapshot` (read-model CQRS-Lite)
- `MultiAppRbacService` que une core + app-scoped permissions
- Refactor `RbacMiddleware` para usar el nuevo lookup con cache Redis 5min
- Endpoint `GET /api/v1/me/identity` con bundle consolidado `{user, apps[], permisos scoped, rol}`
- Endpoint `GET /api/v1/me/permisos` con lista plana core ∪ scoped
- Endpoints admin: `GET/POST/DELETE /api/v1/usuarios/{userId}/apps/{appId}/permisos`
- Artisan `crm:refresh-user-identity-snapshot` (single + bulk, scheduled 03:30am)
- `IdentityCache` con fallback graceful si Redis down (AC06)
- Cascade automático: cuando se asigna app a entidad, propagar permisos default a usuarios
- Invalidation: cada mutación de permisos marca `user_identity_snapshot.is_stale=1`
- UI admin opcional (Sprint 2): matrix `apps × vistas`
- Documentación: OpenAPI spec + integration-guide para nuevos equipos (AC10)

### Out of Scope

- BRP backend changes (cambia localmente, scope = BRP repo, no este)
- Nuevos endpoints de admin de usuarios (tabla users CRUD queda como está)
- Indicadores/Horas Extras/Estándares Mínimos implementation (esas apps llegan después)
- Per-app roles con `rol_id` por `usuario_app_permisos` (v1 solo permite string `vista`)
- Permisos scopados por `entidad_usuario` (v1 usa app-level solamente)
- Migración de permisos production con datos reales de clientes (D-3: se hace via `crm:reset-user-permissions --user=X`)

## Capabilities

### New Capabilities
- `user-identity-snapshot`: read-model pre-computado + invalidation events para identidad del usuario
- `app-scoped-permissions`: gestión de permisos scopados por app/usuario con cascade automático
- `admin-granular-permissions`: endpoints admin para ajustar permisos individuales por usuario/app

### Modified Capabilities
- `auth-rbac`: el RbacMiddleware ahora valida core + app-scoped (antes solo core)
- `me-endpoints`: el `GetMyIdentityUseCase` retorna bundle consolidado (antes solo apps)

## Approach

**Patrón CQRS-Lite ya establecido**: replicar lo que hicimos con `dashboard_kpi_snapshot` pero para identidad. Mismo artisan command + mismo schedule + misma cache Redis. Re-uso 100% del patrón probado en producción.

**Hybrid permission model**: `permisos(rol_id, vista)` se conserva para core del CRM (sin cambios). Se agrega `usuario_app_permisos(usuario_id, app_id, vista)` para app-scoped. En runtime, `MultiAppRbacService` hace UNION ALL de ambos por usuario+app.

**Validación por scope del endpoint**: el `RbacMiddleware` actual solo checkea route_name contra `permisos.vista`. La extensión agrega app_id como parámetro derivado del contexto del endpoint (no de query — viene del prefijo de ruta). Cross-app (BRP viendo `apps.show` pero user no tiene BRP) bloqueado.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Application/UseCases/Me/GetMyIdentityUseCase.php` | New | Lee snapshot, fallback live, 5min Redis cache |
| `app/Application/UseCases/Usuario/GrantAppPermissionUseCase.php` | New | Hook invalidation + cascade |
| `app/Application/Services/MultiAppRbacService.php` | New | Singleton service, lookup con cache |
| `app/Http/Middleware/RbacMiddleware.php` | Modified | Acepta app_id parameter, llama MultiAppRbacService |
| `app/Http/Controllers/API/MeController.php` | Modified | +identity(), +permisos() methods |
| `app/Http/Controllers/API/UsuarioPermisoController.php` | New | Endpoints admin de permisos scopados |
| `app/Models/UsuarioAppPermiso.php` | New | Eloquent model + SoftDeletes |
| `app/Models/UserIdentitySnapshot.php` | New | Eloquent model (sin SoftDeletes) |
| `Modules/CRM/routes/api.php` | Modified | + /me/identity, /me/permisos, /usuarios/{id}/apps/* endpoints |
| `database/migrations/2026_08_06_*_create_usuario_app_permisos_table.php` | New | Schema |
| `database/migrations/2026_08_06_*_create_user_identity_snapshot_table.php` | New | Schema |
| `database/migrations/2026_08_06_*_migrate_default_permisos_to_scoped.php` | New | Data migration |
| `database/seeders/PermisoSeeder.php` | Modified | + vistas `me.identity`, `me.permisos`, `usuarios.apps.permisos.*` |
| `routes/console.php` | Modified | `Schedule::command(...)->dailyAt('03:30')` |
| `app/Console/Commands/RefreshUserIdentitySnapshot.php` | New | Artisan wrapper |
| `Docs/openapi/auth.yaml` | New | OpenAPI 3.1 spec |
| `tools/openapi-lint.sh` | New | CI guard |
| `docs/integrations/sailus-integration.md` | Modified | Bump v2 → v3 |
| `Docs/design/ADD-AUTH-001-multi-app-auth.md` | Modified | Status: Draft → Implemented |
| `tests/Unit/Application/MultiAppRbacServiceTest.php` | New | 12 casos: 3 roles × 4 escenarios |
| `tests/Feature/API/MeControllerTest.php` | Modified | + /me/identity, /me/permisos |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Migración de data en producción genera permisos incorrectos | Med | Backup pre-migration + dry-run flag + staging primero + 2 env (staging → prod) |
| Cross-app permission leak (user A con BRP accede a Indicadores) | High | Test suite dedicado con 4 escenarios cross-app + RbacMiddleware test que cubre ambos axes |
| Snapshot stale > 24h (job no corrió) | Low | Monitoreo + alerta en Telescope + comando manual on-demand |
| Concurrencia 1000+ degrada login | Med | Load test previo en staging con k6 o apache bench |
| Schema change del payload rompe BRP en prod | Med | OpenAPI lint en CI + campo `scope_label` + campos nuevos siempre opcionales |
| Vendor lock-in (alguien mete spatie/laravel-permission) | Low | PR review debe rechazar `composer require` con paquete RBAC; documentado en R01 |
| Cache invalidation race (admin asigna app, user consulta antes de invalidar) | Low | TTL 5min en Redis es el safety net + `is_stale` flag en snapshot recompute next read |

## Rollback Plan

1. **Fase de deploy**: deploy con feature flag `MULTI_APP_AUTH_ENABLED=false` (default). Activar por env var despues de validar smoke tests.
2. **Si cross-app leak detectado**: desactivar feature flag → RbacMiddleware vuelve al comportamiento anterior (solo `permisos(rol_id)` sin scoping). BRP sigue funcionando con permisos globales (degraded).
3. **Si migración de data falla**: `php artisan migrate:rollback --step=<N>` vuelve a estado anterior. Mantener backup pre-migration.
4. **Si snapshot genera queries lentos**: bump `user_identity_snapshot` a `is_stale=1` forzar recompute. Si persisten lentitud, fallback a live query.

## Dependencies

- **UserIdentitySnapshot** depende de `usuarios`, `permisos(rol_id)`, `apps`, `app_entidad`, `entidad_usuario`, `usuario_app_permisos` (las 6 tablas)
- **MultiAppRbacService** depende de `usuarios`, `roles`, `permisos`, `apps`, `app_entidad`, `entidad_usuario`
- **IdentityCache** depende de Redis (configurado, ver `.env`)
- **Schedule** depende del sistema de colas Laravel (queue=database actualmente)
- **BRP equipo** (externo): debe actualizar `SailusAuthMiddleware` para consumir el nuevo endpoint

## Success Criteria

- [ ] BRP integra `/me/identity` y deja de hacer N+1 calls (visible en logs BRP)
- [ ] Indicadores arranca integración < 4h (medido con el checklist del integration-guide)
- [ ] Login p95 < 500ms (sin cache hit) medido con k6
- [ ] `/me/identity` p95 < 80ms (con cache hit) medido con k6
- [ ] Cross-app check: 100% de intentos con app_id no autorizada → 403, 0 falsos positivos
- [ ] Snapshot row count == user count después del primer refresh
- [ ] 0 `is_stale=true` rows después del refresh job automático
- [ ] OpenAPI spec publicado en `Docs/openapi/auth.yaml` + lint test pasa en CI
- [ ] SailusIntegration v3.0 publicado con ejemplos curl
- [ ] Tests Pest: 12+ casos en `MultiAppRbacServiceTest`, 8+ casos en `MeControllerTest`
- [ ] Deployment a staging sin rollback en 48h, luego a prod
