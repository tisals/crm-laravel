# BRP Coordination Note: Token Endpoint Contract

> **Status:** open
> **Owner:** BRP team
> **Blocker for:** BRP go-live against the CRM
> **Reference PR:** `multi-app-access` (OpenSpec change)
> **Date:** 2026-07-31

---

## What changed

The CRM now exposes a NEW endpoint for external apps to validate Sanctum
tokens:

- `GET /api/v1/auth/validate-token`
- Authorization header: `Authorization: Bearer <sanctum-token>`
- Cached 5 minutes under the key prefix `auth:validate_token:{sha256(token)}`
- Throttle: 120 requests / minute per IP

This endpoint is intentionally separate from the legacy
`/api/v1/auth/validate-key` endpoint, which keeps its SAIlus-only
`X-API-Key` contract unchanged.

## What did NOT change

The response shape is identical to what BRP PRD §7.2 expected for
`validate-key`:

```json
{
  "success": true,
  "data": {
    "valid": true,
    "usuario_id": 42,
    "email": "ana@psicologos.com",
    "apps": [{ "slug": "brp", "rol": "psicologo", "rol_id": 5 }],
    "permisos": ["brp.sesiones.marcar"],
    "cached": false,
    "validated_at": "2026-07-30T14:23:45Z"
  }
}
```

Field names, types, and envelope semantics are unchanged. Only the route
path and the required header moved.

## What BRP needs to update

| Item | Before | After |
|---|---|---|
| Endpoint path | `validate-key` | `validate-token` |
| Header | `X-API-Key: <key>` | `Authorization: Bearer <sanctum-token>` |
| Response shape | unchanged | unchanged |

BRP PRD §7.2 references `validate-key`. Change it to `validate-token`.
The response shape (`user_id`, `apps`, `permisos`, `cached`,
`validated_at`) is unchanged — only the path name changed.

## Operational details

- **Cache**: 5 min TTL, key prefix `auth:validate_token:`
- **Throttle**: 120/min per IP
- **Returns 400** if `X-API-Key` header is sent without an
  `Authorization: Bearer` header (`error: bearer_required`). Do NOT
  conflate this with `validate-key`.
- **Returns 401** for missing/malformed/expired/revoked tokens
  (`error: invalid_token`).
- **Returns 200** even when the user has zero app assignments (the token
  is still valid; the apps list is just empty).

## Open tasks

- [ ] BRP team updates PRD §7.2 to use `validate-token` instead of `validate-key`
- [ ] BRP team replaces `X-API-Key` request header with
      `Authorization: Bearer <token>` in the integration client
- [ ] BRP team verifies the response envelope still parses (no change
      expected — only the path/header moved)
- [ ] Confirm cache invalidation trade-off is acceptable for BRP
      (revoked tokens may stay valid up to 5 minutes — `R2` in the
      OpenSpec proposal)