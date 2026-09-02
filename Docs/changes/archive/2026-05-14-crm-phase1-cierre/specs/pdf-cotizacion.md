# PDF Cotización Specification

## Purpose

Provide downloadable PDF generation for cotizaciones that faithfully renders the design from the HTML mockup in `Docs/cotizacion.html`.

## Requirements

### Requirement: PDF download endpoint

Use existing `GET /api/v1/oportunidades/{id}/pdf` to download the cotización as a PDF file.

#### Scenario: Download valid PDF

- GIVEN an Oportunidad with detalles and linked entidad/contacto
- WHEN the client calls GET `/oportunidades/{id}/pdf`
- THEN the response SHALL be a downloadable PDF file with Content-Type `application/pdf`
- AND the filename SHALL be `cotizacion-{codigo}.pdf`

### Requirement: Visual fidelity

The PDF SHALL render all data sections matching the HTML mockup `Docs/cotizacion.html`:
header (logo, slogan, cotización number, date, validity), entity/contact info, detail table, observations, aclarations, subtotal/IVA/total, forma_pago, garantía, tiempo_entrega, and brand signature.

#### Scenario: All sections present

- GIVEN an Oportunidad with all fields populated
- WHEN the PDF is generated
- THEN the PDF MUST include: header with logo + slogan + número cotización + fecha + validez, entity section, contact section, product table with all rows, observations, aclarations, subtotal/IVA/total section, forma de pago, garantía, tiempo de entrega, and brand footer

#### Scenario: Empty optional fields

- GIVEN an Oportunidad with `observaciones=null` and `aclaraciones=null`
- WHEN the PDF is generated
- THEN the PDF SHALL still render all sections, displaying blank/empty values for the null fields

#### Scenario: Table with zero lines

- GIVEN an Oportunidad with no DetalleOportunidad records
- WHEN the PDF is generated
- THEN the PDF SHALL still render with an empty table body
- AND the subtotal/total SHALL be $0

### Requirement: PDF generation for other actions

The same PDF generation logic SHALL be reused when:
- Approving a cotización (PDF is generated internally but not downloaded)
- Sending via email (PDF is attached to the mailable)

#### Scenario: PDF data reuse

- GIVEN data from `buildPdfData()` returns a consistent structure
- WHEN the PDF is generated from any context (download, approve, email)
- THEN all sections SHALL use the same `buildPdfData()` method to ensure consistency
