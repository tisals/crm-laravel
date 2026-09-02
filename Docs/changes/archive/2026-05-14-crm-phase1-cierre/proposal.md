# Proposal: crm-phase1-cierre

## Intent

Cerrar el módulo CRM fase 1 con la cotización completa: editor de todos los campos, líneas de detalle (productos), PDF, aprobación, envío por email y timeline de seguimientos.

## Scope

### In Scope
- Editor de cotización con todos los campos (validez, tiempo entrega, forma pago, garantía, observaciones, aclaraciones)
- CRUD de líneas de detalle (producto, cantidad, vr_unitario, iva)
- Botón descargar PDF → `GET /{id}/pdf`
- Botón aprobar → `POST /{id}/aprobar` → estado Aceptada
- Botón enviar → `POST /{id}/enviar` → estado Enviada + email con PDF adjunto
- Timeline de seguimientos en sidebar de oportunidad

### Out of Scope
- Multi-tenancy (fase 2)
- Planes/suscripciones (SAIlus endpoint)
- Traducción a inglés de endpoints

## Capabilities

### New Capabilities
- `cotizacion-editor`: Editor completo de cotización con campos + líneas de detalle
- `email-cotizacion`: Envío de cotización por email con PDF
- `pdf-cotizacion`: Generación y descarga de PDF

### Modified Capabilities
- `oportunidad-sidebar`: Agregar timeline de seguimientos + botones PDF/enviar/aprobar

## Approach

Backend: usar CotizacionController existente + agregar endpoint `enviar` + mailable.
Frontend: expandir sidebar de oportunidad con formulario completo de cotización + botones de acción + timeline seguimientos.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| DomPDF no renderiza igual que HTML | Med | Testear con layout real de cotización |
| Email sin queue worker bloquea respuesta | Baja | Usar `queue()` en mailable |

## Rollback Plan

Revert commits de frontend y backend por separado.

## Success Criteria

- [ ] Editor de cotización muestra todos los campos del HTML maqueta
- [ ] Se pueden agregar/quitar líneas de detalle con productos
- [ ] Descargar PDF genera archivo válido
- [ ] Aprobar cambia estado a Aceptada
- [ ] Enviar marca como Enviada + genera log de seguimiento
- [ ] Timeline de seguimientos visible en sidebar
