# Capability: seguimiento (delta)

## Purpose

The `seguimiento` capability registers and manages contact actions (Llamada, Correo, Reunion, Nota, Otro) attached to a contacto, oportunidad, and/or entidad. This delta closes 8 known backend gaps (canonical model, author auto-set, notification routing, fecha_fin validation, two new endpoints) and adds a frontend surface (cards + calendar) so comercials can manage their pending follow-ups.

## Requirements

### Requirement: Canonical model

The system MUST expose a canonical Eloquent model for `seguimiento` at `Modules\CRM\Models\Seguimiento.php` with relations to `oportunidad`, `contacto`, `entidad`, and `autor` (Usuario). The legacy alias `app/Models/Seguimiento.php` MUST continue to work by extending the canonical model.

#### Scenario: Canonical model is reachable
- WHEN any application code references `Modules\CRM\Models\Seguimiento`
- THEN it MUST resolve to the same model that `app/Models/Seguimiento` extends

#### Scenario: Relations load correctly
- WHEN a `seguimiento` is loaded with eager-loaded relations
- THEN `$seguimiento->oportunidad`, `$seguimiento->contacto`, `$seguimiento->entidad`, and `$seguimiento->autor` MUST return the corresponding Eloquent models (or null when the FK is null)

### Requirement: Author auto-set on create

When an authenticated user creates a seguimiento via `POST /api/v1/seguimientos` or `POST /api/v1/contactos/{id}/acciones`, the system MUST set `autor_id = Auth::id()` and `created_by = Auth::id()` automatically. Clients MUST NOT be allowed to set these fields.

#### Scenario: Autor populated automatically
- WHEN user with id=42 POSTs to `/api/v1/seguimientos` with a valid body
- THEN the stored row has `autor_id = 42` and `created_by = 42`

#### Scenario: Client-provided autor_id is ignored
- WHEN the request body contains `autor_id: 999` or `created_by: 999`
- THEN the system MUST ignore those values and use `Auth::id()` instead

### Requirement: Date range validation

When both `fecha` and `fecha_fin` are provided, `fecha_fin` MUST be greater than or equal to `fecha`. If the constraint is violated, the system MUST return HTTP 422 with a validation message.

#### Scenario: Valid date range
- WHEN request body has `fecha=2026-06-01` and `fecha_fin=2026-06-15`
- THEN the system accepts the request and stores both fields

#### Scenario: Invalid date range
- WHEN request body has `fecha=2026-06-15` and `fecha_fin=2026-06-01`
- THEN the system returns HTTP 422 with message referencing `fecha_fin`

#### Scenario: Only fecha provided
- WHEN request body has `fecha=2026-06-01` and no `fecha_fin`
- THEN the system accepts the request and `fecha_fin` is null

### Requirement: My seguimientos endpoint

The system MUST expose `GET /api/v1/seguimientos/mios?estado=&fecha_desde=&fecha_hasta=&tipo=&per_page=&page=` that returns seguimientos the current user is responsible for, scoped by role:

- For users with rol `Comercial`: only seguimientos linked to entidades in the user's `entidad_usuario` rows.
- For users with rol `Admin` or `SuperAdmin`: all seguimientos (no entity filter).

#### Scenario: Comercial queries own seguimientos
- WHEN a comercial with assigned entidades [10, 20, 30] calls `/api/v1/seguimientos/mios`
- THEN the response contains ONLY seguimientos where `entidad_id IN (10, 20, 30)` (or where `contacto_id` belongs to those entidades)

#### Scenario: Admin queries all seguimientos
- WHEN an admin calls `/api/v1/seguimientos/mios`
- THEN the response is equivalent to `GET /api/v1/seguimientos` (no entity filter)

#### Scenario: Filters compose
- WHEN a comercial calls `/api/v1/seguimientos/mios?estado=Pendiente&fecha_desde=2026-06-01`
- THEN the result is filtered by both: assigned-entities AND `estado='Pendiente'` AND `fecha >= 2026-06-01`

### Requirement: Calendar payload endpoint

The system MUST expose `GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM` that returns the calendar payload for a given user in a given month, with seguimientos grouped by day. The requester MUST be authorized to view the target user's calendar (themselves, or admin/superadmin).

#### Scenario: User queries own calendar
- WHEN user id=42 calls `/api/v1/usuarios/42/calendario?mes=2026-06`
- THEN the response returns seguimientos in June 2026 grouped by day (`{ "2026-06-01": [...], "2026-06-05": [...] }`)

#### Scenario: User queries another user's calendar (forbidden)
- WHEN user id=42 calls `/api/v1/usuarios/99/calendario?mes=2026-06` and id=42 is not admin
- THEN the system returns HTTP 403

#### Scenario: Admin queries any calendar
- WHEN an admin calls `/api/v1/usuarios/{any}/calendario?mes=YYYY-MM`
- THEN the system returns the calendar payload without restriction

### Requirement: Cards view

The frontend MUST render a cards view on `SeguimientosPage` where seguimientos are grouped by `estado` (Pendiente / Agendado / Completado / Cancelado). Each card MUST display:

- tipo badge (color-coded)
- fecha + hora
- notas (truncated to 2 lines)
- **empresa nombre** (from `seguimiento.entidad.nombre`)
- **oportunidad codigo** (from `seguimiento.oportunidad.codigo`) when present
- estado badge
- action buttons: Completar (only when Pendiente), Editar, Eliminar, Exportar a calendario (.ics)

#### Scenario: Cards render with required fields
- WHEN a seguimiento has `entidad_id=10` (empresa "Acme") and `oportunidad_id=5` (codigo "GC-01-2026-001")
- THEN the card shows "Acme" and "GC-01-2026-001" prominently

#### Scenario: Cards group by estado
- WHEN the SeguimientosPage loads with mixed seguimientos
- THEN sections appear in order: Pendiente, Agendado, Completado, Cancelado (or configurable sort)

### Requirement: Calendar view per comercial

The frontend MUST render a calendar view at `/seguimientos/calendario` showing the current comercial's seguimientos on a month grid, grouped by day. The view MUST default to the current user and the current month.

#### Scenario: Calendar shows month's seguimientos
- WHEN user id=42 navigates to `/seguimientos/calendario` in June 2026
- THEN the grid shows June 2026 with markers on each day that has at least one seguimiento for user 42

#### Scenario: Day click opens detail
- WHEN the user clicks on June 5 in the calendar
- THEN a panel opens listing that day's seguimientos with the same card UI as the cards view

#### Scenario: Month navigation
- WHEN the user clicks "next month"
- THEN the grid renders July 2026 and the query for that month's seguimientos fires

### Requirement: Debounced search

The search input on `SeguimientosPage` MUST debounce keystrokes by 1 second before firing the filter query. While the query is in flight, the previous data MUST remain visible (no full-page unmount).

#### Scenario: Rapid typing does not refire per keystroke
- WHEN the user types 5 characters in 500ms
- THEN the filter query fires ONCE, 1 second after the last keystroke

#### Scenario: Focus preserved during refetch
- WHILE the search query is in flight
- THEN the search input retains focus and the user's typed value is not erased

### Requirement: ICS export button

Each seguimiento card MUST have an "Exportar a calendario (.ics)" button that opens (or downloads) the `.ics` file from `GET /api/v1/seguimientos/{id}/ics`.

#### Scenario: Button generates .ics
- WHEN the user clicks "Exportar a calendario (.ics)" on a card
- THEN the browser opens or downloads `seguimiento-{id}-{yyyymmdd}.ics`

### Requirement: "+ Seguimiento" button on oportunidad card

The kanban (`CRMPage.tsx`) and the opportunity detail side panel MUST show a "+ Seguimiento" button on each oportunidad card. Clicking opens the create modal pre-filled with `oportunidad_id` (and `entidad_id` when known).

#### Scenario: Button is reachable from kanban
- WHEN the user hovers an oportunidad card in the kanban
- THEN a "+ Seguimiento" button is visible (icon + tooltip "Registrar seguimiento")

#### Scenario: Modal pre-fills opportunity
- WHEN the user clicks "+ Seguimiento" on card for oportunidad GC-01-2026-001
- THEN the create modal opens with `oportunidad_id=5` (the opp id) pre-filled, and the title says "Nuevo seguimiento para GC-01-2026-001"
