# Exploration: CRUD API Completo para Entidades del Diccionario

## Current State Summary

### Tablas existentes (14 migraciones)

| Tabla | Columnas clave | Propósito |
|-------|---------------|-----------|
| `users` | id, name, email, password, rememberToken | Auth Sanctum |
| `personal_access_tokens` | id, tokenable(morphs), token, abilities | Sanctum tokens |
| `organizations` | id, name, industry, size, website, plan_type, status, api_key | Clientes SaaS |
| `contacts` | id, name, email(uniq), phone, organization_id, diagnostico_data(JSON), dominante_axis, presupuesto_range | Leads del webhook |
| `organization_services` | id, organization_id, service_name, status, schema_name, plan_id, plan_type, payment_type, max_users, active_users, license_status, trial_ends_at | Licencias SaaS |
| `plans` | id, name, slug(uniq), price, description, features(JSON), is_active, sort_order | Pricing tiers |
| `tags` | id, name, slug(uniq) | Etiquetado automático |
| `contact_tag` | contact_id, tag_id | Pivot tags↔contacts |
| `jobs`, `cache`, `sessions`, `password_reset_tokens` | — | Framework defaults |

### Modelos Eloquent (6)
- `User`, `Organization`, `Contact`, `OrganizationService`, `Plan`, `Tag`

### Entidades de Dominio (5)
- `Organization`, `Contact`, `OrganizationService`, `Plan`, `Tag`

### Repositorios (5 interfaces + 5 implementaciones Eloquent)
- `Contact`, `Organization`, `OrganizationService`, `Plan`, `Tag`

### Endpoints API existentes (`routes/api.php`)

**Públicos:**
- `GET /api/v1/plans` → listar planes activos
- `POST /api/v1/webhook/registration` → registro desde herramienta diagnóstico

**API Key (`X-API-Key`):**
- `GET /api/v1/auth/validate-key` → validar clave de organización

**Sanctum (`auth:sanctum`):**
- `GET /api/v1/contacts` — listar, `POST /api/v1/contacts` — crear, `GET /api/v1/contacts/{id}` — ver
- `GET /api/v1/organizations` — listar, `POST /api/v1/organizations` — crear, `GET /api/v1/organizations/{id}` — ver
- `GET /api/v1/services` — listar, `POST /api/v1/services` — crear, `GET/PUT/DELETE /api/v1/services/{id}`
- `PUT /api/v1/services/{id}/status` — cambiar estado de licencia
- `POST /api/v1/services/{id}/increment-users|decrement-users` — gestión de licencias

### Casos de Uso existentes (18)
- CRUD básico de contacts, organizations, services
- `RegisterFromWebhookUseCase` — flujo completo de registro webhook
- `ValidateApiKeyUseCase` — validación de API key por organización
- `GetActivePlansUseCase` — planes ordenados y filtrados

### Tests existentes (25 archivos)
- **Feature:** WebhookRegistrationTest, PlanControllerTest, ServiceStatusUpdateTest, OrganizationShowTest, AuthValidateKeyTest, PlanEnvelopeTest, EventServiceProviderTest
- **Unit:** Entidades (5), Repositorios (5), Webhook infrastructure (3), ApiResponse, Middleware auth

### Seeders (4)
- `DatabaseSeeder` — usuario de prueba
- `TestDataSeeder` — planes + org + contact + servicio + tags (canónico)
- `TestDataAdvancedSeeder` — 5 orgs realistas con múltiples contacts/services/tags
- `PlanSeeder`

---

## Desired State Summary

El diccionario define **19 tablas** organizadas en 7 dominios:

### 1. Seguridad y Accesos
- `roles` — id, nombre
- `permisos` — id, rol_id, vista
- `usuarios` — id, email(uniq), nombre, tel, password_hash, rol_id, estado(Activo/Inactivo)

### 2. Maestros Generales
- `ciudades` — cod_municipio(PK), municipio, tipo_municipio, cod_departamento, departamento, latitud, longitud
- `productos` — id, nombre, detalle, iva, linea_negocio
- `etiquetas` — id, nombre(uniq)

### 3. Directorio Empresarial
- `entidad` — id, tipo_persona(Natural/Juridica), tipo_id, identificacion(uniq), nombre, nombre_comercial, direccion, ciudad_cod, dominio, rut, logo, estado
- `lugares_entidad` — id, entidad_id, area_oficina, direccion, direccion_adicional, ciudad_cod, contacto_id
- `contacto` — id, entidad_id, nombres, apellidos, area, cargo, tel_contacto, movil, email_contacto, email_secundario, rol, etapa

### 4. Talento y Proveedores
- `colaboradores` — id, usuario_id, nombres, apellidos, tipo_id, identificacion(uniq), cargo, area, fecha_ingreso, fecha_retiro, contrato, estado
- `proveedores` — id, tipo_id, identificacion(uniq), nombres, apellidos, profesion, especialidad, iva, retenciones, ciudad_cod, fecha_registro

### 5. CRM (Comercial)
- `oportunidad` — id, codigo(uniq), entidad_id, contacto_id, fecha, fuente_canal, estado, observaciones, aclaraciones, validez_oferta, tiempo_entrega, forma_pago, garantia
- `detalle_oportunidad` — id, oportunidad_id, producto_id, concepto, medida, cantidad, vr_unitario, iva, vr_total
- `seguimiento` — id, oportunidad_id, contacto_id, entidad_id, tipo, fecha, hora, fecha_fin, notas, autor_id, estado

### 6. Operaciones (ERP)
- `servicios` — id, oportunidad_id, entidad_id, nombre, vr_servicio, fecha_inicio, fecha_fin, prestador_id, estado
- `detalle_servicios` — id, servicio_id, producto_id, observacion, cantidad, precio, descuento, sub_total, iva, total
- `orden_servicio` — id, detalle_srv_id, colaborador_id, proveedor_id, contacto_id, descripcion, objetivo, ubicacion, fecha_desde, fecha_hasta, valor, estado

### 7. Finanzas
- `cuentas` — id, proveedor_id, banco, numero_cuenta, tipo, estado
- `movimientos` — id, fecha, valor_debito, valor_credito, proveedor_id, colaborador_id, servicio_id, observaciones

---

## Conflict Matrix

### Tabla: `users` → `usuarios`

| Aspecto | Actual | Diccionario | Riesgo |
|---------|--------|-------------|--------|
| Nombre tabla | `users` | `usuarios` | **CRÍTICO** — Sanctum usa `tokenable_type='App\Models\User'` |
| Campos | name, email, password, rememberToken | nombre, email, tel, password_hash, rol_id, estado | **ALTO** — `$fillable`, factories, comandos |
| Rol | Sin tabla de roles | FK a `roles.id` | **MEDIO** — auth actual es plana |
| Estado | No tiene | ENUM Activo/Inactivo | **MEDIO** — lógica de login |

**Qué se rompe:**
- `personal_access_tokens` usa morphs `tokenable` → espera modelo `User` en tabla `users`. Cambiar nombre de tabla rompe TODOS los tokens existentes.
- `UserFactory` crea campo `name`; diccionario espera `nombre`.
- `GenerateApiToken` comando usa `User::firstOrCreate(['email' => ...])`.
- TODOS los tests crean usuario vía factory y generan token: `$user->createToken('test-token')`.

### Tabla: `organizations` → `entidad`

| Aspecto | Actual | Diccionario | Riesgo |
|---------|--------|-------------|--------|
| Nombre | `organizations` | `entidad` | **ALTO** — 14 referencias en código |
| Identificación | `name` (no único en BD, pero `firstOrCreate` lo usa) | `identificacion` (UNIQUE, NOT NULL) + `tipo_id` (NIT/CC/CE) | **CRÍTICO** — webhook busca por nombre |
| Tipo persona | No tiene | `tipo_persona` ENUM Natural/Juridica | **MEDIO** — nuevo campo obligatorio |
| Campos CRM | `industry`, `size`, `website`, `plan_type`, `api_key` | No existen | **CRÍTICO** — `api_key` es usada por `ValidateApiKeyMiddleware` |
| Campos ERP | No tiene | `nombre_comercial`, `direccion`, `ciudad_cod`, `dominio`, `rut`, `logo` | **MEDIO** — se agregan |
| Estado | ENUM prospecto/cliente/inactivo | VARCHAR default 'Activo' | **ALTO** — semántica completamente diferente |

**Qué se rompe:**
- `WebhookController` → `RegisterFromWebhookUseCase` usa `organizationRepository->findOrCreateByName()`.
- `ValidateApiKeyUseCase` busca `Organization::where('api_key', $key)`.
- `OrganizationController::store` valida `industry`, `size`, `website`.
- `TestDataSeeder` y `TestDataAdvancedSeeder` crean organizations con campos actuales.
- Todos los tests de Feature que usan `Organization::create([...])`.
- `OrganizationService` tiene FK `organization_id` con `cascadeOnDelete`.

### Tabla: `contacts` → `contacto`

| Aspecto | Actual | Diccionario | Riesgo |
|---------|--------|-------------|--------|
| Nombre | `contacts` | `contacto` | **ALTO** — 10+ referencias |
| Nombre | `name` (único campo) | `nombres` + `apellidos` (ambos NOT NULL) | **CRÍTICO** — webhook manda `name` |
| Email | `email` (UNIQUE) | `email_contacto` (sin unique en diccionario) | **ALTO** — webhook usa `email` como clave de idempotencia |
| Teléfono | `phone` | `tel_contacto` + `movil` | **MEDIO** — mapping simple |
| Relación org | `organization_id` | `entidad_id` | **ALTO** — FK a tabla diferente |
| Campos webhook | `diagnostico_data`(JSON), `dominante_axis`, `presupuesto_range` | No existen | **CRÍTICO** — core del webhook |
| Campos CRM | No tiene | `area`, `cargo`, `email_secundario`, `rol`, `etapa` | **BAJO** — se agregan |

**Qué se rompe:**
- `WebhookController` recibe `name` y `email` en payload; diccionario requiere `nombres` + `apellidos`.
- `Contact::updateOrCreate(['email' => ...])` en `RegisterFromWebhookUseCase`.
- `ContactController::store` valida `name`, `email`, `phone`.
- `contact_tag` pivot table referencia `contact_id`.
- Tests `WebhookRegistrationTest` verifican `assertDatabaseHas('contacts', [...])`.

### Tabla: `organization_services` vs `servicios` + `detalle_servicios`

| Aspecto | Actual (`organization_services`) | Diccionario (`servicios`) | Riesgo |
|---------|----------------------------------|---------------------------|--------|
| Concepto | Licencia SaaS por organización | Proyecto vendido (origen oportunidad) | **CRÍTICO** — son dominios diferentes |
| FK | `organization_id`, `plan_id` | `oportunidad_id`, `entidad_id`, `prestador_id` | **CRÍTICO** — relaciones distintas |
| Campos licencia | `max_users`, `active_users`, `license_status`, `trial_ends_at`, `schema_name` | No existen | **CRÍTICO** — `canAddUser()` desaparece |
| Campos ERP | No tiene | `vr_servicio`, `fecha_inicio`, `fecha_fin` | **MEDIO** — nuevos |
| Tabla hija | No tiene | `detalle_servicios` (líneas del proyecto) | **MEDIO** — nueva entidad |

**Qué se rompe:**
- `OrganizationService::canAddUser()` — lógica de negocio central.
- `OrganizationServiceController` — todos los endpoints de licencias (`increment-users`, `decrement-users`, `updateStatus`).
- `WebhookController` crea un servicio `crm` automáticamente al registrar.
- `TestDataSeeder` crea `la-llave` con `max_users=10`.
- Tests `ServiceStatusUpdateTest` verifican status de licencia.

### Tabla: `plans` vs `productos`

| Aspecto | Actual (`plans`) | Diccionario (`productos`) | Riesgo |
|---------|------------------|---------------------------|--------|
| Concepto | Pricing tiers SaaS | Catálogo de productos/servicios genéricos | **ALTO** — semántica diferente |
| Campos | `slug`(uniq), `price`, `description`, `features`(JSON), `is_active`, `sort_order` | `nombre`, `detalle`, `iva`, `linea_negocio` | **ALTO** — estructura diferente |
| Uso webhook | `plan_id` en payload se resuelve por `slug` | No tiene slug | **CRÍTICO** — `RegistrationRequest` valida `exists:plans,slug` |

**Qué se rompe:**
- `PlanController::index` — endpoint público que filtra `is_active=true` y ordena por `sort_order`.
- `RegisterFromWebhookUseCase` busca plan por slug: `$planRepository->findBySlug($data['plan_id'])`.
- Todos los tests crean Plan con `slug` antes de llamar webhook.

### Tabla: `tags` vs `etiquetas`

| Aspecto | Actual | Diccionario | Riesgo |
|---------|--------|-------------|--------|
| Nombre | `tags` | `etiquetas` | **MEDIO** — cambio de nombre |
| Campos | `name`, `slug`(uniq) | `nombre`(uniq) | **MEDIO** — eliminar slug |
| Pivot | `contact_tag` con timestamps | No definida en diccionario | **MEDIO** — relación N:M implícita |

**Qué se rompe:**
- `Tag::firstOrCreate(['name' => ..., 'slug' => ...])` en `TestDataSeeder` y webhook.
- `TagRepository::autoTag()` crea tags dinámicamente.

---

## Risk Assessment

### Riesgos CRÍTICOS (bloquean deploy inmediatamente si se renombran tablas)

1. **Sanctum Auth**: `personal_access_tokens` usa morph `tokenable` → `App\Models\User` → tabla `users`. Cambiar a `usuarios` rompe TODA la autenticación de la API. Todos los tests usan `$user->createToken()`.
2. **Webhook Registration**: Es el entrypoint principal del sistema. Cambiar `contacts`, `organizations`, `plans` o `organization_services` rompe el flujo de registro desde la herramienta de diagnóstico.
3. **API Key Middleware**: `ValidateApiKeyMiddleware` busca `api_key` en `organizations`. Si `organizations` → `entidad` sin `api_key`, FastAPI no puede autenticarse.
4. **License Logic**: `OrganizationService::canAddUser()` y el control de `trial_ends_at` son lógica de negocio que no existe en el diccionario. Perder esta tabla significa perder el control de licencias SaaS.

### Riesgos ALTOS

5. **Tests**: 25 archivos de test hacen `assertDatabaseHas('contacts', ...)`, `Organization::create([...])`, etc. Un rename masivo requiere reescribir ~80% de los tests.
6. **Seeders**: `TestDataSeeder` y `TestDataAdvancedSeeder` son la fuente de datos para desarrollo local. Dependen completamente del esquema actual.
7. **FastAPI Integration**: El frontend consume `GET /api/v1/plans`, valida API keys, y recibe webhooks outbound. Cambios de esquema rompen el contrato con el frontend.

### Riesgos MEDIOS

8. **Clean Architecture**: Los Domain Entities (`App\Domain\Entities\*`) y Repository Interfaces están acoplados a los nombres de campo actuales. Cambiarlos requiere actualizar 5 entidades, 5 interfaces, 5 implementaciones Eloquent, y 18 casos de uso.
9. **Outbound Webhooks**: `CrmWebhookSender` envía eventos `organization.created` y `contact.updated` con payloads basados en el esquema actual.

---

## Migration Strategy Options

### Opción A: Big Bang Replacement (Drop & Recreate)
**Descripción:** Eliminar todas las tablas existentes, crear las del diccionario, y reescribir todo el código (modelos, repositorios, controladores, tests, seeders) para usar los nuevos nombres y campos.

| Pros | Cons | Complejidad |
|------|------|-------------|
| Esquema único y limpio | Todo se rompe simultáneamente | **ALTA** |
| Sin duplicación de datos | Webhook offline durante semanas | **ALTA** |
| Single source of truth | ~150 archivos a modificar | **ALTA** |
| | Sanctum requiere hack o reemplazo | **ALTA** |
| | Imposible deploy incremental | **ALTA** |

**Veredicto:** NO recomendada. El sistema tiene integraciones activas (webhook + FastAPI) que no pueden tolerar downtime de semanas.

---

### Opción B: Parallel Schema (Tablas Nuevas + Coexistencia)
**Descripción:** Mantener TODAS las tablas existentes exactamente como están. Crear las 18+ tablas del diccionario como entidades NUEVAS con nuevos modelos, controladores, rutas y tests. El sistema opera con dos esquemas en paralelo.

| Pros | Cons | Complejidad |
|------|------|-------------|
| Cero breaking changes | Duplicación lógica temporal | **MEDIA** |
| Webhook sigue funcionando | Necesita sync entre `organizations`↔`entidad` | **MEDIA** |
| FastAPI no se ve afectado | Más tablas en BD | **BAJA** |
| Deploy incremental por fase | Doble mantenimiento durante transición | **MEDIA** |
| Se pueden migrar datos luego | | |

**Veredicto:** RECOMENDADA. Permite construir el ERP sin tocar el CRM/SaaS existente.

---

### Opción C: Incremental Evolution (Alterar tablas existentes)
**Descripción:** Mantener nombres de tabla actuales pero alterar columnas gradualmente (agregar nuevas, renombrar existentes, crear vistas). Usar `$table` property de Eloquent para mapear nombres si es necesario.

| Pros | Cons | Complejidad |
|------|------|-------------|
| Reutiliza modelos existentes | Estado intermedio muy sucio | **ALTA** |
| Menos migraciones nuevas | Cada alter rompe tests hasta arreglarlos | **ALTA** |
| | Algunas tablas son irreconciliables (ej: `organization_services` vs `servicios`) | **ALTA** |
| | Las factories y seeders necesitan versiones duales | **ALTA** |

**Veredicto:** NO recomendada. `organizations` vs `entidad` y `organization_services` vs `servicios` son demasiado diferentes para evolucionar gracefulmente.

---

### Opción D: Adapter Layer (Vistas + Modelos Proxy)
**Descripción:** Crear las tablas del diccionario físicamente, pero mantener los modelos actuales apuntando a vistas o proxies que traduzcan nombres de columnas.

| Pros | Cons | Complejidad |
|------|------|-------------|
| Mínimo cambio en controladores | Las vistas en SQLite/MySQL son de solo lectura o limitadas | **MEDIA** |
| | No resuelve el problema real, solo lo enmascara | **ALTA** |
| | Debugging más difícil | **MEDIA** |

**Veredicto:** NO recomendada. Añade complejidad sin resolver el diseño de dominio.

---

## Recommended Approach: Opción B — Parallel Schema con Fases

### Justificación

1. **Los dos esquemas representan dominios diferentes:**
   - **Esquema actual** = SaaS CRM (registro de leads vía webhook, gestión de licencias `organization_services`, planes de suscripción, API keys para FastAPI).
   - **Diccionario** = ERP completo (oportunidades, cotizaciones, proveedores, colaboradores, órdenes de servicio, finanzas).
   - Son **complementarios**, no mutuamente excluyentes. Una `Organization` puede ser cliente SaaS (`organizations`) Y cliente ERP (`entidad`).

2. **Preservar contratos activos:**
   - El webhook es un entrypoint de producción. No puede romperse.
   - FastAPI consume la API actual. No puede cambiar sin coordinación.
   - Sanctum está hardcodeado a `users`. Cambiarlo es un riesgo innecesario.

3. **Lógica de negocio irreemplazable:**
   - `OrganizationService::canAddUser()`, `trial_ends_at`, `schema_name` (multi-tenant FastAPI) no tienen equivalente en el diccionario. El ERP `servicios` es un proyecto con valor y fechas, no una licencia SaaS.

4. **Escalabilidad del trabajo:**
   - Con tablas paralelas, un equipo puede trabajar en el ERP (`entidad`, `contacto`, `oportunidad`) mientras otro mantiene el SaaS.

### Estrategia de implementación por fases

**Fase 1 — Maestros y Seguridad (bajo riesgo)**
- Crear: `roles`, `permisos`, `usuarios` (nuevo modelo, independiente de `users` para Sanctum), `ciudades`, `productos`, `etiquetas`
- Nota: `usuarios` puede coexistir con `users`. `users` sigue siendo el modelo de Sanctum; `usuarios` es el usuario del ERP con rol y estado.

**Fase 2 — Directorio Empresarial (riesgo medio)**
- Crear: `entidad`, `lugares_entidad`, `contacto`
- Construir CRUD APIs bajo `/api/v1/entidades`, `/api/v1/contactos`
- Agregar un `SyncService` opcional que cree un `entidad` automáticamente cuando se crea una `Organization` vía webhook (dual-write).

**Fase 3 — CRM Comercial (riesgo medio)**
- Crear: `oportunidad`, `detalle_oportunidad`, `seguimiento`
- Nota: `oportunidad` es el flujo de ventas formal. Puede vincularse a un `contacto` del diccionario.

**Fase 4 — Operaciones y Finanzas (riesgo bajo)**
- Crear: `servicios`, `detalle_servicios`, `orden_servicio`, `cuentas`, `movimientos`
- Nota: Estas son tablas puramente ERP. No chocan con `organization_services`.

**Fase 5 — Talento (riesgo bajo)**
- Crear: `colaboradores`, `proveedores`

**Fase 6 — Deprecación gradual (a futuro)**
- Una vez que el ERP está maduro y FastAPI migrado, evaluar si `organizations` y `contacts` se deprecan en favor de `entidad` y `contacto`.
- O mantener ambos para siempre: `organizations` = cliente SaaS, `entidad` = cliente ERP.

---

## Scope Estimate

### Si se implementa Parallel Schema (Opción B recomendada)

| Artefacto | Cantidad | Notas |
|-----------|----------|-------|
| **Nuevas migraciones** | ~18 | Todas las tablas del diccionario |
| **Nuevos modelos Eloquent** | ~18 | Una por tabla del diccionario |
| **Nuevas entidades de Dominio** | ~18 | Una por modelo (Clean Architecture) |
| **Nuevas interfaces de repositorio** | ~18 | Contracts en `App\Domain\Repositories\` |
| **Nuevas implementaciones Eloquent** | ~18 | En `App\Infrastructure\Persistence\` |
| **Nuevos casos de uso** | ~54 | Mínimo 3 por entidad (List, Create, Get). Para entidades complejas (oportunidad, servicios) se necesitan Update, Delete, y casos específicos |
| **Nuevos controladores API** | ~18 | En `App\Http\Controllers\API\` |
| **Nuevos Form Requests** | ~18 | Validación de store/update |
| **Nuevos Resources** | ~18 | Shaping de respuestas JSON |
| **Nuevos Feature tests** | ~36 | ~2 suites por entidad (CRUD básico + validaciones) |
| **Nuevos Unit tests** | ~18 | Entidades de dominio |
| **Nuevos seeders** | ~3-5 | Datos de prueba para ciudades, productos, roles |
| **Sync services** | ~2 | `OrganizationToEntidadSync`, `ContactToContactoSync` (opcional) |
| **Total archivos nuevos** | **~200+** | |

### Esfuerzo estimado

- **Fase 1 (Maestros):** 2-3 días — tablas simples, pocos FK
- **Fase 2 (Directorio):** 3-4 días — `entidad` y `contacto` tienen FK a ciudades
- **Fase 3 (CRM):** 4-5 días — `oportunidad` tiene detalle y seguimiento (relaciones anidadas)
- **Fase 4 (Operaciones+Finanzas):** 4-5 días — `servicios`, `detalle_servicios`, `orden_servicio`, `movimientos`
- **Fase 5 (Talento):** 2-3 días — `colaboradores`, `proveedores`, `cuentas`
- **Tests + Seeders:** 3-4 días
- **Total estimado:** **~18-24 días de trabajo enfocado**

---

## Affected Areas

| Archivo / Directorio | Por qué se ve afectado |
|----------------------|------------------------|
| `routes/api.php` | Nuevas rutas para ~18 entidades |
| `database/migrations/` | +18 migraciones nuevas |
| `database/seeders/` | Nuevos seeders para datos de prueba del ERP |
| `database/factories/` | Factories para nuevos modelos |
| `app/Models/` | +18 modelos Eloquent nuevos |
| `app/Domain/Entities/` | +18 entidades de dominio nuevas |
| `app/Domain/Repositories/` | +18 interfaces de repositorio |
| `app/Infrastructure/Persistence/` | +18 implementaciones Eloquent |
| `app/Application/UseCases/` | +54 casos de uso nuevos |
| `app/Http/Controllers/API/` | +18 controladores nuevos |
| `app/Http/Requests/` | +18 Form Requests nuevos |
| `app/Http/Resources/` | +18 Resources nuevos |
| `tests/Feature/API/` | +36 Feature tests nuevos |
| `tests/Unit/Domain/` | +18 Unit tests de entidades |
| `tests/Unit/Infrastructure/` | +18 Unit tests de repositorios |
| `app/Infrastructure/Webhook/` | Posible sync con nuevas entidades |

---

## Nota sobre `organization_services` vs `servicios`

**Son conceptos de negocio DISTINTOS y DEBEN coexistir:**

- **`organization_services`** (actual) = Suscripción SaaS. Controla `max_users`, `active_users`, `trial_ends_at`. Es la licencia que permite a una organización usar `la-llave`, `diagnostico`, `chat`. No tiene precio unitario ni fechas de ejecución.
- **`servicios` (diccionario)** = Proyecto vendido. Nace de una `oportunidad`. Tiene `vr_servicio` (valor total), `fecha_inicio`, `fecha_fin`, y un `prestador_id` (proveedor asignado). Se desglosa en `detalle_servicios` (líneas con cantidad, precio, iva).

**Relación propuesta:**
- Una `Organization` suscrita a `la-llave` tiene un `organization_service` con `service_name='la-llave'`.
- Esa misma `Organization` (mapeada a una `entidad`) puede tener múltiples `servicios` (proyectos ERP) contratados.
- Es decir: `organization_services` = "qué producto SaaS tiene activo"; `servicios` = "qué proyectos de consultoría/implementación contrató".

---

## Ready for Proposal

**Sí** — pero con una decisión de arquitectura que el orchestrador debe confirmar con el usuario:

> **¿Se implementa el diccionario como esquema PARALELO (nuevas tablas sin tocar las existentes) o como REEMPLAZO (drop de tablas actuales)?**

La exploración demuestra que un reemplazo puro es **extremadamente riesgoso** porque:
1. Rompe Sanctum (auth)
2. Rompe el webhook (entrypoint de producción)
3. Rompe la integración FastAPI (API keys)
4. Pierde la lógica de licencias SaaS (no existe en el diccionario)
5. Requiere reescribir ~200 archivos en un solo deploy

**Recomendación fuerte:** Implementar como **esquema paralelo** con fases, manteniendo `users`, `organizations`, `contacts`, `organization_services`, `plans`, `tags` intactos.
