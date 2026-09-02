# PRD — Multi-app Access & Party Model (CRM)

**Versión:** 1.0
**Fecha:** 2026-07-30
**Estado:** Borrador para aprobación
**Owner:** Tecnoinnsoft
**Apps objetivo:** CRM, SAIlus, Marketing, Plugin WP, La Llave, BRP (asistencia psicosocial)

---

## 1. Resumen ejecutivo

Este PRD agrega al CRM la capacidad de ser **plataforma de identidad y autorización** para múltiples aplicaciones del ecosistema Tecnoinnsoft. Hoy el CRM solo maneja su propio login (Sanctum para un único front). Necesitamos que cualquier app externa pueda:

1. Autenticar usuarios contra el CRM
2. Validar tokens en cada request
3. Conocer qué apps tiene asignadas cada usuario y con qué rol
4. Centralizar la identidad humana sin importar el dominio (staff, proveedor, contacto comercial)

**Driver de negocio:** El cliente **Banco de Bogotá** (3.000 empleados) requiere que los psicólogos que prestarán el servicio BRP se autentiquen contra una plataforma unificada, no contra una app aislada.

**Restricción crítica:** BRP necesita consumir auth del CRM en **15 días corridos**. Este PRD debe estar implementado antes o en paralelo.

---

## 2. Contexto y problema

### Estado actual
- CRM maneja auth propia con Sanctum (token por usuario)
- Tabla `usuario` existe pero el modelo es solo "user de Tecnoinnsoft con acceso al CRM"
- Roles y permisos definidos pero atados al dominio CRM
- 3 aplicaciones internas referenciadas pero sin integración real:
  - SAIlus (gateway de integraciones)
  - Marketing (gestión de campañas)
  - Plugin WP (sitios públicos)
- 2 aplicaciones externas en proceso de migración:
  - BRP (asistencia psicosocial — driver de este PRD)
  - La Llave (gestión documental)
- Aplicación externa legacy: ninguna integrada

### Problemas que resuelve este PRD
1. **Silos de identidad:** cada app tiene su propia noción de "usuario". Si una persona es staff + proveedor + contacto comercial, vive en 3 lugares.
2. **Login fragmentado:** un psicólogo no puede usar el mismo login para BRP y para La Llave si ambas son apps distintas.
3. **Validación de tokens manual:** no hay endpoint para que apps externas validen un token.
4. **Asignación app-usuario inexistente:** hoy un usuario tiene rol global, no se modela "este user entra solo a BRP".
5. **Rotación mal gestionada:** ya pasó que una persona (Patricia) fue contacto de cliente, luego staff, y los datos quedaron dispersos. El modelo actual lo dificulta.

---

## 3. Objetivos

### Objetivos de producto
1. **CRM-OBJ-1:** Una persona puede tener N roles en N apps con un solo login
2. **CRM-OBJ-2:** Una app externa puede validar un token contra el CRM con <100ms de latencia
3. **CRM-OBJ-3:** Agregar/quitar una app para un usuario toma <1 minuto (admin)
4. **CRM-OBJ-4:** Soporte a apps que viven fuera de nuestra infra (plugin WP en host externo)
5. **CRM-OBJ-5:** Auditoría de quién accedió a qué app y cuándo

### Objetivos técnicos
1. **CRM-OBJ-T1:** Token Sanctum válido en cualquier app que valide contra `crm/api/v1/auth/validate-key`
2. **CRM-OBJ-T2:** Cache de 5 min para validaciones repetidas (no martillar DB)
3. **CRM-OBJ-T3:** Party Model implementado (tabla `personas`) sin romper tablas existentes
4. **CRM-OBJ-T4:** Cero acoplamiento fuerte con apps externas (BRP puede caer sin afectar CRM)

---

## 4. Alcance

### 4.1 In Scope (MVP — 5 días)

#### Multi-app access
- Tabla `apps` (catálogo de las 6 apps)
- Tabla `usuario_app` (pivot: qué apps ve cada usuario con qué rol)
- Endpoint `GET /api/v1/me/apps` → apps del usuario autenticado
- Endpoint `GET /api/v1/me/apps/{slug}/permisos` → permisos del usuario en esa app
- Middleware `EnsureUserHasApp` para proteger endpoints app-scoped
- Endpoint `POST /api/v1/auth/validate-key` → para apps externas que validan tokens
- Asignaciones iniciales seed: vos, Lorena, Patricia, Jaime

#### Party Model (mínimo)
- Tabla `personas` con campos unificados (nombre, apellido, identificación, email, teléfono)
- FK opcional `persona_id` en tabla `contacto` (compatibilidad con datos existentes)
- Migración de datos existentes en `contacto` → `personas`
- Las tablas `proveedor` y `colaborador` siguen independientes (NO se migran en MVP)
- Soporte para caso Patricia: una persona puede tener registro histórico en `contacto` (soft-reference) y registro operativo en `colaborador`

#### Auth para apps externas
- BRP puede llamar `validate-key` con un Bearer token del CRM y obtener `{user_id, email, apps, permisos}`
- El token Sanctum del CRM sirve para todas las apps que el usuario tenga asignadas

### 4.2 Out of Scope (post-MVP)
- Tabla `persona_contactos` (multi email/teléfono por persona)
- Roles granulares con CRUD desde UI
- Refactor de `colaborador` y `proveedor` para apuntar a `personas`
- Webhooks outbound a apps externas
- OAuth2 / OIDC (no necesario en MVP)
- 2FA
- Sesiones de UI admin para gestionar asignaciones (en MVP se hace por seeder/consola)
- Multi-tenant para datos del CRM (no es un SaaS, es instancia única)

---

## 5. Stack técnico

| Capa | Tecnología | Estado |
|---|---|---|
| Backend | Laravel 12 + PHP 8.2 | Existente |
| Auth | Sanctum 4.3 | Existente |
| DB | MariaDB prod / SQLite dev | Existente |
| Módulos | `nwidart/laravel-modules` | Existente |
| Cache | Database driver (default) | Existente |
| API | REST + JSON | Existente |
| Testing | PHPUnit 11.5 | Existente |

**No se introducen nuevas dependencias.**

---

## 6. Modelo de datos

### 6.1 Diagrama entidad-relación (delta)

```
                    ┌─────────────────────┐
                    │      personas       │  ← NUEVO
                    │  (Party central)    │
                    └──────────┬──────────┘
                               │ persona_id (FK opcional)
                               │
              ┌────────────────┼────────────────┐
              │                │                │
              ▼                ▼                ▼
        ┌──────────┐    ┌──────────┐    ┌─────────────┐
        │ contacto │    │colaborador│    │  proveedor  │ (existentes,
        │(existente│    │(existente)│    │  existente) │ sin cambios)
        └────┬─────┘    └─────┬────┘    └──────┬──────┘
             │                │                │
             │ entidad_id     │ entidad_id     │
             ▼                ▼                ▼
        ┌────────────────────────────────────────┐
        │             entidad                    │ (existente)
        │   (cliente / proveedor / propia)      │
        └────────────────────────────────────────┘

        ┌──────────────┐       ┌──────────────────┐
        │     apps     │       │   usuario_app    │  ← NUEVO
        │ (catálogo)   │◀──────│ (pivot)          │
        └──────────────┘       └─────────┬────────┘
                                        │ usuario_id
                                        ▼
                                  ┌──────────┐
                                  │ usuario  │ (existente)
                                  └──────────┘
                                        │
                                        │ rol_id
                                        ▼
                                  ┌──────────┐
                                  │  roles   │ (existente)
                                  └──────────┘
```

### 6.2 Tablas nuevas

#### `apps` (catálogo)

| Columna | Tipo | Constraints | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK | |
| `slug` | VARCHAR(50) | NOT NULL, UNIQUE | `crm`, `sailus`, `marketing`, `wp-plugin`, `la-llave`, `brp` |
| `nombre` | VARCHAR(100) | NOT NULL | Display name |
| `tipo` | ENUM | NOT NULL DEFAULT `'internal'` | `internal`, `external`, `customer` |
| `auth_type` | ENUM | NOT NULL DEFAULT `'sanctum'` | `sanctum`, `api_key` |
| `activo` | BOOLEAN | NOT NULL DEFAULT TRUE | |
| `descripcion` | TEXT | NULL | |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

**Seed inicial:**
```sql
INSERT INTO apps (slug, nombre, tipo, auth_type) VALUES
  ('crm',         'CRM Tecnoinnsoft',     'internal', 'sanctum'),
  ('sailus',      'SAIlus Gateway',       'internal', 'sanctum'),
  ('marketing',   'Marketing Manager',    'internal', 'sanctum'),
  ('wp-plugin',   'Plugin WordPress',     'external', 'sanctum'),
  ('la-llave',    'La Llave Documental',  'external', 'sanctum'),
  ('brp',         'BRP Asistencia',       'external', 'sanctum');
```

#### `usuario_app` (pivot: asignaciones)

| Columna | Tipo | Constraints | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK | |
| `usuario_id` | BIGINT UNSIGNED | NOT NULL, FK → `usuarios.id` | |
| `app_id` | BIGINT UNSIGNED | NOT NULL, FK → `apps.id` | |
| `rol_id` | BIGINT UNSIGNED | NOT NULL, FK → `roles.id` | |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

**Constraints:**
- `UNIQUE(usuario_id, app_id)` — un usuario tiene un único rol por app (si necesita múltiples roles en la misma app, son apps distintas conceptualmente)

**Índices:**
- `(usuario_id)` — para `me/apps`
- `(app_id)` — para admin listar usuarios de una app

**Seed inicial (casos confirmados):**
```sql
-- Tu usuario: super-admin en las 6 apps
INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES
  (1, 1, 1), (1, 2, 1), (1, 3, 1), (1, 4, 1), (1, 5, 1), (1, 6, 1);

-- Lorena (id 2): solo CRM, rol comercial
INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES
  (2, 1, 2);

-- Patricia (id 3): CRM operativo + BRP operativo
INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES
  (3, 1, 3), (3, 6, 3);

-- Jaime (id 4): CRM + Marketing, super-admin
INSERT INTO usuario_app (usuario_id, app_id, rol_id) VALUES
  (4, 1, 1), (4, 3, 1);
```

#### `personas` (Party Model)

| Columna | Tipo | Constraints | Notas |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK | |
| `identificacion_tipo` | VARCHAR(10) | NULL | CC, CE, NIT, etc. |
| `identificacion_numero` | VARCHAR(20) | NULL, UNIQUE (cuando no es null) | |
| `nombres` | VARCHAR(100) | NOT NULL | |
| `apellidos` | VARCHAR(100) | NOT NULL | |
| `email_principal` | VARCHAR(150) | NULL | |
| `telefono_principal` | VARCHAR(30) | NULL | |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

**Índices:**
- `(identificacion_numero)` — para migración desde `contacto`

### 6.3 Cambios en tablas existentes

#### `contacto` — agregar FK opcional

```sql
ALTER TABLE contacto ADD COLUMN persona_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE contacto ADD CONSTRAINT fk_contacto_persona 
  FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE SET NULL;
ALTER TABLE contacto ADD INDEX idx_contacto_persona (persona_id);
```

**Migración de datos:**
```sql
-- Backfill: para cada contacto existente, crear una persona y asociar
INSERT INTO personas (nombres, apellidos, email_principal, telefono_principal, identificacion_tipo, identificacion_numero)
SELECT nombres, apellidos, email, telefono, tipo_id, identificacion
FROM contacto
WHERE deleted_at IS NULL;

UPDATE contacto c
JOIN personas p ON p.identificacion_numero = c.identificacion
SET c.persona_id = p.id
WHERE c.persona_id IS NULL;
```

> **Nota:** Las tablas `proveedor` y `colaborador` **NO se modifican en MVP**. Quedan independientes. La migración se difiere a un change posterior.

---

## 7. Funcionalidad

### 7.1 Flujo: usuario se loguea y la app externa lo identifica

```
┌────────────┐                              ┌────────────┐
│  Usuario   │                              │    CRM     │
│ (cualquier │                              │            │
│   app)     │                              │            │
└─────┬──────┘                              └─────┬──────┘
      │                                          │
      │ POST /api/v1/auth/login                  │
      │ { email, password, device_name }         │
      ├─────────────────────────────────────────▶│
      │                                          │
      │  {                                        │
      │    token: "abc123...",                   │
      │    usuario: { id, email, nombres },      │
      │    apps: [                               │
      │      { slug: "crm", rol: "super-admin" },│
      │      { slug: "brp", rol: "psicologo" }   │
      │    ]                                     │
      │  }                                        │
      │◀─────────────────────────────────────────┤
      │                                          │
      │ (App externa guarda token localmente)    │
      │                                          │
      │ GET /api/v1/me/apps                      │
      │ Authorization: Bearer abc123...          │
      ├─────────────────────────────────────────▶│
      │                                          │
      │ [ { slug: "crm", ... }, { slug: "brp" }]│
      │◀─────────────────────────────────────────┤
      │                                          │
      │                                          │
      │ GET /api/v1/auth/validate-key           │ ◀── desde BRP backend
      │ Authorization: Bearer abc123...          │
      ├──────────────────────────────────────────┤
      │                                          │
      │ {                                        │
      │   valid: true,                           │
      │   usuario_id: 42,                        │
      │   email: "ana@psicologos.com",           │
      │   apps: [{ slug: "brp", rol: "psicologo"}],│
      │   permissions: ["brp.sesiones.marcar"]   │
      │ }                                        │
      │◀─────────────────────────────────────────┤
```

### 7.2 Endpoints nuevos

Todos bajo prefijo `/api/v1/`. Auth: Sanctum (excepto `/auth/login` y `/auth/validate-key` que validan por sí mismos).

| Método | Path | Auth | Descripción |
|---|---|---|---|
| POST | `/auth/login` | público | Login estándar. **Modificado:** ahora retorna `apps` |
| GET | `/auth/validate-key` | bearer | **Nuevo.** Usado por apps externas (BRP) para validar un token |
| GET | `/me` | sanctum | **Nuevo.** Datos del usuario autenticado (incluye apps) |
| GET | `/me/apps` | sanctum | **Nuevo.** Lista de apps del usuario |
| GET | `/me/apps/{slug}/permisos` | sanctum | **Nuevo.** Permisos del usuario en la app dada |
| GET | `/usuarios/{id}/apps` | sanctum (admin) | **Nuevo.** Apps asignadas a un usuario (vista admin) |
| POST | `/usuarios/{id}/apps` | sanctum (admin) | **Nuevo.** Asignar app+rol a usuario |
| DELETE | `/usuarios/{id}/apps/{app_id}` | sanctum (admin) | **Nuevo.** Quitar asignación |

### 7.3 Respuesta del login modificada

```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456...",
    "usuario": {
      "id": 42,
      "email": "psicologo@example.com",
      "nombres": "Ana",
      "apellidos": "Pérez"
    },
    "apps": [
      { "slug": "brp", "nombre": "BRP Asistencia", "rol": "psicologo" }
    ]
  }
}
```

### 7.4 Respuesta de `validate-key`

```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "psicologo@example.com",
    "apps": [
      { "slug": "brp", "rol": "psicologo", "rol_id": 5 }
    ],
    "permisos": ["brp.sesiones.marcar", "brp.asistencias.registrar"],
    "cached": false,
    "validated_at": "2026-07-30T14:23:45Z"
  }
}
```

**Comportamiento de cache:**
- Cache key: `auth:validate:{token_hash}`
- TTL: 300 segundos (5 min)
- Si el token es revocado antes del TTL, no se invalida (trade-off aceptable para MVP)
- Header `X-Cache: HIT|MISS` para debugging

---

## 8. Roles y permisos

### Modelo de roles
- **Roles globales** (no scoped a app): `super-admin` (acceso total), `comercial` (solo CRM), `operativo` (CRM módulos operativos), `brp-admin`, `brp-lider`, `brp-psicologo`
- **Flag `es_super_admin` en `roles`:** TRUE para `super-admin`, bypasea checks de `usuario_app`
- **Un usuario con `super-admin` puede no estar en `usuario_app`** (acceso por flag, no por asignación) — útil para casos de emergencia

### Permisos granulares (opcional en MVP)
- Tabla `permisos` (preexistente): mantiene la convención
- Los permisos se asignan a roles, no directamente a usuarios
- Tabla pivot `rol_permiso` (preexistente): mantiene la convención
- **En MVP, los permisos se consultan via JOIN, pero la autorización efectiva sigue siendo por rol + app**

### Decisión sobre Jaime (caso especial)
Jaime es super-admin pero quiere UI enfocada en CRM + Marketing. Dos opciones:
- **(A)** Asignarle las 6 apps y la UI filtra por foco (preferencia)
- **(B)** Asignarle solo 2 apps y si necesita más, se agrega

**Decisión confirmada: Opción A.** Más simple, mejor UX.

---

## 9. Fases de entrega

### Fase 1 — Schema (Días 1-2)

| Día | Entregable |
|---|---|
| 1 | Migración `apps` + seed + `usuario_app` + modelos Eloquent |
| 2 | Migración `personas` + FK en `contacto` + migración de datos existentes |

### Fase 2 — Multi-app access (Días 3-4)

| Día | Entregable |
|---|---|
| 3 | Middleware `EnsureUserHasApp` + `GET /me/apps` + `POST /login` modificado (retorna apps) |
| 4 | Endpoint `validate-key` con caché + tests de los 4 casos (vos/Lorena/Patricia/Jaime) |

### Fase 3 — Admin + seeding (Día 5)

| Día | Entregable |
|---|---|
| 5 | Endpoints admin (`GET/POST/DELETE /usuarios/{id}/apps`) + seed de asignaciones iniciales + tests E2E |

---

## 10. Métricas de éxito

| Métrica | Meta | Cómo medir |
|---|---|---|
| Latencia `validate-key` con cache hit | < 10ms | Load test |
| Latencia `validate-key` sin cache | < 100ms | Load test |
| Migración de contactos a personas | 100% sin pérdida | Test de regresión con datos reales |
| Login retorna apps correctas | 4/4 casos (vos, Lorena, Patricia, Jaime) | Tests Feature |
| Asignación app-usuario nueva | < 1min via admin endpoint | UAT manual |

---

## 11. Riesgos

| # | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | Migración de `contacto` a `personas` pierde datos | Baja | Alto | Backup pre-migración, dry-run, test con dataset real |
| R2 | Token cacheado 5min sigue válido tras revocación | Baja | Medio | Trade-off aceptado; TTL corto |
| R3 | Performance de `validate-key` degrada con miles de requests | Media | Alto | Caché obligatorio desde día 1 |
| R4 | Soft-delete en `contacto` colisiona con Party Model | Baja | Bajo | Diferido a change posterior (DA-7) |
| R5 | Roles mal asignados permiten acceso cross-app | Baja | Alto | Tests de autorización estrictos |
| R6 | Acoplamiento con BRP retrasa este PRD | Media | Alto | Contrato claro, BRP puede mockear `validate-key` en dev |

---

## 12. Decisiones arquitectónicas confirmadas

- **DA-1:** Apps externas validan tokens via `validate-key` (no OAuth2/OIDC en MVP).
- **DA-2:** `usuario_app` es la única fuente de verdad para asignación (no se combina con flag).
- **DA-3:** `super-admin` con flag bypasea checks de `usuario_app`.
- **DA-4:** `personas` solo se migra desde `contacto` en MVP; `colaborador` y `proveedor` quedan independientes.
- **DA-5:** Cache 5min en `validate-key` (aceptable trade-off).
- **DA-6:** Login retorna lista de apps en la misma respuesta (optimiza round-trips).
- **DA-7:** Soft-delete en `contacto` NO se implementa en MVP.

---

## 13. Anexo: Compatibilidad con BRP

### Contrato de `validate-key` (consumido por BRP)

**Request:**
```
GET /api/v1/auth/validate-key
Authorization: Bearer <token>
```

**Response exitosa (200):**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "psicologo@example.com",
    "apps": [
      { "slug": "brp", "rol": "psicologo", "rol_id": 5 }
    ],
    "permisos": ["brp.sesiones.marcar"],
    "cached": false,
    "validated_at": "2026-07-30T14:23:45Z"
  }
}
```

**Response con token inválido (401):**
```json
{
  "success": false,
  "error": "invalid_token",
  "message": "Token expired or revoked"
}
```

**Response con token válido pero usuario sin apps asignadas (200):**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "psicologo@example.com",
    "apps": [],
    "permisos": [],
    "cached": false,
    "validated_at": "2026-07-30T14:23:45Z"
  }
}
```

### Roles BRP pre-creados

```sql
INSERT INTO roles (slug, nombre, es_super_admin) VALUES
  ('brp-admin',     'BRP Admin',     FALSE),
  ('brp-lider',     'BRP Líder',     FALSE),
  ('brp-psicologo', 'BRP Psicólogo', FALSE);
```

---

**Próximo paso:** Aprobación de este PRD. Inicio de implementación con `/sdd-new multi-app-access` (4-5 días).