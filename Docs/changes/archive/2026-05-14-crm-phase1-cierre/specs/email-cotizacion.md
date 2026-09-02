# Email Cotización Specification

## Purpose

Send a cotización via email with a PDF attachment when the user clicks "Enviar", automatically creating a follow-up log entry.

## Requirements

### Requirement: Enviar cotización endpoint

The system MUST provide `POST /api/v1/oportunidades/{id}/enviar` that sends the cotización email and updates estado.

#### Scenario: Send email successfully

- GIVEN an Oportunidad in "Borrador" state with a linked Contacto that has `email_contacto`
- WHEN the client POSTs to `/oportunidades/{id}/enviar`
- THEN the Oportunidad estado SHALL change to "Enviada"
- AND a `CotizacionMail` SHALL be queued to `contacto.email_contacto` with the PDF attached
- AND the response returns `success: true` with updated data

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

#### Scenario: Mailable content

- GIVEN an Oportunidad with `codigo=COT-001`
- WHEN `CotizacionMail` is built
- THEN the subject MUST be "Cotización #COT-001"
- AND the email body SHALL include a message indicating the cotización is attached
- AND a valid PDF SHALL be attached

#### Scenario: Async delivery

- GIVEN the mail is queued
- WHEN `CotizacionMail` is dispatched
- THEN it MUST use the `queue` connection (not send synchronously)
- (Previously: mailable used `Queueable` trait but webhook called `Mail::send()` synchronously)

### Requirement: Auto-create seguimiento

When the email is sent, the system MUST auto-create a Seguimiento record.

#### Scenario: Seguimiento created

- GIVEN an Oportunidad is being sent via `/enviar`
- WHEN the email is queued successfully
- THEN a Seguimiento SHALL be created with `tipo=Correo`, `oportunidad_id`, `contacto_id`, `notas` containing "Cotización enviada por email", and `fecha=now`
- AND the Seguimiento SHALL be persisted before the response
