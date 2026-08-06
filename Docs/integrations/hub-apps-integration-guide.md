# HUB Apps Integration Guide

**Versión:** 1.0 (2026-08-06)
**Audience:** Equipos que construyen apps del HUB (BRP, Indicadores, Horas Extras, Estándares Mínimos)
**Mantenedor:** Equipo CRM (crm-laravel) + Equipo SAIlus
**Tiempo de setup esperado:** < 4 horas (cumple AC10 del `ADD-AUTH-001-multi-app-auth`)

> **TL;DR**: Tu app no se autentica contra el CRM directamente. Va contra **SAIlus** (un broker Python que cachea identidad). SAIlus es tu único punto de contacto. El CRM es el backend de identidad (source of truth) al que SAIlus consulta con `X-API-Key`.

## Arquitectura de 3 capas

```
┌──────────┐    Bearer JWT    ┌──────────┐    Bearer Sanctum   ┌────────────┐
│   BRP   │ ───────────────► │  SAIlus  │ ─────────────────► │  CRM       │
│ (frontend│  HTTP/JSON     │  gateway │  HTTP/JSON          │  Laravel   │
│  /API)   │                 │  (Python)│                     │  (master)  │
└──────────┘                 └──────────┘                     └────────────┘
      ▲                            ▲                                ▲
      │                            │                                │
   (HUB app)              (cache: auth:brp:                 (mysql_read
                          <sha256(jwt)>)                  para reads,
                          15 min TTL)                    mysql para writes)
```

**Por qué SAIlus en el medio** (no BRP directo al CRM):

1. **Cache de identidad**: BRP/HE/etc. hacen `me/identity` en cada request. Sin cache, eso sería 1 query DB por request al CRM. SAIlus cachea el response 15min en Redis (`brp:auth:sailus:<sha256(jwt)>`) y reduce el load en un 80%+.
2. **Outage policy**: si el CRM está caído, SAIlus sigue respondiendo con el cache (degraded mode) + warning log. BRP nunca se entera.
3. **Tenant resolution**: SAIlus resuelve el tenant del user (vía `entidad_usuario`) antes de pasar el request al controller. Tu app recibe `Tenant` ya resuelto, no tiene que hacer queries de membership.
4. **Single point of audit**: toda la actividad de auth pasa por SAIlus → log centralizado en `auth_audit_log` del CRM.

## Quick start: tu primer request autenticado

### 1. Login (BRP/Indicadores/HE/EM app, vía SAIlus)

```bash
# Request
curl -X POST https://sailus.tecnoinnsoft.com/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "psicologa@banco.example.com",
    "password": "changeme"
  }'

# Response
{
  "access_token": "eyJhbGciOiJIUzI1...",  # JWT firmado por SAIlus (no por el CRM)
  "expires_in": 3600,
  "token_type": "Bearer",
  "user": {
    "id": 42,
    "email": "psicologa@banco.example.com",
    "nombre": "Ana López"
  }
}
```

**Importante**: el `access_token` es un JWT firmado por **SAIlus**, no por el CRM. BRP/HE/etc. lo almacenan y lo usan en cada request subsiguiente. **NO** guardar tokens del CRM directamente.

### 2. Obtener identidad del user (con apps y permisos)

```bash
curl https://sailus.tecnoinnsoft.com/me/identity \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1..."

# Response
{
  "user": {
    "id": 42,
    "nombre": "Ana López",
    "email": "psicologa@banco.example.com",
    "rol": "psicologo"
  },
  "apps": [
    {
      "id": 6,
      "slug": "brp",
      "nombre": "BRP Asistencia",
      "permisos": ["brp.create", "brp.read", "brp.update", "contacto.read"]
    }
  ],
  "tenants": [
    {
      "id": 7,
      "nombre": "Banco de Bogotá",
      "apps": ["brp"]
    }
  ],
  "scope_label": "v1"
}
```

**SAIlus cachea** este response por 15 min. La próxima llamada del mismo user en los próximos 15 min NO toca el CRM.

### 3. Hacer un request autenticado a tu app

```bash
# BRP recibe un request, hace su propio check, llama al CRM si necesita
curl https://sailus.tecnoinnsoft.com/me/permisos \
  -H "Authorization: Bearer eyJhbGciOiJIUzI1..."

# Response: array plano de permisos
{
  "permisos": ["brp.create", "brp.read", "brp.update", "contacto.read"]
}
```

## Endpoints que tu app consume desde SAIlus

Todos los endpoints de SAIlus son **proxies** o **wrappers** de los del CRM. Tu app nunca llama al CRM directamente.

### Auth flow

| Endpoint SAIlus | Llama a (CRM) | Cache | Notas |
|-----------------|---------------|-------|-------|
| `POST /auth/login` | `POST /api/v1/auth/login` (CRM) | 0s | Devuelve JWT firmado por SAIlus |
| `POST /auth/logout` | `POST /api/v1/auth/logout` (CRM) | invalida cache | Revoca token del CRM |
| `GET /me/identity` | `GET /api/v1/me/identity` (CRM) | 15min | **El más usado** — trae apps + permisos + tenants |
| `GET /me/permisos` | `GET /api/v1/me/permisos` (CRM) | 15min | Versión plana sin metadata |

### Tenant flow

| Endpoint SAIlus | Notas |
|-----------------|-------|
| `GET /me/tenants` | Lista de entidades (empresas) a las que el user pertenece + apps disponibles en cada una |
| `POST /me/select-tenant` | Fija un tenant activo para el resto de la sesión (header `X-Tenant-Id` interno, **nunca a SAIlus**) |
| `GET /me/current-tenant` | Devuelve el tenant actualmente seleccionado |

### App-specific endpoints

Cada app (BRP, Indicadores, HE, EM) tiene su propio subpath. SAIlus solo rutea — tu lógica vive en tu app.

| Path | Notas |
|------|-------|
| `POST /brp/sesiones` | Crear sesión de BRP (ejemplo — los endpoints reales son específicos de tu app) |
| `GET /brp/sesiones/{id}` | BRP's endpoints internos |

> **SAIlus no conoce los endpoints internos de tu app.** Tu app define sus rutas y controladores. SAIlus solo provee el middleware de auth (`SailusAuthMiddleware`) que cachea identidad y resuelve tenant.

## Headers de cada request

```http
GET /brp/sesiones/123 HTTP/1.1
Host: sailus.tecnoinnsoft.com
Authorization: Bearer eyJhbGciOiJIUzI1...
X-Tenant-Id: 7                              # tenant activo (interno, seteado por /me/select-tenant)
X-Request-ID: 9c4e...                       # correlation ID para tracing
X-Forwarded-For: 192.168.1.1                # IP del cliente original
```

**Regla**: NUNCA setees `X-Tenant-Id` desde tu app cliente. Lo setea SAIlus después de `/me/select-tenant`. Si lo seteás manualmente, SAIlus lo ignora y asume el tenant por defecto.

## Caching: qué cachea SAIlus y por qué

| Key (Redis) | Contenido | TTL | Invalidation |
|--------------|-----------|-----|--------------|
| `brp:auth:sailus:<sha256(jwt)>` | User identity bundle (`me/identity` response) | 15min | Logout explícito, cambio de rol, cambio de app assignments |
| `brp:auth:roles:<rol_id>` | Catálogo de roles del user | 24h | Cambio de permisos del rol |
| `brp:auth:tenants:<user_id>` | Lista de tenants del user | 5min | Cambio de `entidad_usuario` |

**Outage policy** (lo que pasa si el CRM está caído):
1. SAIlus intenta `GET /me/identity` con timeout 2s
2. Si falla, busca en cache de Redis
3. Si cache hit: sirve con header `X-Sailus-Status: degraded`
4. Si cache miss: 503 con `Retry-After: 30`

## Ejemplo completo: BRP con Python + FastAPI

```python
# saIlus_client.py — el cliente Python que tu app usa para hablarle a SAIlus
import httpx
import os
from typing import Optional

class SAIlusClient:
    def __init__(self, base_url: str = "https://sailus.tecnoinnsoft.com"):
        self.base_url = base_url
        self.timeout = httpx.Timeout(2.0, connect=1.0)
        # API Key de tu app para hablarle a SAIlus (la consigues en /settings)
        self.app_api_key = os.environ["SAILUS_APP_API_KEY"]

    def login(self, email: str, password: str) -> dict:
        """Paso 1: user hace login. Devuelve JWT de SAIlus (no del CRM)."""
        r = httpx.post(
            f"{self.base_url}/auth/login",
            json={"email": email, "password": password},
            timeout=self.timeout,
        )
        r.raise_for_status()
        return r.json()  # {access_token, expires_in, user}

    def get_identity(self, jwt: str) -> dict:
        """Paso 2: cada request autenticado de tu app. Cache 15min server-side."""
        r = httpx.get(
            f"{self.base_url}/me/identity",
            headers={"Authorization": f"Bearer {jwt}"},
            timeout=self.timeout,
        )
        r.raise_for_status()
        return r.json()  # {user, apps[], tenants[], scope_label}

    def has_permission(self, identity: dict, app_slug: str, vista: str) -> bool:
        """Helper: chequea si el user tiene un permiso scopado a una app."""
        app = next((a for a in identity.get("apps", []) if a["slug"] == app_slug), None)
        if not app:
            return False
        return vista in app.get("permisos", [])

# brp_routes.py — el endpoint de tu app que usa SAIlus
from fastapi import APIRouter, Depends, HTTPException, Request
from saIlus_client import SAIlusClient

router = APIRouter()
sailus = SAIlusClient()

@router.get("/brp/sesiones")
def listar_sesiones(request: Request, current_user=Depends(get_current_user)):
    # get_current_user es tu middleware que ya validó el JWT via SAIlus
    # y popula request.state.user, request.state.apps, request.state.tenants

    if not any(p in current_user.apps[0]["permisos"] for p in ["brp.read", "brp.admin"]):
        raise HTTPException(403, "No tienes permiso brp.read")

    # Tu lógica de BRP — ya con el tenant resuelto
    sesiones = Sesion.query.filter_by(
        tenant_id=current_user.active_tenant_id
    ).all()
    return sesiones
```

## Testing tu integración antes de prod

### Smoke test local (5 min)

```bash
# 1. Tu app corriendo en localhost
# 2. SAIlus corriendo en localhost (sailus.local)
# 3. CRM corriendo con datos de seed (admin@tecnoinnsoft.dev / password)

# Test login
curl -X POST http://sailus.local/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@tecnoinnsoft.dev","password":"password"}'

# Test identity
JWT="<token del paso anterior>"
curl http://sailus.local/me/identity -H "Authorization: Bearer $JWT" | jq .

# Test tenant switching
curl -X POST http://sailus.local/me/select-tenant \
  -H "Authorization: Bearer $JWT" \
  -d '{"tenant_id": 1}'

# Test 403 cross-app (si user no tiene acceso a "indicadores")
curl http://sailus.local/indicadores/something -H "Authorization: Bearer $JWT"
# Expected: 403
```

### Test integration suite (recomendado)

| Test | Setup | Expected |
|------|-------|----------|
| Login OK | user con credenciales válidas | 200 + JWT |
| Login wrong password | 3 intentos consecutivos | 429 con `Retry-After` |
| Cross-app access | user con BRP, no Indicadores | 403 cuando llama a Indicadores |
| Tenant switch | user con 2 entidades | OK + header `X-Tenant-Id` actualizado |
| Outage CRM | SAIlus con cache hit, CRM caído | 200 con `X-Sailus-Status: degraded` |
| Outage CRM + cache miss | SAIlus con cache miss, CRM caído | 503 con `Retry-After: 30` |
| Cache TTL | mismo user, dos calls en 16 min | segundo call < 50ms (cache hit) |

## Checklist de deploy a producción

- [ ] Tu app tiene su propio `app_slug` registrado en el CRM (ej: `brp`, `indicadores`, `horas-extras`, `estandares-minimos`)
- [ ] Tu entidad cliente tiene contratada esa app en el CRM (`app_entidad` con `estado='Activo'`)
- [ ] Tu `app_api_key` (header `X-App-Key` cuando llamas a SAIlus server-to-server) está generada y guardada en tu secret manager
- [ ] Los permisos default del `rol` de tu user coinciden con lo que tu app espera (ej: `brp.create` para usuarios del rol Psicólogo)
- [ ] El frontend de tu app **NO** llama al CRM directamente — siempre va vía SAIlus
- [ ] El JWT de SAIlus se almacena en cookie HttpOnly + Secure (nunca en localStorage, XSS risk)
- [ ] Tu middleware de auth hace el `GET /me/identity` UNA VEZ al inicio del request, no en cada middleware hop
- [ ] Manejás `503` con `Retry-After` (degraded mode)
- [ ] El header `X-Request-ID` se loguea en cada request para tracing

## Cómo pedir cambios al contrato

Si tu app necesita un endpoint nuevo o un campo nuevo:

1. **Abrir un issue en `crm-laravel`** con tag `enhancement:integration`
2. Especificar: app (`brp`/`indicadores`/`he`/`em`), método, path, payload esperado
3. Tu caso de uso (por qué lo necesitás)
4. El equipo CRM lo evalúa contra el `ADD-AUTH-001-multi-app-auth.md` y el OpenAPI spec
5. Si es aceptado, se mergea al `main` con un sprint log

**Regla**: nuevos campos son siempre **opcionales** con default razonable (forward compat, AC11). Si necesitás un breaking change, se bumpa a `/api/v2/`.

## References

- `Docs/integrations/sailus-integration.md` — contrato SAIlus↔CRM (vista del gateway)
- `Docs/openapi/auth.yaml` — OpenAPI 3.1 spec del CRM (13 endpoints)
- `Docs/design/ADD-AUTH-001-multi-app-auth.md` — architecture decision (drivers AC10/AC11)
- `Docs/changes/multi-app-auth-identity/{proposal,specs/*}.md` — propuesta y specs
- `PRD-MultiApp-Access.md` — PRD original (en su mayor parte obsoleta, pero útil para contexto histórico)

## Soporte

- **CRM team**: issue tracker de crm-laravel, tag `integration`
- **SAIlus team**: Slack #sailus-integrations (o email según tu setup)
- **Onboarding help**: agendar con el CRM lead via #hub-onboarding

---

**TL;DR final**: tu app NO habla con el CRM. Habla con SAIlus. SAIlus cachea, resuelve tenant, y habla con el CRM en tu nombre. Empieza con login → me/identity → tu lógica de app. El OpenAPI spec en `Docs/openapi/auth.yaml` es la source of truth del contrato CRM; el `sailus-integration.md` describe cómo SAIlus lo consume.
