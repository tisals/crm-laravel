# Archive Report — CRUD API Completo

**Change**: crud-api-completo
**Archived**: 2026-05-10
**Status**: ✅ IMPLEMENTED AND VERIFIED

---

## Executive Summary

Complete domain replacement of the original 6-table SaaS CRM schema with a full 19-entity ERP system. Delivered across 4 phases (Foundation, Directorio+Talento, CRM, Operaciones+Finanzas) with 96 tasks, 156 passing tests (465 assertions), and 102 API routes under `/api/v1/`. All code follows Clean Architecture (Domain → Application → Infrastructure → Interfaces). The system replaces Contact, Organization, OrganizationService, Plan, and Tag models with 19 new entities spanning security, master data, directory, talent, CRM, operations, and finance domains.

## What Was Built

### 19 Entities with Full CRUD

| Domain | Entities |
|--------|----------|
| **Seguridad** | roles, permisos, usuarios |
| **Maestros** | ciudades (read-only), productos, etiquetas |
| **Directorio** | entidad, lugares_entidad, contacto |
| **Talento** | colaboradores, proveedores |
| **CRM** | oportunidad, detalle_oportunidad, seguimiento |
| **Operaciones** | servicios, detalle_servicios, orden_servicio |
| **Finanzas** | cuentas, movimientos |

### Key Features
- **Sanctum auth** reconfigured for `usuarios` model with `getAuthPassword()` returning `password_hash`
- **RBAC middleware** checks `permisos` table by route name against user's role
- **Auto-codigo generation** for oportunidades (`COT-{6digits}`)
- **Auto-calculation** for detalle lines (vr_total, IVA) via `CalculoDetalleService`
- **Nested routes**: `/entidad/{id}/lugares`, `/oportunidad/{id}/detalles`, `/servicios/{id}/detalles`
- **Auto-creation** of Servicio when Oportunidad reaches estado=Ganada
- **No soft deletes** on cuentas and movimientos (permanent financial audit trail)
- **Soft deletes** on 15 entities with `?trashed=true` support
- **Ciudades** uses VARCHAR PK (`cod_municipio`) — read-only, DANE codes

## Test Coverage

| Suite | Tests | Assertions |
|-------|-------|------------|
| Feature (API Controllers) | 143 | 446 |
| Unit (Services, Resources, Repository) | 13 | 19 |
| **Total** | **156** | **465** |

All 156 tests pass: `php artisan test` exits 0.

## Key Architectural Decisions

1. **Clean Architecture** — 4 layers: Domain entities (pure PHP) → Application UseCases → Infrastructure Repositories → Interfaces (Controllers/Requests/Resources)
2. **BaseRepository pattern** — All 19 Eloquent repositories extend `BaseRepository` with `paginate`, `findById`, `create`, `update`, `delete`
3. **BaseResource** — All API Resources extend `BaseResource` with consistent JSON envelope `{ success, data?, message?, error? }`
4. **RBAC** — Permission check by `route name → permisos.vista`, not hardcoded middleware per route
5. **CalculoDetalleService** — Shared auto-calculation service for both DetalleOportunidad and DetalleServicio (DRY)
6. **Codigo format** — `COT-{6digits}` chosen over `OP-{YYYY}-{XXXX}` for simplicity and readability
7. **vr_total including IVA** — Deliberate decision: matches real-world invoice behavior (total = subtotal + tax)
8. **Entity naming** — Spanish throughout to match business domain (`usuarios`, `entidad`, `oportunidad`, `cuentas`)

## How to Use the API

```bash
# 1. Login
curl -X POST http://localhost:8001/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
# Returns: { success: true, data: { token: "...", usuario: {...} } }

# 2. Use token for CRUD
curl http://localhost:8001/api/v1/entidad \
  -H "Authorization: Bearer {token}"

# 3. Create entidad
curl -X POST http://localhost:8001/api/v1/entidad \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"tipo_persona":"Natural","identificacion":"123456789","nombre":"Juan Perez","ciudad_cod":"05001"}'

# 4. Pagination & search
curl "http://localhost:8001/api/v1/entidad?search=Juan&per_page=15&sort_by=created_at&sort_order=desc"

# 5. Soft delete restore
curl "http://localhost:8001/api/v1/colaboradores?trashed=true"
```

All endpoints under `/api/v1/`. 102 routes total. See `php artisan route:list` for complete reference.

## Ingestion Notes for Future AI Sessions

- **Cuentas** — `app/Models/Cuenta.php` has NO SoftDeletes trait (permanent records). Destroy use case does hard delete.
- **Ciudades** — `$primaryKey = 'cod_municipio'`, `$incrementing = false`, `$keyType = 'string'`
- **Usuarios auth** — `getAuthPassword()` returns `password_hash` column, not the default `password`
- **Codigos** — `COT-{6digits}` pattern (not `OP-{YYYY}-{XXXX}`)
- **vr_total** — Includes IVA (subtotal + tax)
- **`created_by` / `updated_by`** — Auto-set from `auth()->id()` in BaseRepository
- **RBAC** — Route name maps to `permisos.vista`. Route names use dots: `entidad.index`, `entidad.store`, etc.
- **Test helper** — `actingAsUsuario()` in TestCase creates usuario + returns Sanctum token
- **AppServiceProvider** — All 19+ repository bindings registered in `$this->app->bind(Interface::class, EloquentRepo::class)` format
- **Logout** — `auth()->user()->currentAccessToken()->delete()` (not `tokens()->delete()`)

## Observation IDs (Engram Traceability)

| Artifact | Engram ID |
|----------|-----------|
| explore | #229 |
| proposal | #230 |
| spec | #231 |
| design | #233 |
| tasks | #234 |
| verify-report | #328 |
| archive-report | (this document) |

## Next Steps for the User

1. **Add T4.24 (SoftDeleteTest)** — Create test verifying `?trashed=true` restore flow for soft-delete entities
2. **Add coverage tool** — Configure PHPUnit coverage reporting for regression detection
3. **Test `fecha_retiro` auto-set** — Add test for Colaborador status→Inactivo → fecha_retiro auto-set
4. **Test duplicate email per entidad** — Verify contacto unique constraint enforcement
5. **Add Rol delete protection** — Check if usuarios reference a role before allowing delete
6. **Reconcile spec deviations** — Update spec.md to document `COT-{6digits}` and vr_total+IVA as intentional decisions
7. **Frontend integration** — Connect FastAPI or other frontend to the new API
8. **Webhook restoration** — Rebuild webhook endpoint for external diagnostic tool integration
