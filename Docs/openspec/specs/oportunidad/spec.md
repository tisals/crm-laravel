# Capability: oportunidad (delta)

## Purpose

This delta adds a "+ Seguimiento" affordance on each oportunidad card so comercials can register a contact action without leaving the kanban flow.

---

## Multi-Pipeline Support

### Description

El modelo `Oportunidad` debe ser capaz de clasificarse en diferentes pipelines para separar las oportunidades del flujo comercial inicial de los flujos de rescate o reactivación.

### Fields and Schema
- `pipeline`: String. MUST be one of: `Llegada`, `Rescate`. Default: `Llegada`.
- `estado`: String/Enum. MUST support the following values depending on the pipeline:
  - For `Llegada` pipeline: `Borrador`, `Enviada`, `Aceptada`, `Rechazada`, `Ganada`, `Perdida`.
  - For `Rescate` pipeline: `Rescate_Inicial`, `Rescate_En_Progreso`, `Rescate_Exitoso`, `Rescate_Fallido`.

### Acceptance Criteria
- Una oportunidad nueva creada sin especificar `pipeline` SHALL pertenecer por defecto al pipeline `Llegada`.
- La base de datos MUST validar la transición de estados y asegurar integridad referencial.
- Si se intenta asignar un estado que no pertenece al pipeline actual de la oportunidad, se MUST arrojar una excepción de validación.

### Scenarios

#### S1: Transición válida dentro de un Pipeline
- GIVEN una oportunidad en el pipeline `Llegada` con estado `Borrador`
- WHEN el usuario cambia el estado a `Enviada`
- THEN el cambio SHALL ser exitoso
- AND el pipeline se mantendrá como `Llegada`

#### S2: Transición inválida entre estados de distintos Pipelines
- GIVEN una oportunidad en el pipeline `Llegada`
- WHEN el usuario intenta asignar el estado `Rescate_Inicial`
- THEN la operación MUST fallar con un error de validación (422)

---

## Requirements

### Requirement: Inline seguimiento action on oportunidad card

The oportunidad card in the kanban (`CRMPage.tsx`) and the opportunity detail side panel MUST show a "+ Seguimiento" button. Clicking it opens the create-seguimiento modal pre-filled with the oportunidad's `id` (and `entidad_id`).

#### Scenario: Button visible on kanban card
- WHEN the user hovers an oportunidad card
- THEN a "+ Seguimiento" button is rendered with icon and tooltip

#### Scenario: Modal pre-fills with oportunidad
- WHEN the user clicks "+ Seguimiento" on card for oportunidad id=5
- THEN the modal opens with `oportunidad_id=5` already set, and the title indicates the related opportunity

#### Scenario: Button also in the detail side panel
- WHEN the user opens the opportunity detail side panel
- THEN a "+ Seguimiento" button is visible alongside other actions (versionar, clonar, eliminar)
