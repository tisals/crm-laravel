# User Identity Snapshot Specification

## Purpose

Pre-computed read model that returns a user's apps, scoped permissions, and
identity bundle in a single DB SELECT — replacing N+1 calls from BRP/Indicadores.

## Requirements

### Requirement: Bundle Payload

The system MUST return a JSON object containing `user`, `apps[]`, `rol`,
`scope_label`, and `snapshot_at`.

#### Scenario: Fresh snapshot — happy path

- GIVEN a user with rol "Comercial" assigned to BRP via entidad
- WHEN `GET /api/v1/me/identity` is called with valid Bearer
- THEN the response contains the user, `apps` array with one entry
  (slug=brp), and `rol.slug="comercial"`
- AND `scope_label="v1"`

#### Scenario: Redis cache hit

- GIVEN a previous call within the TTL window
- WHEN the same user calls again
- THEN the system MUST return the cached payload without querying DB

### Requirement: Refresh Job

The system MUST run `crm:refresh-user-identity-snapshot` nightly at 03:30
America/Bogota via Laravel Scheduler.

#### Scenario: Scheduled execution

- GIVEN the scheduler is configured
- WHEN the cron fires at 03:30
- THEN one row per user is upserted with `scope_label="v1"`

### Requirement: Invalidation on Permission Mutation

The system MUST set `is_stale=1` on a user's `user_identity_snapshot`
row whenever an admin changes their scoped permissions.

#### Scenario: Permission grant

- GIVEN a user with empty BRP permissions
- WHEN admin POSTs to grant a permission
- THEN the snapshot row's `is_stale` becomes `1`

### Requirement: Fallback when Stale

The system MUST recompute the snapshot on the next read if `is_stale=1`.

#### Scenario: Stale flag triggers recompute

- GIVEN `is_stale=1` and Redis cache empty
- WHEN the user calls `/me/identity`
- THEN the system MUST recompute from DB and reset `is_stale=0`

#### Scenario: Degraded mode (Redis down)

- GIVEN Redis is unreachable
- WHEN the user calls `/me/identity`
- THEN the system MUST fall back to direct DB SELECT and return 200

## Out of Scope

- Per-user role override (added in a future change)
- Cross-tenant permission inheritance
