# Capability: oportunidad (delta)

## Purpose

This delta adds a "+ Seguimiento" affordance on each oportunidad card so comercials can register a contact action without leaving the kanban flow.

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
