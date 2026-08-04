# Integración SAIlus ↔ CRM (Multi-app Auth)

**Versión:** 1.0
**Fecha:** 2026-07-30
**Audience:** Equipo SAIlus (Python, gateway)
**Mantenedor:** Equipo CRM (crm-laravel)

---

## 1. Modelo de autenticación: 3 capas

El CRM expone endpoints en 3 categorías distintas. **SAIlus usa las 3** según el contexto:

| Capa | Tipo de token | Quién lo emite | Quién lo consume | Endpoint |
|---|---|---|---|---|
| **C1: Machine-to-Machine** | API Key estática (`X-API-Key`) | CRM (config) | SAIlus bot (server-side) | `GET /api/v1/auth/validate-key` |
| **C2: User Token Exchange** | Sanctum Bearer | CRM (a través de SAIlus) | SAIlus backend | `POST /api/v1/auth/token-exchange` |
| **C3: Frontend (vía SAIlus)** | JWT propio de SAIlus | SAIlus (Redis) | Frontend (PWA/React/Vue) | NO tocar CRM — SAIlus responde |

### Regla de oro

> **El frontend NUNCA llama al CRM directo. NUNCA contiene `X-API-Key`.**
>
> Front → SAIlus (con JWT propio) → CRM (con X-API-Key o Sanctum)

---

## 2. Flujo end-to-end (login de usuario)

```
┌──────────┐                              ┌──────────┐                              ┌──────────┐
│ Frontend │                              │  SAIlus  │                              │   CRM    │
│  (PWA)   │                              │ (Python) │                              │ (Laravel)│
└────┬─────┘                              └────┬─────┘                              └────┬─────┘
     │                                         │                                         │
     │ POST /auth/login                        │                                         │
     │ { email, password }                     │                                         │
     ├────────────────────────────────────────▶│                                         │
     │                                         │                                         │
     │                                         │ POST /api/v1/auth/token-exchange       │
     │                                         │ { email, password }                     │
     │                                         │ X-Internal-Source: sailus              │
     │                                         │ X-Request-ID: <uuid>                   │
     │                                         ├────────────────────────────────────────▶│
     │                                         │                                         │
     │                                         │ (CRM valida credenciales,              │
     │                                         │  emite token Sanctum,                  │
     │                                         │  registra en audit_log)                │
     │                                         │                                         │
     │                                         │ 200                                     │
     │                                         │ { token, usuario, apps, expires_at }   │
     │                                         │◀────────────────────────────────────────┤
     │                                         │                                         │
     │                                         │ (SAIlus genera su propio JWT,          │
     │                                         │  lo guarda en Redis con TTL)           │
     │                                         │                                         │
     │ 200                                     │                                         │
     │ { access_token: <JWT>,                  │                                         │
     │   expires_in: 3600,                     │                                         │
     │   user: {...} }                         │                                         │
     │◀────────────────────────────────────────┤                                         │
     │                                         │                                         │
     │ (peticiones subsiguientes)              │                                         │
     │                                         │                                         │
     │ GET /api/sailus/me/sesiones             │                                         │
     │ Authorization: Bearer <JWT>             │                                         │
     ├────────────────────────────────────────▶│                                         │
     │                                         │ (SAIlus valida JWT en Redis)            │
     │                                         │                                         │
     │                                         │ GET /api/v1/auth/validate-token        │
     │                                         │ Authorization: Bearer <sanctum-token>   │
     │                                         │ X-Internal-Source: sailus              │
     │                                         ├────────────────────────────────────────▶│
     │                                         │                                         │
     │                                         │ 200 { usuario_id, apps, permisos }     │
     │                                         │◀────────────────────────────────────────┤
     │                                         │                                         │
     │                                         │ (con permisos, llama a BRP, etc.)       │
     │                                         │                                         │
     │ 200 { sesiones: [...] }                 │                                         │
     │◀────────────────────────────────────────┤                                         │
```

---

## 3. Endpoints del CRM disponibles para SAIlus

### 3.1 `POST /api/v1/auth/token-exchange` ⭐ NUEVO

**Propósito**: SAIlus intercambia credenciales de usuario por un Sanctum token. Más estricto que `/auth/login` (audit log + throttling reforzado).

**Request**:
```
POST /api/v1/auth/token-exchange
Content-Type: application/json
X-Internal-Source: sailus
X-Request-ID: <uuid>

{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response 200 (success)**:
```json
{
  "success": true,
  "data": {
    "token": "1|abc123def456...",
    "usuario": {
      "id": 42,
      "email": "user@example.com",
      "nombres": "Juan Pérez"
    },
    "apps": [
      { "slug": "crm", "nombre": "CRM Tecnoinnsoft", "rol": "comercial", "rol_id": 2 }
    ],
    "entidades": [
      {
        "id": 7,
        "nombre": "Acme S.A.",
        "tipo_relacion": "asignado",
        "rol": "comercial",
        "apps": ["crm"]
      }
    ],
    "expires_at": "2026-07-30T16:00:00Z"
  }
}
```

**Response 200 — `entidades` field shape (extended in TE-8)**:

Each entity in `data.entidades` carries:
- `id` (int): internal entity id
- `nombre` (string): entity's display name
- `tipo_relacion` (string): always `"asignado"` or `"pertenece"` for entities that appear in the response (read-only `"consulta"` memberships are filtered out)
- `rol` (string|null): user's role in this entity, from `contacto.rol` or metadata fallback
- `apps` (string[]): intersection of apps the entity contracts AND apps the user is assigned to

The list is capped at **50 entities**. If the user has more than 50 matching entities, the response includes an `X-Total-Entidades` header with the true count so the caller can paginate:

```
HTTP/1.1 200 OK
X-Total-Entidades: 87
Content-Type: application/json

{ ... "entidades": [...50 items...] ... }
```

Soft-deleted entities are excluded. Users without a `persona_id` (no contact records to link them to entities) get an empty `entidades` array.

**Response 401 (credenciales inválidas)**:
```json
{
  "success": false,
  "error": "invalid_credentials",
  "message": "Invalid email or password"
}
```
> ⚠️ **NO revela** si el email existe o no. Mensaje genérico siempre.

**Response 400 (header faltante)**:
```json
{
  "success": false,
  "error": "missing_internal_source",
  "message": "X-Internal-Source header required"
}
```

**Throttling**: 10 requests/min por IP + 5 requests/10min por user (anti-bruteforce).

---

### 3.2 `GET /api/v1/auth/validate-token` ⭐ NUEVO

**Propósito**: SAIlus valida un Sanctum token antes de hacer una llamada al CRM en nombre del usuario.

**Request**:
```
GET /api/v1/auth/validate-token
Authorization: Bearer <sanctum-token>
```

**Response 200**:
```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "user@example.com",
    "apps": [
      { "slug": "crm", "rol": "comercial", "rol_id": 2 }
    ],
    "permisos": ["crm.leads.read", "crm.oportunidades.write"],
    "cached": false,
    "validated_at": "2026-07-30T15:00:00Z"
  }
}
```

**Cache**: 5 minutos. Key: `auth:validate_token:{sha256(token)}`.

**Throttling**: 120 requests/min por IP.

---

### 3.3 `GET /api/v1/auth/validate-key` (existente)

**Propósito**: SAIlus valida su API Key (operaciones machine-to-machine como webhooks).

**Request**:
```
GET /api/v1/auth/validate-key
X-API-Key: <secret>
```

> ⚠️ **Este secret solo vive en server-side (env vars de SAIlus). NUNCA en el frontend.**

---

### 3.4 `GET /api/v1/me/apps` ⭐ NUEVO

**Propósito**: SAIlus puede consultar qué apps tiene el usuario para enrutar la respuesta.

**Request**:
```
GET /api/v1/me/apps
Authorization: Bearer <sanctum-token>
```

---

### 3.5 `GET /api/v1/auth/roles` ⭐ NUEVO

**Propósito**: SAIlus (o el frontend) puede obtener el catálogo de roles del CRM para mapear `rol_id` → `nombre` legible (`"brp-lider"` en vez de `"2"`). Esencial para BRP/Hub para mostrar el nombre del rol en el dashboard.

**Request**:
```
GET /api/v1/auth/roles
Authorization: Bearer <sanctum-token>
X-Internal-Source: sailus
X-Request-ID: <uuid>
Accept: application/json
```

**Response 200**:
```json
{
  "success": true,
  "data": [
    {"id": 1, "nombre": "super-admin",    "descripcion": null},
    {"id": 2, "nombre": "comercial",     "descripcion": null},
    {"id": 3, "nombre": "operaciones",   "descripcion": null},
    {"id": 4, "nombre": "finanzas",      "descripcion": null},
    {"id": 5, "nombre": "brp-admin",     "descripcion": null},
    {"id": 6, "nombre": "brp-lider",     "descripcion": null},
    {"id": 7, "nombre": "brp-psicologo", "descripcion": null}
  ],
  "meta": {
    "total": 7,
    "validated_at": "2026-08-04T16:09:18+00:00"
  }
}
```

**Detalles**:
- **Auth**: `Bearer <sanctum-token>` (token del usuario) — NO usa `auth:sanctum` middleware; el use case valida el token manualmente
- **Throttling**: 120 req/min por IP (igual que `validate-token`)
- **Cache**: 5 minutos por hash del token; header `X-Cache: HIT|MISS`
- **Audit log**: cada llamada queda registrada en `auth_audit_log` con `event='roles.list.success'`, `request_id`, `metadata: {total, cached}`
- **Scope**: TODOS los roles del sistema (no filtra por usuario). BRP filtra client-side según `data.tenants[i].rol_id`
- **Cache sugerido en SAIlus**: 5 min en Redis bajo `auth:roles:{sha256(sanctum)}`

**Contrato SAIlus (equivalente)**:
```
GET /api/v1/auth/roles
Authorization: Bearer <frontend-jwt>
```
SAIlus debe:
1. Validar el JWT del frontend
2. Extraer el `sanctum_token` del payload del JWT
3. Llamar al CRM con ese token
4. Cachear el resultado 5 min en Redis
5. Devolver al frontend

**Errores**:
- 401 `missing_token` — no se envió header `Authorization`
- 401 — token inválido/expirado
- 429 `too_many_requests` — excedió 120 req/min

---

## 4. Configuración de SAIlus

### Variables de entorno (server-side de SAIlus, NUNCA exponer al frontend)

```bash
# URL del CRM (red Docker interna)
CRM_BASE_URL=http://crm-laravel:8001

# API Key de servicio (machine-to-machine)
CRM_API_KEY=<valor-de-MARKETING_API_SECRET>

# Headers que SAIlus SIEMPRE debe enviar
CRM_INTERNAL_SOURCE=sailus

# Redis para tokens JWT propios
SAILUS_JWT_TTL=3600  # 1 hora
SAILUS_REDIS_HOST=redis
SAILUS_REDIS_PORT=6379
```

### Setup inicial

```python
# sailus/config.py
CRM_BASE_URL = os.environ["CRM_BASE_URL"]
CRM_API_KEY = os.environ["CRM_API_KEY"]  # NUNCA loguear este valor
INTERNAL_SOURCE = "sailus"
```

---

## 5. Ejemplo de implementación SAIlus (Python/FastAPI)

```python
# sailus/auth/token_broker.py
import httpx
import jwt
import redis
from datetime import datetime, timedelta

CRM_URL = "http://crm-laravel:8001"
CRM_API_KEY = "***server-side only***"
REDIS = redis.Redis(host="redis", port=6379)
JWT_SECRET = "***server-side only***"
JWT_TTL = 3600

async def exchange_credentials(email: str, password: str, request_ip: str):
    """Llama al token-exchange del CRM, emite JWT propio para el frontend."""
    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{CRM_URL}/api/v1/auth/token-exchange",
            json={"email": email, "password": password},
            headers={
                "X-Internal-Source": "sailus",
                "X-Request-ID": str(uuid.uuid4()),
                "X-Forwarded-For": request_ip,
            },
        )
    
    if response.status_code != 200:
        # Audit log del intento fallido
        await log_auth_attempt(email=email, ip=request_ip, success=False)
        raise InvalidCredentials()
    
    crm_data = response.json()["data"]
    
    # Emitir JWT propio para el frontend
    expires_at = datetime.utcnow() + timedelta(seconds=JWT_TTL)
    jwt_payload = {
        "sub": crm_data["usuario"]["id"],
        "email": crm_data["usuario"]["email"],
        "crm_token": crm_data["token"],  # Sanctum token para llamadas futuras al CRM
        "apps": crm_data["apps"],
        "iat": datetime.utcnow(),
        "exp": expires_at,
    }
    access_token = jwt.encode(jwt_payload, JWT_SECRET, algorithm="HS256")
    
    # Guardar en Redis para validación
    REDIS.setex(
        f"sailus:jwt:{access_token[-16:]}",
        JWT_TTL,
        json.dumps(jwt_payload),
    )
    
    # Audit log exitoso
    await log_auth_attempt(email=email, ip=request_ip, success=True, user_id=crm_data["usuario"]["id"])
    
    return {
        "access_token": access_token,
        "expires_in": JWT_TTL,
        "user": crm_data["usuario"],
    }


async def validate_frontend_token(jwt_token: str):
    """Valida el JWT del frontend contra Redis."""
    payload = jwt.decode(jwt_token, JWT_SECRET, algorithms=["HS256"])
    redis_key = f"sailus:jwt:{jwt_token[-16:]}"
    cached = REDIS.get(redis_key)
    if not cached:
        raise InvalidToken()
    return payload


async def call_crm_on_behalf_of_user(jwt_token: str, path: str):
    """Helper para que SAIlus llame al CRM usando el Sanctum token del usuario."""
    payload = await validate_frontend_token(jwt_token)
    async with httpx.AsyncClient() as client:
        response = await client.get(
            f"{CRM_URL}{path}",
            headers={
                "Authorization": f"Bearer {payload['crm_token']}",
                "X-Internal-Source": "sailus",
            },
        )
    return response.json()
```

---

## 6. Errores comunes

| Código | Error | Causa | Solución |
|---|---|---|---|
| 400 | `missing_internal_source` | Header `X-Internal-Source` faltante | Agregar header en cada request SAIlus → CRM |
| 401 | `invalid_credentials` | Email/password incorrectos | Mensaje genérico, no revelar si email existe |
| 401 | `invalid_token` | Sanctum token expirado/revocado | Llamar a token-exchange de nuevo |
| 403 | `app_access_denied` | Usuario sin acceso a la app consultada | Filtrar UI por `/me/apps` |
| 429 | `too_many_requests` | Throttling activado | Backoff exponencial, retry-after header |
| 500 | `internal_error` | Error inesperado | Reportar a equipo CRM con `X-Request-ID` |

---

## 7. Audit log

Cada llamada a `token-exchange` queda registrada en la tabla `auth_audit_log` del CRM:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT | PK |
| `event` | VARCHAR(50) | `token_exchange.success`, `token_exchange.failed`, `token_exchange.throttled` |
| `email` | VARCHAR(150) | Siempre presente, incluso en fallos (lower-case) |
| `usuario_id` | BIGINT NULL | Solo si success |
| `ip` | VARCHAR(45) | IPv4 o IPv6 |
| `user_agent` | VARCHAR(500) | |
| `request_id` | VARCHAR(50) | UUID del header X-Request-ID |
| `metadata_json` | JSON | Detalles adicionales (motivo de fallo, throttling count) |
| `created_at` | TIMESTAMP | |

SAIlus puede consultar su propio audit log vía:
```
GET /api/v1/auth/audit?since=2026-07-30T00:00:00Z
X-API-Key: <secret>
```

---

## 8. Rate limits y políticas

| Endpoint | Rate limit | Ventana | Por |
|---|---|---|---|
| `POST /auth/token-exchange` | 10 | 60s | IP |
| `POST /auth/token-exchange` | 5 | 600s | Email (anti-bruteforce) |
| `GET /auth/validate-token` | 120 | 60s | IP |
| `GET /auth/validate-key` | 60 | 60s | IP |
| `GET /me/apps` | 60 | 60s | User |

Si se excede: HTTP 429 con header `Retry-After: <segundos>`.

---

## 9. Roadmap / Fase 2 (CRM)

Cuando SAIlus esté estable y listo, el CRM migrará a:
- **Sanctum para todo** (eliminar X-API-Key en producción)
- **OAuth2 client credentials** para apps externas (BRP, La Llave)
- **Rotación automática de secrets** vía Easypanel

Por ahora, **X-API-Key queda solo en server-side** (SAIlus en Docker, CRM en Docker). La red privada evita exposición.

---

## 10. Contacto y soporte

- **Issue tracker**: https://github.com/.../crm-laravel/issues (etiqueta `integration/sailus`)
- **Audit log del CRM**: tabla `auth_audit_log`, view `v_auth_audit_recent`
- **Cambios breaking**: avisar con 1 semana de anticipación por este documento

---

**Próximo paso para SAIlus**: implementar el broker según sección 5, usar `token-exchange` en lugar de `login`, y validar todo con los tests del CRM (`composer test --filter=TokenExchange`).