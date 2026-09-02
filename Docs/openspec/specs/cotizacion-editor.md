# Cotización Editor Specification

## Purpose

Provide a complete editing interface for cotización fields and detail lines (productos) on an Oportunidad, with contextual action buttons and a seguimiento timeline.

## Requirements

### Requirement: Update cotización fields

The system MUST allow updating all cotización fields on an Oportunidad via `PUT /api/v1/oportunidades/{id}`: `observaciones`, `aclaraciones`, `validez_oferta`, `tiempo_entrega`, `forma_pago`, `garantia`.

#### Scenario: Update all fields

- GIVEN an Oportunidad in "Borrador" state
- WHEN the client sends a PUT with all cotización fields
- THEN the response returns `success: true` with updated data
- AND each field is persisted and readable via GET

#### Scenario: Partial update

- GIVEN an Oportunidad with existing values
- WHEN the client sends PUT with only `observaciones`
- THEN only `observaciones` SHALL change; other fields remain unchanged

### Requirement: CRUD detail lines

The system MUST expose full CRUD for `detalle_oportunidad` lines via existing endpoints:
`GET|POST /oportunidades/{id}/detalles`, `PUT|DELETE /detalles-oportunidad/{id}`.

#### Scenario: Create detail line

- GIVEN an Oportunidad with `id=1`
- WHEN client POSTs to `/oportunidades/1/detalles` with `producto_id`, `cantidad`, `vr_unitario`
- THEN a new DetalleOportunidad is created with `vr_total = cantidad * vr_unitario` and `iva` calculated from the Producto's IVA rate
- AND the response returns `success: true` with the created line

#### Scenario: Auto-calculate vr_total

- GIVEN a detail line with `cantidad=3` and `vr_unitario=50000`
- WHEN the line is created or updated
- THEN `vr_total` MUST be `150000`

#### Scenario: Auto-calculate IVA

- GIVEN `vr_total=100000` and the selected Producto has `iva=19`
- WHEN the line is created
- THEN `iva` MUST be `19000`

#### Scenario: Product selector

- GIVEN the client needs a product catalog
- WHEN it calls `GET /api/v1/productos` (existing endpoint)
- THEN the response SHOULD return all active productos with `id`, `nombre`, `iva`

#### Scenario: Delete detail line

- GIVEN an existing DetalleOportunidad
- WHEN client sends DELETE to `/detalles-oportunidad/{id}`
- THEN the line MUST be soft-deleted
- AND response returns `success: true`

### Requirement: Sidebar contextual buttons

The sidebar on the opportunity detail MUST show action buttons based on estado:
- **Borrador**: Mostrar "Enviar"
- **Enviada**: Mostrar "Aprobar"
- **Aceptada**: Mostrar "Ganar"
- **Descargar PDF**: Mostrar siempre

#### Scenario: Buttons by estado (Borrador)

- GIVEN an Oportunidad with `estado=Borrador`
- WHEN the sidebar renders
- THEN it MUST show "Enviar" and "Descargar PDF" buttons
- AND it MUST NOT show "Aprobar" or "Ganar"

#### Scenario: Buttons by estado (Enviada)

- GIVEN an Oportunidad with `estado=Enviada`
- WHEN the sidebar renders
- THEN it MUST show "Aprobar" and "Descargar PDF" buttons
- AND it MUST NOT show "Enviar" or "Ganar"

### Requirement: Seguimiento timeline with query invalidation

The sidebar MUST display a vertical timeline of seguimientos for the current Oportunidad, ordered by `fecha` descending, with clear visual hierarchy (date, type, notes, author). When a quick-save seguimiento is submitted, the timeline MUST auto-refresh via query invalidation.

#### Scenario: Timeline with entries

- GIVEN an Oportunidad with 3 seguimientos
- WHEN the sidebar renders the timeline
- THEN all 3 entries SHALL appear sorted by `fecha` descending
- AND each entry SHALL show `tipo`, `fecha`, `notas`, and `autor.nombre`

#### Scenario: Empty state

- GIVEN an Oportunidad with zero seguimientos
- WHEN the sidebar renders
- THEN it SHALL display a "Sin seguimientos" message

#### Scenario: Timeline updates after quick save

- GIVEN the opportunity sidebar is open showing the timeline
- WHEN the user types a seguimiento and clicks "Enviar" in quick-save
- THEN the timeline section re-fetches and shows the new entry
- AND no manual page refresh is needed

#### Scenario: Correct query key invalidation

- GIVEN `queryClient.invalidateQueries` is called
- WHEN a quick-save completes
- THEN it MUST use `{ queryKey: ['seguimientos'] }` to match both `['seguimientos', 'byOportunidad', id]` and `['seguimientos', 'timeline', id]` queries

### Requirement: IVA sent in save payload

When saving detail lines (DetalleLineEditor), the system MUST include the `iva` field in the POST/PUT payload to the backend.

#### Scenario: IVA included on create

- GIVEN a DetalleLineEditor with a line where `producto_id=1`, `cantidad=3`, `vr_unitario=50000`, `iva=19`
- WHEN the user clicks "Guardar"
- THEN the frontend sends `iva: 19` in the POST to `/oportunidades/{id}/detalles`
- AND the backend calculates `vr_total` correctly

#### Scenario: IVA inherited from product

- GIVEN a user selects a Producto with `iva=19` in DetalleLineEditor
- WHEN the product dropdown selection completes
- THEN the line's `iva` field is auto-filled with the product's IVA (19)
- AND the user can override it manually

### Requirement: DetalleLineEditor uses empty string instead of 0

The DetalleLineEditor MUST initialize new lines with empty string values instead of 0 for `vr_unitario` and `iva`.

#### Scenario: New line has empty values

- GIVEN the user clicks "+ Agregar" in DetalleLineEditor
- WHEN a new line is created
- THEN `vr_unitario` is `""` (empty string) instead of `0`
- AND `iva` is `""` (empty string) instead of `0`
- AND input fields show empty instead of "0"
