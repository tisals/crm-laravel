# Design: SAIlus Integration v1

## Technical Approach

Five endpoints for SAIlus FastAPI consumption, following existing Clean Architecture patterns (Controller → UseCase → Repository). Reuses `ValidateApiKeyMiddleware`, `ApiResponse` trait, and `BrandPermissionsSeeder` seeder pattern. All new controllers go in `App\Http\Controllers\API\`.

## Architecture Decisions

| Decision | Options | Choice | Rationale |
|----------|---------|--------|-----------|
| **API key storage** | `dominio` (existing) vs new `api_key` column | Keep `dominio` | Entidad already has `dominio` field; ValidateApiKeyUseCase already queries it. No migration needed. Keys like `sailus_bot_abc123` stored as dominio values. |
| **Cuentas route conflict** | `/cuentas/{id}` (conflict with financial Cuentas) vs `/sailus/cuentas/{id}` | `/api/v1/sailus/cuentas/{id}` | Existing `cuentas/{id}` → CuentaController (accounting module). Cannot share path. New SAIlus namespace avoids collisions and scopes future SAIlus endpoints. |
| **Webhook plan_type** | Store in servicio.nombre vs lookup only | Lookup only, return plan_id | Plan_type matches Producto.nombre WHERE tipo='suscripcion'. Return matched ID; no persistence needed on Servicio. servicio.nombre gets `service_name` field. |
| **Webhook email duplicate** | updateOrCreate vs explicit 409 | Explicit 409 | Spec requires 409 on duplicate email. Check `Contacto::where('email_contacto', $email)->exists()` before transaction. |
| **diagnostico_data storage** | New JSON column vs separate table | New `diagnostico_data` JSON column on contacto | Contacto has no JSON fields. Adding nullable JSON column is minimal. Separate table overkill for unstructured diagnostic payload. |
| **Plans fields (price, desc, features)** | Add to productos vs return nulls | Add nullable columns: `descripcion`, `precio`, `caracteristicas` | Spec contract requires these fields. Nullable ensures backward compat for existing productos (tipo='producto'). |

## Data Flow

```
SAIlus Bot
    │
    ├─ GET /auth/validate-key ──→ ValidateApiKeyMiddleware ──→ AuthController.validateKey
    │   (X-API-Key)                   │                            │
    │                           Entidad::where(dominio, $key)       │
    │                                                              ├─ 200 { valid, bot_id, name, permissions }
    │                                                              └─ 401 { error }
    │
    ├─ GET /sailus/cuentas/{id} ──→ SailusCuentasController.show ──→ ShowEntidadUseCase
    │   (Bearer token)                                              │
    │                                                        Entidad::find($id)
    │                                                              └─ 200 { id, nombre, plan_type, status }
    │
    ├─ GET /entidad/{id}/servicios ──→ EntidadServicioController.index
    │   (Bearer token)                            │
    │                              Servicio::where(entidad_id, $id)
    │                                   →where(estado, 'Activo')
    │                                   →withCount('detalles')
    │
    └─ GET /plans (public) ──→ ProductoController.plans
                                    │
                            Producto::where('tipo', 'suscripcion')
                                 →where('estado', 'Activo')
```

```
WordPress Webhook
    │
    POST /webhook/registration (public)
    │
    ┌── DB::transaction ──────────────────────────┐
    │  1. Find/validate plan by name+tipo          │
    │  2. Check email duplicate → 409 if exists    │
    │  3. Entidad::create(estado='Prospecto')      │
    │  4. Contacto::create(entidad_id, email,      │
    │                      diagnostico_data JSON)  │
    │  5. Servicio::create(entidad_id,             │
    │                      nombre=service_name,    │
    │                      estado='Activo')        │
    │  → 201 { org_id, contact_id, plan_id }       │
    │  → 500 on any rollback                      │
    └──────────────────────────────────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/API/AuthController.php` | Modify | Implement `validateKey()` reading request attributes set by middleware |
| `app/Http/Controllers/API/SailusCuentasController.php` | Create | SAIlus cuentas endpoint wrapping Entidad (show only) |
| `app/Http/Controllers/API/SailusWebhookController.php` | Create | Webhook registration: Entidad+Contacto+Servicio in transaction |
| `app/Http/Controllers/API/EntidadServicioController.php` | Create | List servicios activos by entidad with detalles_count |
| `app/Http/Controllers/API/ProductoController.php` | Modify | Add `plans()` method for public suscripcion listing |
| `app/Http/Requests/WebhookRegistrationRequest.php` | Create | Validates webhook payload (organization_name, contact_name, contact_email, plan_type, service_name, diagnostico_data) |
| `routes/api.php` | Modify | Add 5 new routes; cuentas alias under `/sailus/` prefix |
| `app/Models/Producto.php` | Modify | Add `tipo`, `descripcion`, `precio`, `caracteristicas` to fillable |
| `app/Models/Contacto.php` | Modify | Add `diagnostico_data` to fillable (JSON cast) |
| `database/migrations/xxxx_add_tipo_to_productos.php` | Create | Add `tipo` (varchar 50, default 'producto'), `descripcion` (text nullable), `precio` (decimal nullable), `caracteristicas` (json nullable) |
| `database/migrations/xxxx_add_diagnostico_to_contacto.php` | Create | Add `diagnostico_data` JSON nullable to contacto |
| `database/seeders/SailusPlansSeeder.php` | Create | Seed suscripcion-type products for development (follows BrandPermissionsSeeder pattern) |
| `tests/Feature/API/SailusIntegrationTest.php` | Create | Feature tests for all 5 endpoints |

## Interfaces / Contracts

### POST /api/v1/webhook/registration (public)
```json
// Request
{
  "organization_name": "Empresa SA",
  "contact_name": "Juan Pérez",
  "contact_email": "juan@empresa.com",
  "plan_type": "Plan Básico",
  "service_name": "Diagnóstico Inicial",
  "source": "wordpress",
  "diagnostico_data": { "score": 85, "category": "tech" }
}
// Response 201
{ "success": true, "data": { "org_id": 1, "contact_id": 5, "plan_id": 3, "status": "registered", "pdf_url": null } }
// Response 409
{ "success": false, "error": "El email ya está registrado." }
```

### GET /api/v1/sailus/cuentas/{id} (auth:sanctum)
```json
// Response 200
{ "success": true, "data": { "id": 5, "nombre": "Tecnoinnsoft", "plan_type": null, "status": "Activo" } }
```

### GET /api/v1/entidad/{id}/servicios (auth:sanctum)
```json
// Response 200
{ "success": true, "data": [{ "id": 1, "nombre": "Soporte", "vr_servicio": 500000, "estado": "Activo", "detalles_count": 3 }] }
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | validate-key returns 200/401 | RefreshDatabase + Sanctum token pattern (follows CuentaControllerTest) |
| Feature | Webhook registration: success, duplicate email, validation errors | RefreshDatabase, no auth needed |
| Feature | sailus/cuentas/{id}: existing, 404 | Sanctum token + Entidad factory |
| Feature | entidad/{id}/servicios: active, empty, 404 | Sanctum token + Servicio factory with entidad_id |
| Feature | /plans: returns filtered, empty | No auth, Producto factory with tipo/estado |
| Unit | Plan lookup by name+tipo | Producto model scope test |

## Migration / Rollout

1. Run migrations (add `tipo` to productos, `diagnostico_data` to contacto) — zero-downtime (new columns, nullable defaults)
2. Seed `SailusPlansSeeder` for dev environments
3. Deploy routes — new endpoints only, no existing route changes
4. SAIlus switches from 501 mock to real endpoint
5. No rollback needed for existing functionality; new columns are nullable

## Open Questions

- [ ] **Route conflict resolution**: Confirm `/api/v1/sailus/cuentas/{id}` is acceptable vs spec's `/api/v1/cuentas/{id}`. The latter collides with existing financial `CuentaController`.
- [ ] **plan_type for entidad**: The `cuentas` response includes `plan_type` (nullable). No entidad→plan mapping exists. Accept returning null until SAIlus defines the mapping?
- [ ] **pdf_url generation**: Spec returns `null` now. Future: generate from contact's diagnostico_data? Tracked separately.
