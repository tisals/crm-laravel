# Email Cotización Specification

## Purpose

Send a cotización via email with a PDF attachment when the user clicks "Enviar", automatically creating a follow-up log entry. Supports custom email message and contact selection.

## Requirements

### Requirement: Enviar cotización endpoint accepts custom payload

The system MUST provide `POST /api/v1/oportunidades/{id}/enviar` that sends the cotización email and updates estado. The endpoint MUST accept a JSON body with optional `mensaje` and `contacto_id` fields. If `contacto_id` is omitted, it defaults to the Oportunidad's linked contacto.

#### Scenario: Send email successfully with default contact

- GIVEN an Oportunidad in "Borrador" state with a linked Contacto that has `email_contacto`
- WHEN the client POSTs to `/oportunidades/{id}/enviar` with no body
- THEN the Oportunidad estado SHALL change to "Enviada"
- AND a `CotizacionMail` SHALL be queued to `contacto.email_contacto` with the PDF attached
- AND the response returns `success: true` with updated data

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

#### Scenario: Reject if already sent

- GIVEN an Oportunidad with `estado=Enviada`
- WHEN the client POSTs to `/oportunidades/{id}/enviar`
- THEN the system SHALL return `success: false` with HTTP 422
- AND the error message SHALL indicate "La cotización ya fue enviada"

#### Scenario: Reject if no contacto email

- GIVEN an Oportunidad with `contacto_id=null` or `contacto.email_contacto` is empty
- WHEN the client POSTs to `/oportunidades/{id}/enviar`
- THEN the system SHALL return `success: false` with HTTP 422
- AND the error message SHALL indicate "La oportunidad no tiene un contacto con email"

### Requirement: CotizacionMail mailable

The system MUST include a `App\Mail\CotizacionMail` mailable that:
- Uses the `Queueable` trait
- Attaches the generated PDF using DomPDF
- Uses subject "Cotización #{codigo}"
- Sends to the contacto's email
- Accepts an optional custom `mensaje` to display in the email body

#### Scenario: Mailable content

- GIVEN an Oportunidad with `codigo=COT-001`
- WHEN `CotizacionMail` is built
- THEN the subject MUST be "Cotización #COT-001"
- AND the email body SHALL include the custom message if provided, or a default message
- AND a valid PDF SHALL be attached

#### Scenario: Async delivery

- GIVEN the mail is queued
- WHEN `CotizacionMail` is dispatched
- THEN it MUST use the `queue` connection (not send synchronously)
- (Previously: mailable used `Queueable` trait but webhook called `Mail::send()` synchronously)

### Requirement: Auto-create seguimiento

When the email is sent, the system MUST auto-create a Seguimiento record. The `notas` field stores the custom message if provided, otherwise the default canned message.

#### Scenario: Seguimiento created

- GIVEN an Oportunidad is being sent via `/enviar`
- WHEN the email is queued successfully
- THEN a Seguimiento SHALL be created with `tipo=Correo`, `oportunidad_id`, `contacto_id`, `notas` containing the custom message (or "Cotización enviada por email" if none provided), and `fecha=now`
- AND the Seguimiento SHALL be persisted before the response

#### Scenario: Seguimiento with custom message

- GIVEN an Oportunidad is being sent via `/enviar` with `mensaje="Texto personalizado"`
- WHEN the email is queued successfully
- THEN a seguimiento SHALL be created with `tipo=Correo` and `notas` containing "Texto personalizado"
- AND the default canned message is NOT used

### Requirement: ICS calendar uses correct param

The `GET /seguimientos/calendar.ics` endpoint MUST accept `entidad_id` as an alternative filter param in addition to `contacto_id`.

#### Scenario: Filter calendar by entidad_id

- GIVEN a seguimiento linked to entidad_id=10
- WHEN the client calls `GET /seguimientos/calendar.ics?mes=2026-05&entidad_id=10`
- THEN the response includes only seguimientos where `entidad_id=10` AND `estado=Pendiente`

#### Scenario: Frontend sends correct param

- GIVEN the CRMPage sidebar renders the ICS download link
- WHEN the user clicks the .ics link
- THEN the URL uses `entidad_id` instead of `contacto_id` (consistent with the current behavior)
- AND the backend matches on `entidad_id`
