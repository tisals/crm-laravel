# Task Breakdown: Mejoras Post-Lanzamiento

**Change**: `mejoras-post-lanzamiento`
**Phase**: Tasks
**Topic Key**: `sdd/mejoras-post-lanzamiento/tasks`

---

## Group 1: CSV Seeders (Backend)

### T1 — Create CsvSeederTrait
**Description**: Create `database/seeders/CsvSeederTrait.php` with shared CSV parsing logic: `parseCsv()`, `cleanHeaders()`, `cleanCell()`, `isEmptyRow()`, `parseExcelDate()`, `parsePercentage()`.

**Files affected**:
- `database/seeders/CsvSeederTrait.php` (NEW)

**Depends on**: None

**Verification**:
```bash
# Manual: inspect trait methods
grep -n "function parseCsv\|function cleanCell\|function parseExcelDate" database/seeders/CsvSeederTrait.php
```

---

### T2 — Create CiudadCsvSeeder
**Description**: Create `database/seeders/CiudadCsvSeeder.php` that reads `Docs/ciudades.csv` (semicolon-delimited, ~1123 rows) and upserts into `ciudades` table. Pad `cod_municipio` with leading zero (5001 → 05001), title-case `nombre` and `departamento`, parse `dd/mm/YYYY` dates.

**Files affected**:
- `database/seeders/CiudadCsvSeeder.php` (NEW)
- Uses `CsvSeederTrait`

**Depends on**: T1 (CsvSeederTrait)

**Verification**:
```bash
php artisan db:seed --class=CiudadCsvSeeder
php artisan tinker --execute="echo DB::table('ciudades')->count();"
# Expected: ~1123
```

---

### T3 — Create EntidadCsvSeeder
**Description**: Create `database/seeders/EntidadCsvSeeder.php` that reads `Docs/Entidades.csv` (~144 rows) and maps columns to `entidad` table. Handle estado mapping (25→Prospecto, Activo/Cliente→Activo, Inactivo→Inactivo), ciudad lookup, Excel artifact cleaning.

**Files affected**:
- `database/seeders/EntidadCsvSeeder.php` (NEW)
- Uses `CsvSeederTrait`

**Depends on**: T1 (CsvSeederTrait), T2 (ciudades must exist for FK lookup)

**Verification**:
```bash
php artisan db:seed --class=EntidadCsvSeeder
php artisan tinker --execute="echo DB::table('entidad')->count();"
# Expected: ~144
```

---

### T4 — Create ContactoCsvSeeder
**Description**: Create `database/seeders/ContactoCsvSeeder.php` that reads `Docs/contactos.csv` (~296 rows) and maps columns to `contactos` table. Skip rows where referenced `entidad` doesn't exist.

**Files affected**:
- `database/seeders/ContactoCsvSeeder.php` (NEW)
- Uses `CsvSeederTrait`

**Depends on**: T3 (entidades must exist for FK)

**Verification**:
```bash
php artisan db:seed --class=ContactoCsvSeeder
php artisan tinker --execute="echo DB::table('contactos')->count();"
# Expected: ~296
```

---

### T5 — Create ProductoCsvSeeder
**Description**: Create `database/seeders/ProductoCsvSeeder.php` that reads `Docs/productos.csv` (~37 rows) and maps columns to `productos` table. Parse `iva` percentage (19% → 19.0).

**Files affected**:
- `database/seeders/ProductoCsvSeeder.php` (NEW)
- Uses `CsvSeederTrait`

**Depends on**: T1 (CsvSeederTrait)

**Verification**:
```bash
php artisan db:seed --class=ProductoCsvSeeder
php artisan tinker --execute="echo DB::table('productos')->count();"
# Expected: ~37
```

---

### T6 — Create RealDataSeeder (Orchestrator)
**Description**: Create `database/seeders/RealDataSeeder.php` that calls all CSV seeders in dependency order: CiudadCsvSeeder → EntidadCsvSeeder → ContactoCsvSeeder → ProductoCsvSeeder. Do NOT modify DatabaseSeeder (runs separately).

**Files affected**:
- `database/seeders/RealDataSeeder.php` (NEW)

**Depends on**: T2, T3, T4, T5

**Verification**:
```bash
php artisan db:seed --class=RealDataSeeder
# Verify no errors, then check counts
```

---

### T7 — Verify seeders end-to-end
**Description**: Run `php artisan migrate:fresh --seed --class=RealDataSeeder` and verify table counts match expected. Test idempotency by running twice.

**Files affected**: None (verification step)

**Depends on**: T6

**Verification**:
```bash
php artisan migrate:fresh --seed --class=RealDataSeeder
# Verify ~1123 ciudades, ~144 entidades, ~296 contactos, ~37 productos
# Run again, verify no duplicates via updateOrInsert
```

---

## Group 2: Backend Fixes (Backend)

### T8 — Override applyFilters in EloquentEntidadRepository for estado IN clause
**Description**: Override `applyFilters()` in `EloquentEntidadRepository` to handle `estado` as a comma-separated string → case-insensitive IN clause using `LOWER()`. Supports single value `"Activo"` or multiple `"Activo,Prospecto"`.

**Files affected**:
- `app/Infrastructure/Persistence/EloquentEntidadRepository.php` (MODIFY)

**Depends on**: None

**Verification**:
```bash
# Hit the endpoint
curl "http://localhost:8001/api/v1/entidad?estado=Activo,Prospecto" -H "Authorization: Bearer $(php artisan crm:generate-token --email=test@test.com --plain-text)"
# Verify response only includes Activo and Prospecto
curl "http://localhost:8001/api/v1/entidad?estado=activo" -H "..."  # case-insensitive
```

---

### T9 — Add iva rule to DetalleOportunidadRequest
**Description**: Add `'iva' => 'nullable|numeric|min:0|max:100'` validation rule to `DetalleOportunidadRequest`. Both POST (store) and PUT (update) paths.

**Files affected**:
- `app/Http/Requests/DetalleOportunidadRequest.php` (MODIFY)

**Depends on**: None

**Verification**:
```bash
# POST with iva field
curl -X POST "http://localhost:8001/api/v1/oportunidades/1/detalles" -H "Content-Type: application/json" -d '{"producto_id":1,"cantidad":1,"vr_unitario":1000,"iva":19}'
# Verify 200, not 422
```

---

### T10 — Fix SeguimientoController ICS to accept entidad_id
**Description**: Add `entidad_id` filter support to `SeguimientoController::exportCalendarIcs()`. In addition to existing `contacto_id`, accept `entidad_id` param and filter query accordingly.

**Files affected**:
- `app/Http/Controllers/API/SeguimientoController.php` (MODIFY)

**Depends on**: None

**Verification**:
```bash
curl "http://localhost:8001/api/v1/seguimientos/calendar.ics?mes=2026-05&entidad_id=1"
# Verify .ics file returned with filtered results
```

---

### T11 — Modify CotizacionController::enviar() to accept mensaje + contacto_id
**Description**: Update `CotizacionController::enviar()` method signature to accept `Request $request` (currently no signature param). Accept JSON body with optional `mensaje` (string) and `contacto_id` (int). If `contacto_id` provided, validate it belongs to the same entity. Use custom message in seguimiento `notas` and pass to CotizacionMail.

**Files affected**:
- `app/Http/Controllers/API/CotizacionController.php` (MODIFY)

**Depends on**: T12 (for custom message in mailer)

**Verification**:
```bash
curl -X POST "http://localhost:8001/api/v1/oportunidades/1/enviar" -H "Content-Type: application/json" -d '{"mensaje":"Test message","contacto_id":5}'
# Verify 200 + seguimiento with "Test message"
```

---

### T12 — Update CotizacionMail to accept optional custom message
**Description**: Modify `CotizacionMail` constructor to accept optional `?string $mensaje = null` parameter. If provided, use it in the email body instead of default template.

**Files affected**:
- `app/Mail/CotizacionMail.php` (MODIFY)

**Depends on**: None (called from T11)

**Verification**:
```bash
# Verify mail driver is 'log', send a test, check storage/logs/laravel.log for custom message
```

---

## Group 3: Frontend Menu Reorganization (Frontend)

### T13 — Add new MODULES constants to roles.ts
**Description**: Add `CONTACTOS: 'contactos'`, `CIUDADES: 'ciudades'`, `PRODUCTOS: 'productos'` to the MODULES object in `roles.ts`. MAESTROS already exists.

**Files affected**:
- `dashboard-crm/src/config/roles.ts` (MODIFY)

**Depends on**: None

**Verification**:
```bash
grep -n "CONTACTOS\|CIUDADES\|PRODUCTOS" dashboard-crm/src/config/roles.ts
```

---

### T14 — Update role module lists in roles.ts
**Description**: Update each role's `modules[]` array:
- `super_admin`: ALL modules (auto via `Object.values(MODULES)`)
- `comercial`: add `MODULES.CONTACTOS`
- `admin`: add `MODULES.CIUDADES`, `MODULES.PRODUCTOS`
- `operaciones`: add `MODULES.CONTACTOS` (optional — align with design)

Also add permissions entries for new modules in `allPermissions[]` and each role's `permissions[]`.

**Files affected**:
- `dashboard-crm/src/config/roles.ts` (MODIFY)

**Depends on**: T13

**Verification**:
```typescript
// Manual verification: inspect each role's modules array
```

---

### T15 — Update Sidebar.tsx group structure
**Description**: Reorganize `GROUPS` array in Sidebar.tsx:
- **CRM**: Dashboard, Directorio, **Contactos**, Oportunidades (CRM)
- **ERP**: Finanzas, Talento, Operaciones (rename from "Administrativo-ERP")
- **Seguridad**: Seguridad, **Maestros**, **Ciudades**, **Productos**, Usuarios

Add icons for new modules (`Users`, `MapPin`, `Package`, `BookOpen` from lucide-react) and labels (`Contactos`, `Ciudades`, `Productos`, `Maestros`).

Remove the `filter(m => m !== MODULES.MAESTROS)` filter on line 72.

**Files affected**:
- `dashboard-crm/src/components/layout/Sidebar.tsx` (MODIFY)

**Depends on**: T13 (for MODULES constants), T14 (for role access)

**Verification**:
```bash
# Start frontend dev server, login as super_admin, verify sidebar shows 3 groups with all modules
```

---

### T16 — Create placeholder pages (ContactosPage, CiudadesPage, ProductosPage, MaestrosPage)
**Description**: Add 4 new placeholder page components to `ModulosPages.tsx`: `ContactosPage`, `CiudadesPage`, `ProductosPage`, `MaestrosPage`. Each with title, subtitle, and "Módulo en desarrollo..." placeholder.

**Files affected**:
- `dashboard-crm/src/pages/ModulosPages.tsx` (MODIFY)

**Depends on**: None

**Verification**: Import components, render with correct title text

---

### T17 — Export new pages from index.ts
**Description**: Add exports for `ContactosPage`, `CiudadesPage`, `ProductosPage`, `MaestrosPage` from `pages/index.ts`.

**Files affected**:
- `dashboard-crm/src/pages/index.ts` (MODIFY)

**Depends on**: T16

**Verification**:
```bash
grep -n "ContactosPage\|CiudadesPage\|ProductosPage\|MaestrosPage" dashboard-crm/src/pages/index.ts
```

---

### T18 — Add routes to App.tsx
**Description**: Import new page components from `./pages/index` and add routes:
- `<Route path="/contactos" element={<ContactosPage />} />`
- `<Route path="/ciudades" element={<CiudadesPage />} />`
- `<Route path="/productos" element={<ProductosPage />} />`
- `<Route path="/maestros" element={<MaestrosPage />} />`

**Files affected**:
- `dashboard-crm/src/App.tsx` (MODIFY)

**Depends on**: T17

**Verification**:
```bash
# Navigate to /contactos, /ciudades, /productos, /maestros — verify no 404
```

---

## Group 4: Mobile Bottom Nav (Frontend)

### T19 — Rewrite BottomNav.tsx with 3-group architecture
**Description**: Rewrite `BottomNav.tsx` to replace the flat 5-item nav with 3 group buttons (CRM, ERP, Seguridad). Each button has an icon (`TrendingUp`, `Briefcase`, `Shield`) and a label. Tapping a group toggles a slide-up submenu panel showing that group's modules filtered by role.

**Implementation details**:
- `NavGroup` interface: `{ key, label, icon, modules[] }`
- State: `activeGroup: string | null` — single active group at a time
- Submenu: fixed position above the nav bar (`bottom-16`), z-50, with backdrop
- Desktop: hidden via `hidden md:hidden`
- Mobile: visible via `md:hidden`

**Files affected**:
- `dashboard-crm/src/components/layout/BottomNav.tsx` (REWRITE)

**Depends on**: T13 (for MODULES constants), T14 (for role filtering)

**Verification**:
```bash
# Open mobile viewport in devtools (<768px)
# Verify 3 buttons visible
# Tap "CRM" → submenu slides up with CRM modules
# Tap "ERP" → submenu closes, ERP opens
# Navigate → submenu closes
# Desktop viewport → BottomNav hidden
```

---

### T20 — Verify mobile nav role filtering
**Description**: Login as each role and verify correct submenu content on mobile:
- `comercial`: CRM shows modules; ERP shows "No access"
- `admin`: CRM shows "No access"; ERP + Seguridad show modules
- `super_admin`: All 3 groups show their modules

**Files affected**: None (verification step)

**Depends on**: T19

**Verification**:
```typescript
// Manual: switch roles in devtools, verify submenu filtering
```

---

## Group 5: Opportunity Module Fixes (Frontend + Backend)

### T21 — Fix empty-string vs 0 UX in DetalleLineEditor
**Description**: Update `LineaForm` interface in `DetalleLineEditor.tsx`: change `vr_unitario: number` → `vr_unitario: number | ''` and `iva: number` → `iva: number | ''`. Update `addLine()` defaults to use `''` instead of `0`. Update input value handling to show empty string when value is `0`.

**Files affected**:
- `dashboard-crm/src/components/DetalleLineEditor.tsx` (MODIFY)

**Depends on**: None

**Verification**: Open DetalleLineEditor, click "+ Agregar", verify new line shows empty inputs (not "0")

- [x] **DONE**: Updated `LineaForm` interface types, `addLine()` defaults, input value handling, and total calculation.

---

### T22 — Fix addLine button defaults in CRMPage.tsx
**Description**: Update the inline `onClick` handler for the "+ Agregar línea" button (line ~1264) to use `''` instead of `0` for `vr_unitario` and `iva`.

**Files affected**:
- `dashboard-crm/src/pages/CRMPage.tsx` (MODIFY)

**Depends on**: T21 (consistent behavior)

**Verification**: Same as T21 — verify inline button produces empty inputs

- [x] **DONE**: Updated inline onClick to use `''` for both fields.

---

### T23 — Add IVA to save payload in handleSaveLines()
**Description**: Update `handleSaveLines()` in `CRMPage.tsx` to include `iva` in the POST/PUT payload. Map `''` → `0` for the API call. Also add IVA auto-inheritance from product when selecting a product in `selectProducto()` — already done at line 48, verify it works.

**Files affected**:
- `dashboard-crm/src/pages/CRMPage.tsx` (MODIFY)

**Depends on**: T9 (backend accepts iva), T21 (frontend collects iva)

**Verification**:
```typescript
// Add line with IVA=19, save, verify backend stores iva=19
```

- [x] **DONE**: Added `iva` field to payload with `'' → 0` mapping.

---

### T24 — Update StoreDetalleOportunidadUseCase for custom IVA
**Description**: Modify `StoreDetalleOportunidadUseCase::execute()` to accept `iva` from the data payload (not just from the product). If `data['iva']` is explicitly provided, use it instead of `$producto->iva`. Calculate `vr_total` based on the provided IVA.

**Files affected**:
- `app/Application/UseCases/DetalleOportunidad/StoreDetalleOportunidadUseCase.php` (MODIFY)

**Depends on**: None

**Verification**:
```bash
POST /api/v1/oportunidades/1/detalles with {"producto_id":1,"cantidad":1,"vr_unitario":1000,"iva":19}
# Verify DB stores iva=19 and vr_total=1190 (1000*1 * 1.19)
```

- [x] **DONE**: Already implemented — UseCase accepts `data['iva']` and falls back to product iva.

---

### T25 — Fix seguimiento timeline refresh query key
**Description**: Update the `createQuickSeg` mutation `onSuccess` handler in `CRMPage.tsx` (line 667) to use `queryClient.invalidateQueries({ queryKey: ['seguimientos'], exact: false })` so that both `['seguimientos', 'timeline', id]` and `['seguimientos', 'byOportunidad', id]` query keys are invalidated.

**Files affected**:
- `dashboard-crm/src/pages/CRMPage.tsx` (MODIFY)

**Depends on**: None

**Verification**:
```typescript
// Type quick seguimiento, save, verify timeline section re-fetches and shows new entry
```

- [x] **DONE**: Changed to `{ queryKey: ['seguimientos'], exact: false }`.

---

### T26 — Fix calendar ICS URL parameter (entidad_id vs contacto_id)
**Description**: Update `downloadMonthlyCalendarIcsUrl()` in `crmApi.ts` to accept an object with optional `entidad_id` and `contacto_id` params instead of a single `contactoId`. Construct the URL with `entidad_id` param when provided.

**Files affected**:
- `dashboard-crm/src/api/crmApi.ts` (MODIFY)

**Depends on**: T10 (backend accepts entidad_id)

**Verification**:
```typescript
// Before: downloadMonthlyCalendarIcsUrl(mes, entidadId) — sends as contacto_id
// After: downloadMonthlyCalendarIcsUrl(mes, { entidad_id: 5 }) — sends as entidad_id
```

- [x] **DONE**: Updated function signature to accept `{ contacto_id?, entidad_id? }` object.

---

### T27 — Fix calendar ICS URL call site in CRMPage.tsx
**Description**: Update the `href` on line 1288 of `CRMPage.tsx` to use the new function signature: `downloadMonthlyCalendarIcsUrl(currentMonth, { entidad_id: selectedOpp?.entidad_id })`.

**Files affected**:
- `dashboard-crm/src/pages/CRMPage.tsx` (MODIFY)

**Depends on**: T26

**Verification**:
```bash
# Click .ics link, verify URL contains &entidad_id= not &contacto_id=
```

- [x] **DONE**: Updated href to use new signature with `{ entidad_id: ... }`.

---

### T28 — Update DetalleOportunidadCreate type to include iva
**Description**: Add optional `iva?: number` field to `DetalleOportunidadCreate` interface in `types.ts`.

**Files affected**:
- `dashboard-crm/src/api/types.ts` (MODIFY)

**Depends on**: None

**Verification**:
```bash
grep -n "iva" dashboard-crm/src/api/types.ts
```

- [x] **DONE**: Added `iva?: number` to `DetalleOportunidadCreate`. Also added `EnviarCotizacionRequest` type.

---

### T29 — Create SendQuoteModal component
**Description**: Create `dashboard-crm/src/components/SendQuoteModal.tsx` with:
- Textarea for email body (pre-filled with default message)
- Contact dropdown filtered to contacts of the opportunity's entity, defaulting to the one with `rol === 'Decisor'`
- "Cancelar" and "Enviar" buttons
- Loading state, error display for 422 responses
- Calls `enviarCotizacion(id, { mensaje, contacto_id })` on submit

**Files affected**:
- `dashboard-crm/src/components/SendQuoteModal.tsx` (NEW)

**Depends on**: T31 (API function updated)

**Verification**:
```typescript
// Open modal from CRMPage, verify contact list, type message, send, verify seguimiento created
```

- [x] **DONE**: Created SendQuoteModal using SlidePanel with contact dropdown, message textarea, and error handling.

---

### T30 — Update enviarCotizacion API function with new params
**Description**: Change `enviarCotizacion(id: number)` to `enviarCotizacion(id: number, body?: { mensaje?: string; contacto_id?: number })`. Send body in POST request (empty object `{}` by default for backward compatibility).

**Files affected**:
- `dashboard-crm/src/api/crmApi.ts` (MODIFY)

**Depends on**: T11 (backend accepts new params)

**Verification**:
```typescript
enviarCotizacion(1, { mensaje: "test", contacto_id: 5 }) // sends JSON body
enviarCotizacion(1) // sends {} — backward compatible
```

- [x] **DONE**: Updated function signature to accept optional body parameter.

---

### T31 — Integrate SendQuoteModal in CRMPage.tsx
**Description**: Replace the current `handleEnviar()` which directly calls `enviar.mutate()` with a modal opener. Add `showSendQuoteModal` state. When modal closes with success, invalidate queries and refresh UI.

**Files affected**:
- `dashboard-crm/src/pages/CRMPage.tsx` (MODIFY)

**Depends on**: T30 (API), T29 (modal component)

**Verification**:
```typescript
// Click "Enviar Cotización" on a Borrador opportunity
// Verify modal opens instead of immediate send
// Send with custom message, verify seguimiento created
```

- [x] **DONE**: Added `showSendQuoteModal` state, modified `handleEnviar()` to open modal, added modal JSX with query invalidation on success.

---

## Group 6: Directorio Filter (Frontend + Backend)

### T32 — Update getEntidades API function with estado param
**Description**: Update `getEntidades()` type signature in `crmApi.ts` to accept optional `estado?: string` parameter in addition to existing `search` and `per_page`.

**Files affected**:
- `dashboard-crm/src/api/crmApi.ts` (MODIFY)

**Depends on**: T8 (backend accepts estado param)

**Verification**:
```typescript
getEntidades({ estado: 'Activo,Prospecto' }) // sends ?estado=Activo,Prospecto
```

---

### T33 — Add estado filter dropdown to DirectorioPage
**Description**: Add estado filter UI to `DirectorioPage.tsx`:
- State: `estadoFilter` (default `''` for comercial, or configurable)
- Add dropdown/select above search bar with options: "Todos", "Activo", "Prospecto", "Inactivo"
- Pass `estado` param to `getEntidades()` query
- Update `queryKey` to include `estadoFilter` so React Query re-fetches on change

**Files affected**:
- `dashboard-crm/src/pages/DirectorioPage.tsx` (MODIFY)

**Depends on**: T32

**Verification**:
```bash
# Open DirectorioPage, select "Activo" in filter, verify only active entities shown
# Select "Prospecto", verify only prospects shown
# Switch back to "Todos", verify all entities shown
```

---

### T34 — Test estado filter end-to-end
**Description**: Verify the full flow: frontend sends estado param → backend applies case-insensitive IN clause → correct entities returned. Test single value, multiple values, case-insensitive, and no filter.

**Files affected**: None (verification step)

**Depends on**: T32, T33

**Verification**:
```typescript
// Manual: change estado filter in DirectorioPage dropdown
// Verify API calls include ?estado=...
// Verify response matches expectations
```

---

## Dependency Graph

```
T1 (CsvSeederTrait)
├── T2 (CiudadCsvSeeder) ──┐
├── T3 (EntidadCsvSeeder) ─┤
├── T4 (ContactoCsvSeeder) ─┤
└── T5 (ProductoCsvSeeder) ─┤
                             └── T6 (RealDataSeeder) ── T7 (Verify)
                     
T8 (EntidadRepo filter) ──┐
                           ├── T32 (getEntidades estado param) ── T33 (DirectorioPage filter) ── T34 (verify)
                           
T9 (DetalleReq iva) ──┐
T10 (ICS entidad_id) ──┤
T11 (enviar) ──────────┤
T12 (CotizacionMail) ──┘
                       ├── T30 (enviarCotizacion API) ── T29 (SendQuoteModal) ── T31 (CRMPage integration)
                       ├── T26 (calendar URL) ── T27 (CRMPage call site)
                       ├── T24 (StoreUseCase iva) ── T23 (save payload) ── T21 (DetalleLineEditor) ── T22 (CRMPage addLine)
                       └── T28 (types iva)

T13 (MODULES) ── T14 (role lists) ── T15 (Sidebar groups) ── T19 (BottomNav) ── T20 (verify role filtering)
                                    ── T16 (placeholder pages) ── T17 (index exports) ── T18 (App routes)
```

## Implementation Order (Recommended)

1. **T1–T7**: CSV Seeders (backend only, no API impact)
2. **T8**: Directorio filter backend (isolated)
3. **T9–T12**: Backend fixes for IVA, ICS, send-quote (isolated, but needed by frontend)
4. **T13–T18**: Menu reorganization (frontend only)
5. **T19–T20**: Mobile bottom nav (frontend only)
6. **T21–T31**: Opportunity module fixes (frontend + backend)
7. **T32–T34**: Directorio filter frontend (depends on T8)
