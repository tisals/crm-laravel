# Delta for me-endpoints

## MODIFIED Requirements

### Requirement: Self-Service Endpoints

The system MUST expose endpoints under `/api/v1/me/*` for the
authenticated user to retrieve identity, apps, and permissions.
(Previously: only `GET /me/apps` and `GET /me/apps/{slug}/permisos` existed.)

#### Scenario: Identity bundle

- GIVEN a user with apps [brp, indicadores]
- WHEN `GET /api/v1/me/identity` is called with Bearer
- THEN the response MUST contain `{user, apps[2 items with each having
  its own permisos], scope_label, snapshot_at}`

#### Scenario: Plan de permisos

- GIVEN the same user
- WHEN `GET /api/v1/me/permisos` is called
- THEN the response MUST be the deduped union of core + app-scoped
  permissions across all the user's apps

#### Scenario: Existing apps endpoint still works

- GIVEN the same user
- WHEN `GET /api/v1/me/apps` is called
- THEN the response MUST continue to return the apps array (unchanged)

#### Scenario: Cache hit fast path

- GIVEN a previous call within the 5min Redis cache TTL
- WHEN any `/me/*` endpoint is called again
- THEN the system MUST serve from Redis cache (no DB SELECT)
- AND p95 latency < 80ms

#### Scenario: Stale snapshot recompute

- GIVEN `user_identity_snapshot.is_stale=1` for the user
- WHEN `/me/identity` is called
- THEN the system MUST recompute the snapshot, persist it, and serve
  fresh data within the same request

## Out of Scope

- WebSocket / SSE stream of identity changes (polling is fine)
- Per-device session revocation (separate change)
