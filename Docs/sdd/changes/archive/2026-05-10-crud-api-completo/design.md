# Design: CRUD API Completo — 19-Entity Domain Replacement

## Technical Approach

Big Bang schema replacement: drop 6 old tables, create 19 new ones from `Docs/Diccionario_entidades.md`. Reconfigure Sanctum auth to use `usuarios` model. Build full Clean Architecture stack (Domain → Application → Infrastructure → Interfaces) following existing Contact/Organization patterns exactly. Four sequential phases by domain group.

## Architecture Decisions

### Decision: Sanctum Auth Model

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Replace `User` model to point at `usuarios` | Minimal config change, breaks nothing since no UI exists | **Chosen** |
| Create custom guard with `UsuariosProvider` | More isolation, more complexity | Rejected |

**Rationale**: Sanctum's `auth.php` provider already uses `env('AUTH_MODEL')`. Changing the model class and adding a `$table = 'usuarios'` alias on the Eloquent model is the simplest path. The `password` column maps via `$hidden = ['password_hash']` + accessor.

### Decision: `ciudades` Primary Key

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Keep `cod_municipio` (VARCHAR) as PK from dictionary | Matches DANE codes, no surrogate waste | **Chosen** |
| Add surrogate `id` INT PK, make `cod_municipio` unique | Inconsistent with dictionary, adds unnecessary column | Rejected |

**Rationale**: Dictionary specifies `cod_municipio` as PK. All FK references (`entidad.ciudad_cod`, `lugares_entidad.ciudad_cod`, `proveedores.ciudad_cod`) already use this format. No migration needed for FK columns.

### Decision: Soft Delete Strategy

| Entity | Soft Delete | Rationale |
|--------|-------------|-----------|
| All 15 entities | `SoftDeletes` | Default — supports restore via `?trashed=true` |
| `ciudades` | None | Read-only reference data |
| `movimientos` | None | Financial audit trail — permanent record |
| `cuentas` | None | Bank account reference — delete is permanent |

### Decision: `oportunidad.codigo` Auto-Generation

**Pattern**: `OP-{YYYY}-{NNNN}` (e.g., `OP-2026-0001`). Use a DB transaction + `SELECT MAX(codigo) WHERE codigo LIKE 'OP-2026-%'` to get next sequence. Implemented in `GenerarCodigoOportunidoUseCase` called from `CreateOportunidadUseCase`.

### Decision: `detalle_oportunidad` Auto-Calculation

**Strategy**: Use Case computes totals before persisting. `vr_total = cantidad × vr_unitario`. `iva` = `cantidad × vr_unitario × (producto.iva / 100)`. Same pattern for `detalle_servicios` with `sub_total`, `iva`, `total`. Calculation logic lives in Application layer, NOT in Eloquent mutators.

### Decision: RBAC Implementation

**Strategy**: `RbacMiddleware` or Use Case checks `auth()->user()->rol_id` → query `permisos` where `vista = request->route()->getName()`. If no row exists, return 403. Vista string = route name (e.g., `entidad.index`).

### Decision: Drop Old Tables

**Strategy**: First migration drops all old tables (`users`, `organizations`, `contacts`, `organization_services`, `plans`, `tags`, `contact_tag`, `personal_access_tokens`, `password_reset_tokens`). Second migration creates all 19 new tables. This order prevents FK conflicts.

### Decision: `plans` Table

**Choice**: Drop it. Not in the dictionary. The new domain replaces the SaaS licensing model entirely. Plans can be re-added later if needed.

## Data Flow

```
HTTP Request
  → Controller (thin — validates via FormRequest, delegates to UseCase)
    → UseCase (business logic, RBAC check, auto-calc)
      → Repository Interface (Domain contract)
        → Eloquent Repository (Infrastructure — queries DB, maps to Entity)
      ← Domain Entity (pure PHP, no framework deps)
    ← Entity->toArray()
  ← ApiResponse trait (JSON envelope)
← HTTP Response { success, data, message?, error? }
```

## File Changes

### Layer 1: Domain (`app/Domain/`)

| File | Action | Description |
|------|--------|-------------|
| `Domain/Entities/Rol.php` | Create | Pure PHP entity |
| `Domain/Entities/Permiso.php` | Create | Pure PHP entity |
| `Domain/Entities/Usuario.php` | Create | Pure PHP entity (password_hash, email, rol_id) |
| `Domain/Entities/Ciudad.php` | Create | PK = cod_municipio |
| `Domain/Entities/Producto.php` | Create | |
| `Domain/Entities/Etiqueta.php` | Create | |
| `Domain/Entities/Entidad.php` | Create | |
| `Domain/Entities/LugarEntidad.php` | Create | |
| `Domain/Entities/Contacto.php` | Create | New schema (replaces old Contact entity) |
| `Domain/Entities/Colaborador.php` | Create | |
| `Domain/Entities/Proveedor.php` | Create | |
| `Domain/Entities/Oportunidad.php` | Create | |
| `Domain/Entities/DetalleOportunidad.php` | Create | |
| `Domain/Entities/Seguimiento.php` | Create | |
| `Domain/Entities/Servicio.php` | Create | |
| `Domain/Entities/DetalleServicio.php` | Create | |
| `Domain/Entities/OrdenServicio.php` | Create | |
| `Domain/Entities/Cuenta.php` | Create | |
| `Domain/Entities/Movimiento.php` | Create | |
| `Domain/Repositories/*RepositoryInterface.php` | Create ×19 | Standard CRUD contract: `findAll`, `findById`, `create`, `update`, `delete` |
| `Domain/Entities/Contact.php` | Delete | Old schema entity |
| `Domain/Entities/Organization.php` | Delete | Old schema entity |
| `Domain/Entities/OrganizationService.php` | Delete | Old schema entity |
| `Domain/Entities/Plan.php` | Delete | Old schema entity |
| `Domain/Entities/Tag.php` | Delete | Old schema entity |
| `Domain/Repositories/*Interface.php` | Delete ×5 | Old interfaces |

### Layer 2: Application (`app/Application/`)

| File | Action | Description |
|------|--------|-------------|
| `UseCases/*/Index*UseCase.php` | Create ×19 | Paginated list with search/sort/filter |
| `UseCases/*/Show*UseCase.php` | Create ×19 | Single record by ID |
| `UseCases/*/Store*UseCase.php` | Create ×19 | Create with validation delegates |
| `UseCases/*/Update*UseCase.php` | Create ×19 | Update existing record |
| `UseCases/*/Destroy*UseCase.php` | Create ×19 | Soft delete (or hard for ciudades/movimientos/cuentas) |
| `UseCases/Auth/LoginUseCase.php` | Create | Email+password → Sanctum token |
| `UseCases/Auth/LogoutUseCase.php` | Create | Revoke current token |
| `UseCases/Oportunidad/GenerarCodigoUseCase.php` | Create | `OP-{YYYY}-{NNNN}` generation |
| `UseCases/Oportunidad/GanarOportunidadUseCase.php` | Create | Auto-create Servicio on win |
| `Services/RbacService.php` | Create | Check permisos.vista for user's rol_id |
| `Services/CalculoDetalleService.php` | Create | Shared calc logic for detalle lines |
| `DTOs/LoginRequest.php` | Create | { email, password } |
| `DTOs/LoginResponse.php` | Create | { token, usuario } |
| All old UseCases | Delete ×17 | Replaced by new ones |

### Layer 3: Infrastructure (`app/Infrastructure/`)

| File | Action | Description |
|------|--------|-------------|
| `Models/Usuario.php` | Create | `$table = 'usuarios'`, HasApiTokens, password_hash accessor |
| `Models/Rol.php` | Create | |
| `Models/Permiso.php` | Create | |
| `Models/Ciudad.php` | Create | `$primaryKey = 'cod_municipio'`, `$incrementing = false` |
| `Models/Producto.php` | Create | |
| `Models/Etiqueta.php` | Create | |
| `Models/Entidad.php` | Create | |
| `Models/LugarEntidad.php` | Create | |
| `Models/Contacto.php` | Create | (replaces old Contact model) |
| `Models/Colaborador.php` | Create | |
| `Models/Proveedor.php` | Create | |
| `Models/Oportunidad.php` | Create | |
| `Models/DetalleOportunidad.php` | Create | |
| `Models/Seguimiento.php` | Create | |
| `Models/Servicio.php` | Create | |
| `Models/DetalleServicio.php` | Create | |
| `Models/OrdenServicio.php` | Create | |
| `Models/Cuenta.php` | Create | |
| `Models/Movimiento.php` | Create | |
| `Persistence/Eloquent*Repository.php` | Create ×19 | Implements repo interface, maps Model→Entity |
| `Auth/RbacMiddleware.php` | Create | Checks permisos table |
| `Models/Contact.php` | Delete | Old model |
| `Models/Organization.php` | Delete | Old model |
| `Models/OrganizationService.php` | Delete | Old model |
| `Models/Plan.php` | Delete | Old model |
| `Models/Tag.php` | Delete | Old model |
| `Models/User.php` | Delete | Replaced by Usuario |
| `Persistence/Eloquent*Repository.php` | Delete ×5 | Old repos |

### Layer 4: Interfaces (`app/Http/`)

| File | Action | Description |
|------|--------|-------------|
| `Controllers/API/*Controller.php` | Create ×19 | Thin controllers using ApiResponse trait |
| `Controllers/API/AuthController.php` | Rewrite | Login/logout with Usuario model |
| `Requests/*Request.php` | Create ×19 | FormRequest validation per entity |
| `Resources/*Resource.php` | Create ×19 | API Resource for response shaping |
| `Controllers/API/ContactController.php` | Delete | Old controller |
| `Controllers/API/OrganizationController.php` | Delete | Old controller |
| `Controllers/API/OrganizationServiceController.php` | Delete | Old controller |
| `Controllers/API/PlanController.php` | Delete | Old controller |
| `Controllers/API/WebhookController.php` | Delete | Deferred |

### Layer 5: Config & Routes

| File | Action | Description |
|------|--------|-------------|
| `config/auth.php` | Modify | `AUTH_MODEL` → `App\Models\Usuario` |
| `routes/api.php` | Rewrite | 19 entity groups + auth routes |

### Layer 6: Database

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/*_drop_old_tables.php` | Create | Drops 6 old + pivot tables |
| `database/migrations/*_create_roles_table.php` | Create | Phase 1 |
| `database/migrations/*_create_permisos_table.php` | Create | Phase 1 |
| `database/migrations/*_create_usuarios_table.php` | Create | Phase 1 |
| `database/migrations/*_create_ciudades_table.php` | Create | Phase 1 |
| `database/migrations/*_create_productos_table.php` | Create | Phase 1 |
| `database/migrations/*_create_etiquetas_table.php` | Create | Phase 1 |
| `database/migrations/*_create_entidad_table.php` | Create | Phase 2 |
| `database/migrations/*_create_lugares_entidad_table.php` | Create | Phase 2 |
| `database/migrations/*_create_contacto_table.php` | Create | Phase 2 |
| `database/migrations/*_create_colaboradores_table.php` | Create | Phase 2 |
| `database/migrations/*_create_proveedores_table.php` | Create | Phase 2 |
| `database/migrations/*_create_oportunidad_table.php` | Create | Phase 3 |
| `database/migrations/*_create_detalle_oportunidad_table.php` | Create | Phase 3 |
| `database/migrations/*_create_seguimiento_table.php` | Create | Phase 3 |
| `database/migrations/*_create_servicios_table.php` | Create | Phase 4 |
| `database/migrations/*_create_detalle_servicios_table.php` | Create | Phase 4 |
| `database/migrations/*_create_orden_servicio_table.php` | Create | Phase 4 |
| `database/migrations/*_create_cuentas_table.php` | Create | Phase 4 |
| `database/migrations/*_create_movimientos_table.php` | Create | Phase 4 |
| `database/seeders/RoleSeeder.php` | Create | Admin, Ventas, Operaciones, Finanzas |
| `database/seeders/CiudadSeeder.php` | Create | Colombian municipalities from DANE |
| `database/seeders/DatabaseSeeder.php` | Rewrite | New seeders only |

## Migration Order (FK Dependencies)

```
Phase 1 — Foundation:
  roles → permisos → usuarios → ciudades, productos, etiquetas (no FK deps)

Phase 2 — Directorio + Talento:
  entidad(ciudad_cod→ciudades) → contacto(entidad_id→entidad)
  → lugares_entidad(entidad_id, ciudad_cod, contacto_id)
  → colaboradores(usuario_id→usuarios)
  → proveedores(ciudad_cod→ciudades)

Phase 3 — CRM:
  oportunidad(entidad_id, contacto_id) → detalle_oportunidad(oportunidad_id, producto_id)
  → seguimiento(oportunidad_id, contacto_id, entidad_id, autor_id→usuarios)

Phase 4 — Operaciones + Finanzas:
  servicios(oportunidad_id, entidad_id, prestador_id→proveedores)
  → detalle_servicios(servicio_id, producto_id)
  → orden_servicio(detalle_srv_id, colaborador_id, proveedor_id, contacto_id)
  cuentas(proveedor_id) + movimientos(proveedor_id, colaborador_id, servicio_id)
```

## Interfaces / Contracts

### Repository Interface Pattern (all 19 follow this)

```php
interface EntidadRepositoryInterface
{
    public function paginate(int $perPage, ?string $search, array $filters): LengthAwarePaginator;
    public function findById(int $id): ?Entidad;
    public function create(array $data): Entidad;
    public function update(int $id, array $data): Entidad;
    public function delete(int $id): bool;
}
```

### Usuario Model — Sanctum Integration

```php
class Usuario extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'usuarios';
    protected $hidden = ['password_hash'];
    protected $casts = ['password_hash' => 'hashed'];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function rol(): BelongsTo { return $this->belongsTo(Rol::class); }
}
```

### Route Group Structure

```php
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        // 19 × Route::apiResource('entidad', EntidadController::class);
        // Nested: entidad/{id}/lugares, oportunidad/{id}/detalles, servicios/{id}/detalles
    });
});
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | All 19 CRUD endpoints (95 tests min) | `RefreshDatabase` + Sanctum token via `Usuario` factory |
| Feature | Auth flow (login, inactive user rejection) | Direct HTTP tests |
| Feature | Auto-calculation in detalle | POST with known values, assert computed fields |
| Feature | Oportunidad codigo generation | POST, verify `OP-{year}-{seq}` pattern |
| Feature | RBAC — 403 when permiso missing | Create usuario without permiso entry, assert 403 |
| Unit | Domain entities | Constructor + `toArray()` |
| Unit | CalculoDetalleService | Pure math tests |
| Unit | GenerarCodigoOportunidadUseCase | Sequence generation |

**Factory strategy**: One factory per model. `UsuarioFactory` creates users with hashed passwords. `CiudadFactory` uses real DANE codes.

## Migration / Rollout Plan

1. **Pre-flight**: Create git branch `feat/crud-api-completo`. Backup `database/database.sqlite`.
2. **Phase 1**: Drop old tables → create foundation tables → reconfigure `auth.php` → seed roles + ciudades + productos → run `php artisan test` (auth tests must pass).
3. **Phase 2-4**: Create tables in FK order → build Domain → Application → Infrastructure → Interfaces per entity → test per phase.
4. **Post-deploy**: `php artisan migrate:fresh --seed` → `php artisan test` (all 95+ tests pass).
5. **Rollback**: `git checkout main` + `migrate:fresh --seed` restores old schema.

## Open Questions

- [ ] Should `ciudades` seeder include all 1,122 Colombian municipalities or a subset for dev?
- [ ] `oportunidad.codigo` format: spec says `OP-{YYYY}-{XXXX}` — confirm 4-digit sequence is sufficient?
- [ ] `contacto` spec says "no duplicate email per entidad" — enforce via DB unique index on `(entidad_id, email_contacto)`?
- [ ] `OrdenServicio` spec says "at least colaborador_id OR proveedor_id required" — enforce via FormRequest or DB constraint?
