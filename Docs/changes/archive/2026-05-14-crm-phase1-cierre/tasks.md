# Tasks: CRM Phase 1 — Cierre de Cotización

## Phase 1: Backend — Mailable & Email Foundation

- [ ] 1.1 Create `app/Mail/CotizacionMail.php` — ShouldQueue, subject "Cotización #{codigo}", DomPDF attachment from `buildPdfData()`
- [ ] 1.2 Create `resources/views/emails/cotizacion.blade.php` — email body with greeting + cotización summary + "adjunto PDF"
- [ ] 1.3 Register route `POST /oportunidades/{id}/enviar` in `routes/api.php` (cotización group)

## Phase 2: Backend — Enviar Endpoint & State Guards

- [ ] 2.1 Add `enviar(int $id)` to `CotizacionController`: guard estado=Borrador → buildPdfData → `Mail::to()->queue()` → auto `Seguimiento::create()` (tipo=Correo) → estado=Enviada
- [ ] 2.2 Add estado guard to `aprobar()`: 422 unless estado=Enviada
- [ ] 2.3 Add estado guard to `ganar()` in `OportunidadController`: 422 unless estado=Aceptada

## Phase 3: Frontend — Types & API Layer

- [ ] 3.1 Add `DetalleOportunidad`, `Producto`, `CotizacionData` interfaces to `types.ts`
- [ ] 3.2 Add to `crmApi.ts`: `enviarOportunidad()`, `getProductos()`, `getCotizacionData()`, detail CRUD helpers (`createDetalle`, `updateDetalle`, `deleteDetalle`), `getSeguimientos(per_page=50)`

## Phase 4: Frontend — Components

- [ ] 4.1 Create `CotizacionEditor.tsx` — form: validez_oferta, tiempo_entrega, forma_pago, garantia, observaciones, aclaraciones; auto-save on blur via `updateOportunidad`
- [ ] 4.2 Create `DetalleLineEditor.tsx` — table rows with inline product search dropdown (fetch `GET /productos`), cantidad/vr_unitario inputs, auto-calc vr_total+iva, add/delete row buttons
- [ ] 4.3 Create `ActionButtons.tsx` — sticky bottom bar: Enviar (estado=Borrador), Aprobar (Enviada), Ganar (Aceptada), Descargar PDF (always); each triggers mutation with loading/error state
- [ ] 4.4 Create `SeguimientoTimeline.tsx` — vertical timeline: dot + line + card per entry, sorted fecha desc, shows tipo icon, date, notas, autor_nombre; empty state "Sin seguimientos"

## Phase 5: Frontend — CRMPage Integration

- [ ] 5.1 Refactor sidebar in `CRMPage.tsx`: 3 collapsible sections (Cotización → Detalles → Timeline) + sticky ActionButtons at bottom; wire TanStack Query mutations for send/approve/win; replace current seguimientos list with SeguimientoTimeline

## Phase 6: Testing (PHPUnit)

- [ ] 6.1 Feature test: `POST /enviar` success — estado=Enviada, `Mail::fake()` + `Queue::fake()` assertions
- [ ] 6.2 Feature test: `POST /enviar` 422 — already sent (estado=Enviada) | no contacto email
- [ ] 6.3 Feature test: `POST /aprobar` 422 — estado != Enviada
- [ ] 6.4 Feature test: `POST /ganar` 422 — estado != Aceptada
- [ ] 6.5 Unit test: `CotizacionMail` subject "Cotización #COT-001" + PDF attachment present
