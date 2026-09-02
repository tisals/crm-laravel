# Delta for email-cotizacion

## MODIFIED Requirements

### Requirement: Enviar cotización endpoint accepts custom payload

The `POST /api/v1/oportunidades/{id}/enviar` endpoint MUST accept a JSON body with optional `mensaje` and `contacto_id` fields.
(Previously: endpoint accepted no body — it used the Oportunidad's linked contacto and a canned email message)

#### Scenario: Send with custom message and contact selection

- GIVEN an Oportunidad in "Borrador" state
- WHEN the client POSTs to `/oportunidades/{id}/enviar` with `{"mensaje": "Hola, adjunto cotización", "contacto_id": 5}`
- THEN the Oportunidad estado SHALL change to "Enviada"
- AND an email SHALL be queued to `contacto_id=5`'s email
- AND the email body SHALL contain the custom message
- AND a seguimiento SHALL be created with `notas` containing the custom message

#### Scenario: contacto_id validation

- GIVEN a POST with `contacto_id` that doesn't exist or belongs to a different entity
- WHEN the request is processed
- THEN the system SHALL return `success: false` with HTTP 422
- AND the error SHALL indicate "El contacto no pertenece a la entidad de la oportunidad"

### Requirement: ICS calendar uses correct param

The `GET /seguimientos/calendar.ics` endpoint MUST accept `entidad_id` as an alternative filter param in addition to `contacto_id`.
(Previously: only accepted `contacto_id`, but the frontend was sending `entidad_id`)

#### Scenario: Filter calendar by entidad_id

- GIVEN a seguimiento linked to entidad_id=10
- WHEN the client calls `GET /seguimientos/calendar.ics?mes=2026-05&entidad_id=10`
- THEN the response includes only seguimientos where `entidad_id=10` AND `estado=Pendiente`

#### Scenario: Frontend sends correct param

- GIVEN the CRMPage sidebar renders the ICS download link
- WHEN the user clicks the .ics link
- THEN the URL uses `entidad_id` instead of `contacto_id` (consistent with the current behavior)
- AND the backend matches on `entidad_id`

### Requirement: Auto-create seguimiento

(Unchanged from existing spec — seguimiento is auto-created with the same behavior, but `notas` now contains the custom message if provided.)

#### Scenario: Seguimiento with custom message

- GIVEN an Oportunidad is being sent via `/enviar` with `mensaje="Texto personalizado"`
- WHEN the email is queued successfully
- THEN a seguimiento SHALL be created with `tipo=Correo` and `notas` containing "Texto personalizado"
- AND the default canned message is NOT used
