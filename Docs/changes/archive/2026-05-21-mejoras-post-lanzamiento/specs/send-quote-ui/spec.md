# send-quote-ui Specification

## Purpose

Replace the simple "Enviar" button with a full email composition UI. User writes a custom message, selects a contact (defaulting to the contact with Rol "Decisor"), and the email text is stored in the seguimiento record.

## Requirements

### Requirement: Email composition modal

When the user clicks "Enviar Cotización" (estado === Borrador), a modal MUST open instead of immediately sending.

#### Scenario: Open composition modal

- GIVEN an Oportunidad in "Borrador" state
- WHEN the user clicks "Enviar Cotización"
- THEN a modal opens with:
  - A textarea for the email body (pre-filled with a default message)
  - A dropdown to select the contact (default: contact with Rol "Decisor")
  - "Cancelar" and "Enviar" buttons

#### Scenario: Default contact selection

- GIVEN the Oportunidad has 3 contacts, one with `rol=Decisor`
- WHEN the composition modal opens
- THEN the contact dropdown defaults to that contact
- AND the user can change it to another contact

#### Scenario: Send with custom message

- GIVEN the user has typed a custom message in the body textarea
- WHEN they click "Enviar"
- THEN the system sends the email with that message as the body
- AND the seguimiento record stores the custom message in `notas` (not the canned version)

### Requirement: Backend accepts mensaje and contacto_id

The `POST /api/v1/oportunidades/{id}/enviar` endpoint MUST accept JSON body with `mensaje` (string) and `contacto_id` (integer, optional).

#### Scenario: Custom message in request

- GIVEN a POST to `/oportunidades/{id}/enviar` with `{"mensaje": "Estimado cliente...", "contacto_id": 5}`
- WHEN the request is processed
- THEN the email is sent to contacto_id=5's email
- AND the seguimiento `notas` field stores the custom message
- AND the Oportunidad estado changes to "Enviada"

#### Scenario: Backward compatible (no body)

- GIVEN a POST to `/oportunidades/{id}/enviar` with no body
- WHEN the request is processed
- THEN it behaves as before: sends to the Oportunidad's linked contacto
- AND the seguimiento `notas` stores the default message

#### Scenario: contacto_id validation

- GIVEN a POST with `contacto_id` that doesn't exist or doesn't belong to this entity
- WHEN the request is processed
- THEN the API returns 422 with error message

### Requirement: 422 error fixed

The current `POST /oportunidades/{id}/enviar` MUST accept the new body params without requiring `detalles` or other previously missing fields.

#### Scenario: No 422 on valid request

- GIVEN an Oportunidad with contactos and detail lines
- WHEN the client POSTs `{"mensaje": "text", "contacto_id": 5}`
- THEN the response returns 200 with success: true
- AND no 422 validation error occurs
