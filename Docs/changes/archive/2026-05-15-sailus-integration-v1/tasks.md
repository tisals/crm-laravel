# Tasks: SAIlus Integration v1

## Phase 1: Foundation (Migrations)

- [ ] 1.1 Create migration `add_tipo_to_productos_table` — add `tipo` enum('producto','suscripcion') default 'producto', `descripcion` text nullable, `precio` decimal nullable, `caracteristicas` json nullable
- [ ] 1.2 Create migration `add_diagnostico_data_to_contacto_table` — add `diagnostico_data` json nullable to `contacto`

## Phase 2: Existing Component Updates

- [ ] 2.1 Update `ValidateApiKeyUseCase::execute()` — return `bot_id` (id), `name` (nombre), `permissions` ([]) instead of organization_id/name
- [ ] 2.2 Implement `AuthController::validateKey()` — read attributes from middleware request, return SAIlus response envelope
- [ ] 2.3 Update `Producto` model fillable — add `tipo`, `descripcion`, `precio`, `caracteristicas`
- [ ] 2.4 Update `Contacto` model fillable — add `diagnostico_data` with `casts` as array

## Phase 3: Simple Endpoints

- [ ] 3.1 Create `ProductoController@plans()` — `GET /api/v1/plans`, public, filter tipo='suscripcion' AND estado='Activo', return id/name/price/description/features
- [ ] 3.2 Create `SailusEntidadController` with `show()` — `GET /api/v1/sailus/entidades/{id}`, protected auth:sanctum, use ApiResponse trait, return entidad mapped with plan_type=null
- [ ] 3.3 Create `EntidadServicioController` with `index()` — `GET /api/v1/entidad/{id}/servicios`, protected, filter estado='Activo', include detalles_count

## Phase 4: Complex Endpoint (Webhook Registration)

- [ ] 4.1 Create `WebhookRegistrationRequest` — validate organization_name, contact_name, contact_email (unique), plan_type, service_name, diagnostico_data
- [ ] 4.2 Create `SailusWebhookController@register()` — DB transaction: validate no duplicate email (409 if exists), lookup Producto by nombre+tipo='suscripcion', create Entidad (estado='Prospecto'), create Contacto with diagnostico_data, create Servicio (estado='Activo')
- [ ] 4.3 Verify 201 response: org_id, contact_id, plan_id, status, pdf_url(null); verify 409 on duplicate email

## Phase 5: Routes & Config

- [ ] 5.1 Register all 5 routes in `routes/api.php`: plans (public), sailus/entidades/{id}, entidad/{id}/servicios (sanctum), webhook/registration (public), auth/validate-key (already exists, verify response shape)
- [ ] 5.2 Seed `SailusPlansSeeder` — create suscripcion-type products for dev (Plan Básico, Plan Premium, Plan Enterprise)

## Phase 6: Tests

- [ ] 6.1 Create `SailusIntegrationTest` — test all 5 endpoints: validate-key (200/401), plans (filtered/empty), sailus/entidades/{id} (200/404), entidad/{id}/servicios (active/empty/404), webhook registration (201/409/validation errors)
