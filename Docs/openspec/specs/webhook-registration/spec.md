# Webhook Registration Specification

## Purpose

Public endpoint receiving registrations from WordPress/Marketing tools. Creates organization (Entidad), contact (Contacto), and service (Servicio) atomically.

## Requirements

### Requirement: Registrar organización desde webhook externo

The system MUST expose `POST /api/v1/webhook/registration` (public, no auth). Body MUST include: `organization_name`, `contact_name`, `contact_email`, `plan_type`, `service_name`, `source`, `diagnostico_data`.

#### Scenario: Registro exitoso crea entidad + contacto + servicio
- GIVEN valid payload with organization_name, contact_name, contact_email, plan_type, service_name
- WHEN POST `/api/v1/webhook/registration`
- THEN a new Entidad is created with nombre=organization_name and estado='Prospecto'
- AND a new Contacto is created linked to that entidad, with nombres=contact_name and email_contacto=contact_email
- AND a new Servicio is created linked to that entidad, with nombre=service_name and estado='Activo'
- AND response MUST be 201: `{ success: true, data: { org_id, contact_id, plan_id, status: "registered", pdf_url: null } }`

#### Scenario: Email duplicado retorna error
- GIVEN a contact with email_contacto already exists
- WHEN POST `/api/v1/webhook/registration` with the same contact_email
- THEN response MUST be 409 with error message indicating duplicate email

### Requirement: Transacción atómica

The system MUST create Entidad, Contacto, and Servicio within a single database transaction.

#### Scenario: Fallo parcial revierte toda la transacción
- GIVEN a payload where Servicio creation would fail (missing required field)
- WHEN POST `/api/v1/webhook/registration`
- THEN no Entidad or Contacto rows are persisted
- AND response MUST be 500 with error message

---

## Lead Scoring (AssignScoreAction)

### Description

Acción de dominio encargada de calcular el score del contacto/lead basado en la información provista. El score resultante se guarda en la entidad o contacto correspondiente.

### Scoring Rules
- Cargo del contacto contiene "CEO", "Gerente", "Director", "Director General", "Fundador" -> Suma 30 puntos.
- Fuente/Canal es "Web", "Formulario", o "Recomendado" -> Suma 20 puntos.
- Si el dominio de la entidad es corporativo (no gmail/yahoo/etc) -> Suma 20 puntos.
- El score base inicial es 10 puntos.
- El score total calculado MUST estar en el rango de [0, 100].

---

## Laravel Ingestion Pipeline Pattern

### Description

El proceso de ingesta de un lead a través de los webhooks de SAIlus (FastAPI) o APIs directas se procesará utilizando el patrón `Illuminate\Pipeline\Pipeline` de Laravel para estructurar limpiamente las operaciones secuenciales.

### Pipeline Stages
1. `NormalizeLeadData`: Asegura la consistencia de caracteres, limpia teléfonos y normaliza campos UTM.
2. `ResolveOrCreateEntidad`: Busca si la empresa ya existe (por identificación o dominio) o la crea.
3. `ResolveOrCreateContacto`: Busca si el contacto existe por email en la entidad o lo crea.
4. `AssignLeadScore`: Ejecuta la acción `AssignScoreAction` sobre el contacto y actualiza su score.
5. `CreateOportunidad`: Si no existe una oportunidad activa para esta entidad/contacto, crea una nueva oportunidad en el pipeline `Llegada` con estado `Borrador`.

### Acceptance Criteria
- El endpoint `POST /api/v1/webhook/registration` y `POST /api/v1/contacto` MUST procesar las peticiones a través de este pipeline.
- Cualquier fallo en un paso del pipeline MUST abortar la transacción de base de datos y retornar un error formateado adecuado.
