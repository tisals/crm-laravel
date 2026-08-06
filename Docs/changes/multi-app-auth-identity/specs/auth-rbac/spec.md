# Delta for auth-rbac

## MODIFIED Requirements

### Requirement: Permission Check (RbacMiddleware)

The system MUST validate that the authenticated user has the required
`vista` permission for the requested route, considering BOTH the global
permissions by rol AND any app-scoped overrides.
(Previously: system checked only `permisos WHERE rol_id=user.rol_id`.)

#### Scenario: Happy path — core permission

- GIVEN user with rol="Comercial" having `permisos.vista='contacto.index'`
- AND no `usuario_app_permisos` rows
- WHEN user calls `GET /api/v1/contacto`
- THEN access is granted (matched via global rol permission)

#### Scenario: Happy path — app-scoped override

- GIVEN user with rol="Comercial" lacking `contacto.update` globally
- AND `usuario_app_permisos` row granting it for app="brp"
- WHEN user calls a BRP route requiring `contacto.update` in app context
- THEN access is granted (matched via app-scoped override)

#### Scenario: Cross-app isolation

- GIVEN user with `usuario_app_permisos` granting "contacto.update" on "brp"
- AND NOT on "indicadores"
- WHEN user calls an indicadores route requiring `contacto.update`
- THEN the system MUST return 403 (no cross-app leak)

#### Scenario: SuperAdmin bypass

- GIVEN user with rol.es_super_admin=true
- AND no permissions anywhere
- WHEN user calls any ruta protegida
- THEN access MUST be granted (`vista='*'` wildcard)

#### Scenario: Redis down fallback

- GIVEN Redis unreachable
- WHEN `RbacMiddleware` evaluates permissions
- THEN the system MUST fall back to direct DB query and proceed

## REMOVED Requirements

(none)

## Out of Scope

- Automatic cross-app rol inheritance (admin must grant per app)
