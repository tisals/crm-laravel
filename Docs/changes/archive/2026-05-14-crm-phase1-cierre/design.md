# Design: CRM Phase 1 — Cierre de Cotización

## Technical Approach

Extend `CotizacionController` with `enviar()` (PDF generation + queued mail + auto-seguimiento), add state-machine guards to `aprobar()`/`ganar()`, and create `CotizacionMail` (ShouldQueue). Frontend: expand CRMPage sidebar into scrollable collapsible sections — cotización editor, detail line CRUD, vertical timeline, contextual sticky action bar.

## Architecture Decisions

| Decision | Options | Choice | Rationale |
|----------|---------|--------|-----------|
| Email delivery | Direct `Mail::send()` vs `queue()` | `ShouldQueue` mailable via `Mail::to()->queue()` | AGENTS.md gotcha #6: synchronous mail blocks webhook response. Queue driver is already `database`. |
| State machine | Service class vs inline guards | Inline guard clauses in controller methods | 6 states, 3 transitions — a state machine library is overkill. Simple `if estado !== expected → 422` in each method. |
| Product selector UX | Modal picker vs inline autocomplete | Inline dropdown with search, per detail row | Product list is small. Inline is faster for adding multiple rows. Matches existing FilterBar entidad-search pattern. |
| Sidebar layout | Tabs vs collapsible sections | Vertical scroll with collapsible sections: Cotización → Detalles → Timeline → Acciones (sticky) | Natural top-to-bottom reading flow matching the PDF layout. Action bar sticky at bottom for always-visible CTAs. |
| Detail line controller | Reuse `DetalleOportunidadController` vs new endpoint | Reuse existing CRUD endpoints | `GET/POST /oportunidades/{id}/detalles`, `PUT/DELETE /detalles-oportunidad/{id}` already exist and are fully functional. |

## Data Flow

```
Sidebar Editor ──PUT /oportunidades/{id}──→ OportunidadController.update()
       │
       ├──POST /oportunidades/{id}/detalles──→ DetalleOportunidadController.store()
       ├──PUT /detalles-oportunidad/{id}─────→ DetalleOportunidadController.update()
       └──DELETE /detalles-oportunidad/{id}──→ DetalleOportunidadController.destroy()

Enviar ──POST /{id}/enviar──→ CotizacionController.enviar()
    ├── buildPdfData() → DomPDF raw output
    ├── Mail::to(contacto.email)→queue(CotizacionMail)   ← async
    ├── Seguimiento::create({tipo:"Correo", notas:"Cotización enviada…"})  ← sync
    └── estado = "Enviada"

Aprobar ──POST /{id}/aprobar──→ guard: estado=Enviada → estado=Aceptada
Ganar   ──POST /{id}/ganar────→ guard: estado=Aceptada → existing GanarUseCase
PDF     ──GET /{id}/pdf────────→ existing stream response
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Mail/CotizacionMail.php` | **Create** | Mailable implementing ShouldQueue; `envelope()` subject "Cotización #{codigo}"; `attachments()` returns DomPDF output; Blade view for HTML body |
| `resources/views/emails/cotizacion.blade.php` | **Create** | Email body: greeting + cotización details summary + "adjunto encontrará el PDF" |
| `app/Http/Controllers/API/CotizacionController.php` | **Modify** | Add `enviar(int $id)` method; add estado guard to `aprobar()` (must be Enviada); use `buildPdfData()` for PDF in memory |
| `app/Http/Controllers/API/OportunidadController.php` | **Modify** | Add estado guard to `ganar()` → only Aceptada allowed |
| `routes/api.php` | **Modify** | Add `POST /oportunidades/{id}/enviar` in cotización group |
| `dashboard-crm/src/api/crmApi.ts` | **Modify** | Add: `enviarOportunidad()`, `getProductos()`, `getCotizacionData()`, detail CRUD, `getSeguimientos()` with `per_page=50`; extend `updateOportunidad` for all fields |
| `dashboard-crm/src/api/types.ts` | **Modify** | Add `DetalleOportunidad`, `Producto`, `CotizacionData` interfaces |
| `dashboard-crm/src/pages/CRMPage.tsx` | **Modify** | Refactor sidebar: replace current sections with `CotizacionEditor`, `DetalleLineEditor`, `SeguimientoTimeline`, `ActionButtons` components; add queries for productos/detalles/cotizacion-data |
| `dashboard-crm/src/components/CotizacionEditor.tsx` | **Create** | Form: validez_oferta, tiempo_entrega, forma_pago, garantía, observaciones, aclaraciones — auto-save on blur or debounced |
| `dashboard-crm/src/components/DetalleLineEditor.tsx` | **Create** | Table rows with product select (fetch `GET /productos`), cantidad, vr_unitario inputs; auto-calc vr_total + iva; add/delete row buttons |
| `dashboard-crm/src/components/ActionButtons.tsx` | **Create** | Sticky bottom bar: "Enviar" (Borrador), "Aprobar" (Enviada), "Ganar" (Aceptada), "Descargar PDF" (always) — each triggers mutation |
| `dashboard-crm/src/components/SeguimientoTimeline.tsx` | **Create** | Vertical timeline: line + dot + card per entry; sorted `fecha` desc; shows tipo icon, date, notas, autor_nombre |

## Interfaces

```php
// POST /api/v1/oportunidades/{id}/enviar
// Success: { success: true, data: { id, estado:"Enviada", seguimiento_id } }
// Error 422: { success: false, error: "La cotización ya fue enviada" | "La oportunidad no tiene un contacto con email" }
```

```ts
interface DetalleOportunidad {
  id: number; oportunidad_id: number; producto_id: number | null;
  concepto: string; medida: string; cantidad: number;
  vr_unitario: number; iva: number; vr_total: number; producto?: Producto;
}
interface Producto { id: number; nombre: string; iva: number; estado: string; }
```

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Feature | `enviar` success, already-sent guard, no-contact guard | PHPUnit + RefreshDatabase + Sanctum token |
| Feature | `aprobar` guard rejects non-Enviada | PHPUnit |
| Feature | `ganar` guard rejects non-Aceptada | PHPUnit |
| Unit | `CotizacionMail` subject/attachment | Mailable assertions + `Mail::fake()` |
| Integration | Mail is queued not sent synchronously | `Queue::fake()` assertion |

## Migration / Rollout

No migration required. All columns exist on `oportunidad` table. Route is additive.

## Open Questions

None.
