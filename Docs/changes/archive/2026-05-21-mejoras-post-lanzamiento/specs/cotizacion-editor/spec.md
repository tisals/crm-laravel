# Delta for cotizacion-editor

## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: Seguimiento timeline refreshes after quick save

The quick-save seguimiento form MUST correctly invalidate the timeline query so the timeline component re-fetches.
(Previously: query invalidation used `['seguimientos']` but the timeline query key is `['seguimientos', 'timeline', id]`)

#### Scenario: Timeline updates after quick save

- GIVEN the opportunity sidebar is open showing the timeline
- WHEN the user types a seguimiento and clicks "Enviar" in quick-save
- THEN the timeline section re-fetches and shows the new entry
- AND no manual page refresh is needed

#### Scenario: Correct query key invalidation

- GIVEN `queryClient.invalidateQueries` is called
- WHEN a quick-save completes
- THEN it MUST use `{ queryKey: ['seguimientos'] }` to match both `['seguimientos', 'byOportunidad', id]` and `['seguimientos', 'timeline', id]` queries

### Requirement: Update cotización fields

(Unchanged from existing spec — the cotización field editor continues to work as specified.)
