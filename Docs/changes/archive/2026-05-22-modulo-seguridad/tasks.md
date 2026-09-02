# Tasks: Módulo Seguridad

> Generated: 2026-05-22
> Based on: design.md + 5 delta specs + existing codebase patterns

## Legend

- **Dependencies**: Task IDs that must be completed before this one
- **Execution order within groups**: Sequential; between groups: parallel if no dependency link
- **Verification**: How the task is verified as done

---

## Group A: Backend — Maestros Clean Architecture (full CRUD from scratch)

*Dependencies: None. These tasks are sequential within the group.*

### A1. Create `app/Domain/Entities/Maestro.php`
- **Title**: Pure PHP entity for Maestro
- **Description**: Create a Domain Entity following the same pattern as existing entities (e.g. `CiudadEntity`). Constructor with typed properties: `id` (int), `nombre` (string), `campo` (string), `habilitado` (string), `created_at` (?string), `updated_at` (?string). Static `fromArray(array $data): self` factory method.
- **Files**: CREATE `app/Domain/Entities/Maestro.php`
- **Dependencies**: —
- **Verification**: File exists, class is instantiable, `fromArray` returns hydrated instance

### A2. Create `app/Domain/Repositories/MaestroRepositoryInterface.php`
- **Title**: Repository interface for Maestro
- **Description**: Define interface extending generic contracts: `paginate(int $perPage, ?string $search, array $filters): LengthAwarePaginator`, `findById(int $id): mixed`, `create(array $data): mixed`, `update(int $id, array $data): mixed`, `delete(int $id): bool`
- **Files**: CREATE `app/Domain/Repositories/MaestroRepositoryInterface.php`
- **Dependencies**: A1
- **Verification**: Interface exists with all 5 methods

### A3. Create `app/Infrastructure/Persistence/EloquentMaestroRepository.php`
- **Title**: Eloquent implementation of Maestro repository
- **Description**: Extends `BaseRepository`, implements `MaestroRepositoryInterface`. Uses `App\Models\Maestro` as Eloquent model. Maps to `MaestroEntity` in `mapModelToEntity`. `paginate` applies search on `nombre`/`campo` fields, and filters by `campo`. Inherits `create`, `update`, `delete` from `BaseRepository`.
- **Files**: CREATE `app/Infrastructure/Persistence/EloquentMaestroRepository.php`
- **Dependencies**: A1, A2
- **Verification**: Repository can paginate with search/filter, create, update, delete records

### A4. Create `app/Application/UseCases/Maestro/IndexMaestroUseCase.php`
- **Title**: List maestros use case
- **Description**: Follows existing pattern (e.g. `IndexProductoUseCase`). Constructor injects `MaestroRepositoryInterface`. `execute(int $perPage, ?string $search, array $filters): LengthAwarePaginator`. Delegates to repository `paginate`.
- **Files**: CREATE `app/Application/UseCases/Maestro/IndexMaestroUseCase.php`
- **Dependencies**: A2
- **Verification**: UseCase returns paginated results, passes through search/filter params

### A5. Create `app/Application/UseCases/Maestro/ShowMaestroUseCase.php`
- **Title**: Show single maestro use case
- **Description**: Constructor injects repository. `execute(int $id): mixed`. Returns entity or null.
- **Files**: CREATE `app/Application/UseCases/Maestro/ShowMaestroUseCase.php`
- **Dependencies**: A2
- **Verification**: UseCase returns entity for valid id, null for nonexistent

### A6. Create `app/Application/UseCases/Maestro/StoreMaestroUseCase.php`
- **Title**: Create maestro use case
- **Description**: Constructor injects repository. `execute(array $data): mixed`. Delegates to repository `create`.
- **Files**: CREATE `app/Application/UseCases/Maestro/StoreMaestroUseCase.php`
- **Dependencies**: A2
- **Verification**: UseCase creates and returns the new record

### A7. Create `app/Application/UseCases/Maestro/UpdateMaestroUseCase.php`
- **Title**: Update maestro use case
- **Description**: Constructor injects repository. `execute(int $id, array $data): mixed`. Delegates to repository `update`. Returns null if not found.
- **Files**: CREATE `app/Application/UseCases/Maestro/UpdateMaestroUseCase.php`
- **Dependencies**: A2
- **Verification**: UseCase updates and returns updated record, null for nonexistent id

### A8. Create `app/Application/UseCases/Maestro/DeleteMaestroUseCase.php`
- **Title**: Delete maestro use case
- **Description**: Constructor injects repository. `execute(int $id): bool`. Delegates to repository `delete`.
- **Files**: CREATE `app/Application/UseCases/Maestro/DeleteMaestroUseCase.php`
- **Dependencies**: A2
- **Verification**: UseCase returns true on success, false for nonexistent id

### A9. Create `app/Http/Requests/MaestroRequest.php`
- **Title**: Validation request for Maestro store/update
- **Description**: Extends `FormRequest`. Rules for POST: `nombre` required|string|max:255, `campo` required|string|max:100, `habilitado` required|in:Y,N. Rules for PUT: same but all `sometimes`. Spanish messages.
- **Files**: CREATE `app/Http/Requests/MaestroRequest.php`
- **Dependencies**: —
- **Verification**: POST with missing nombre returns 422, POST with valid data passes validation

### A10. Create `app/Http/Resources/MaestroResource.php`
- **Title**: API Resource for Maestro
- **Description**: Extends `JsonResource`. Returns `id`, `nombre`, `campo`, `habilitado`, `created_at`, `updated_at`.
- **Files**: CREATE `app/Http/Resources/MaestroResource.php`
- **Dependencies**: A1
- **Verification**: Resource serializes expected fields

### A11. Refactor `app/Http/Controllers/API/MaestroController.php`
- **Title**: Refactor MaestroController to use UseCases + full CRUD
- **Description**: Replace current Eloquent-direct implementation. Inject `IndexMaestroUseCase`, `ShowMaestroUseCase`, `StoreMaestroUseCase`, `UpdateMaestroUseCase`, `DeleteMaestroUseCase` via constructor. Add `store()`, `update()`, `destroy()` methods. `index()` returns paginated results. Follow the exact pattern of `ProductoController` (ApiResponse trait, 201 for store, 200 for update/delete, 404 when not found).
- **Files**: MODIFY `app/Http/Controllers/API/MaestroController.php`
- **Dependencies**: A4, A5, A6, A7, A8, A9, A10
- **Verification**: All 5 endpoints work with proper status codes and error handling

### A12. Update `routes/api.php` — Maestros under RBAC + full CRUD
- **Title**: Move maestros routes under RBAC middleware, add POST/PUT/DELETE
- **Description**: Replace current read-only routes (lines 108-110) with RBAC-guarded group containing all 5 CRUD routes: GET, POST, GET/{id}, PUT/{id}, DELETE/{id}. Follow pattern of existing RBAC groups.
- **Files**: MODIFY `routes/api.php`
- **Dependencies**: A11
- **Verification**: POST/PUT/DELETE /api/v1/maestros/* return proper responses, unauthorized without RBAC

### A13. Create `tests/Feature/API/MaestroControllerTest.php`
- **Title**: Full CRUD feature tests for Maestros
- **Description**: Follow `ProductoControllerTest` pattern (RefreshDatabase, authenticate helper). Test scenarios:
  - `it_lists_maestros` — 200 with paginated data
  - `it_creates_a_maestro` — 201 with valid data, record exists in DB
  - `it_shows_a_maestro` — 200 with existing id
  - `it_updates_a_maestro` — 200 with valid changes
  - `it_deletes_a_maestro` — 200 success, record removed
  - `it_returns_422_on_validation_failure` — missing nombre, invalid habilitado
  - `it_returns_404_when_maestro_not_found` — show/update/delete with nonexistent id
- **Files**: CREATE `tests/Feature/API/MaestroControllerTest.php`
- **Dependencies**: A11, A12
- **Verification**: `php artisan test --filter MaestroControllerTest` all green

---

## Group B: Backend — Ciudades Extend Existing CRUD

*Dependencies: None with Group A or C. Tasks sequential within group.*

### B1. Extend `CiudadRepositoryInterface` with create/update/delete
- **Title**: Add create, update, delete methods to existing interface
- **Description**: Add `create(array $data): mixed`, `update(string $codMunicipio, array $data): mixed`, `delete(string $codMunicipio): bool` to the existing interface.
- **Files**: MODIFY `app/Domain/Repositories/CiudadRepositoryInterface.php`
- **Dependencies**: —
- **Verification**: Interface has all 5 methods (paginate, findById, create, update, delete)

### B2. Extend `EloquentCiudadRepository` with create/update/delete implementations
- **Title**: Implement new repository methods for Ciudad
- **Description**: Add `create()`, `update()`, `delete()` methods. Note: primary key is `cod_municipio` (string), not auto-increment integer. `update` must find by string ID, use `$model->update($data)`, return mapped entity or null. `delete` must find by string ID, return bool.
- **Files**: MODIFY `app/Infrastructure/Persistence/EloquentCiudadRepository.php`
- **Dependencies**: B1
- **Verification**: Repository creates, updates, and deletes ciudad records correctly

### B3. Create StoreCiudadUseCase, UpdateCiudadUseCase, DeleteCiudadUseCase
- **Title**: Create 3 new UseCases for Ciudad CRUD
- **Description**: Create `StoreCiudadUseCase.php` (execute with data, returns created), `UpdateCiudadUseCase.php` (execute with cod_municipio + data, returns updated/null), `DeleteCiudadUseCase.php` (execute with cod_municipio, returns bool). Each injects `CiudadRepositoryInterface`.
- **Files**: 
  - CREATE `app/Application/UseCases/Ciudad/StoreCiudadUseCase.php`
  - CREATE `app/Application/UseCases/Ciudad/UpdateCiudadUseCase.php`
  - CREATE `app/Application/UseCases/Ciudad/DeleteCiudadUseCase.php`
- **Dependencies**: B1
- **Verification**: Each UseCase correctly delegates to repository and returns expected values

### B4. Create `app/Http/Requests/CiudadRequest.php`
- **Title**: Validation request for Ciudad store/update
- **Description**: POST rules: `cod_municipio` required|string|max:10|unique:ciudades, `nombre` required|string|max:200, `departamento` nullable|string|max:100. PUT: same but `cod_municipio` becomes `sometimes` (can't update PK) and unique rule ignores current record. Spanish messages.
- **Files**: CREATE `app/Http/Requests/CiudadRequest.php`
- **Dependencies**: —
- **Verification**: POST with missing cod_municipio returns 422; duplicate cod_municipio returns 422

### B5. Extend `CiudadController` with store, update, destroy
- **Title**: Add full CRUD methods to CiudadController
- **Description**: Inject `StoreCiudadUseCase`, `UpdateCiudadUseCase`, `DeleteCiudadUseCase`. Add `store()` returns 201, `update()` returns 200 or 404, `destroy()` returns 200 or 404. Handle duplicate key exception gracefully (Potential `\Illuminate\Database\QueryException` with 23000 code → 409 Conflict or 422).
- **Files**: MODIFY `app/Http/Controllers/API/CiudadController.php`
- **Dependencies**: B3, B4
- **Verification**: POST/PUT/DELETE endpoints work with correct status codes

### B6. Update `routes/api.php` — Ciudades under RBAC + full CRUD
- **Title**: Move ciudades routes under RBAC middleware, add POST/PUT/DELETE
- **Description**: Replace current read-only routes (lines 104-106) with RBAC-guarded group: GET, POST, GET/{cod_municipio}, PUT/{cod_municipio}, DELETE/{cod_municipio}.
- **Files**: MODIFY `routes/api.php`
- **Dependencies**: B5
- **Verification**: POST/PUT/DELETE /api/v1/ciudades/* work; unauthorized without RBAC

### B7. Add tests to existing `CiudadControllerTest`
- **Title**: Add CRUD test methods to existing Ciudad test
- **Description**: Add test methods to existing `tests/Feature/API/CiudadControllerTest.php`:
  - `it_creates_a_ciudad` — 201 with `{ cod_municipio, nombre, departamento }`
  - `it_updates_a_ciudad` — 200 with nombre change
  - `it_deletes_a_ciudad` — 200 success, record removed
  - `it_returns_422_on_duplicate_cod_municipio` — 422 on create with existing PK
  - `it_returns_404_when_updating_nonexistent_ciudad` — 404 for non existent cod_municipio
  - `it_returns_404_when_deleting_nonexistent_ciudad` — 404 for non existent cod_municipio
- **Files**: MODIFY `tests/Feature/API/CiudadControllerTest.php`
- **Dependencies**: B5, B6
- **Verification**: `php artisan test --filter CiudadControllerTest` all green

---

## Group C: Backend — Security Dashboard

*Dependencies: None with Groups A or B. Tasks sequential within group.*

### C1. Create `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php`
- **Title**: Security dashboard KPIs use case
- **Description**: Follow `GetDashboardUseCase` pattern. Queries live Counts:
  - `total_usuarios`: `Usuario::count()`
  - `usuarios_activos`: `Usuario::where('estado', 'Activo')->count()`
  - `total_productos`: `Producto::count()`
  - `total_marcas`: `Entidad::where('estado', 'Propia')->count()`
  - `distribucion_roles`: group usuarios by rol_id, join with roles for name
  - `actividad_reciente`: empty array for now (placeholder, to be extended with audit trail)
  Returns `{ kpi, distribucion_roles, actividad_reciente }` structure.
- **Files**: CREATE `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php`
- **Dependencies**: —
- **Verification**: Returns correct KPI structure with live counts; zeroes when DB empty

### C2. Create `app/Http/Controllers/API/SecurityDashboardController.php`
- **Title**: Thin controller for security dashboard
- **Description**: Follows `DashboardController` pattern. Uses `ApiResponse` trait. Single `index()` method that calls `GetSecurityDashboardUseCase::execute()` and returns `$this->successResponse($data)`.
- **Files**: CREATE `app/Http/Controllers/API/SecurityDashboardController.php`
- **Dependencies**: C1
- **Verification**: Controller responds 200 with envelope `{ success: true, data: { kpi: {...}, ... } }`

### C3. Add route `GET /api/v1/seguridad/dashboard` under RBAC
- **Title**: Register security dashboard route
- **Description**: Add inside the `auth:sanctum` + `throttle:api` group, under RBAC middleware: `Route::get('/seguridad/dashboard', [SecurityDashboardController::class, 'index'])->name('seguridad.dashboard');`
- **Files**: MODIFY `routes/api.php`
- **Dependencies**: C2
- **Verification**: `GET /api/v1/seguridad/dashboard` with auth returns data, without returns 401

### C4. Create `tests/Feature/API/SecurityDashboardTest.php`
- **Title**: Feature tests for security dashboard
- **Description**: Follow `refreshDatabase` + authenticate pattern. Tests:
  - `it_returns_dashboard_kpis` — creates usuarios, productos, entidad Propia; asserts KPI values
  - `it_returns_zero_kpis_when_empty` — no data; all KPI values = 0
  - `it_returns_401_when_unauthenticated` — no token → 401
- **Files**: CREATE `tests/Feature/API/SecurityDashboardTest.php`
- **Dependencies**: C2, C3
- **Verification**: `php artisan test --filter SecurityDashboardTest` all green

---

## Group D: Frontend — API Layer

*Dependencies: Groups A, B, C should be complete (API exists). Group D must complete before Groups E, F, G, H, I.*

### D1. Add CRUD API functions to `crmApi.ts`
- **Title**: Add missing API functions for seguridad module
- **Description**: Add to `src/api/crmApi.ts`:
  - `getSecurityDashboard()` — `GET /seguridad/dashboard`
  - `createProducto()`, `updateProducto()`, `deleteProducto()` — already have `getProductos`, add the missing CRUD functions
  - `createMaestro()`, `updateMaestro()`, `deleteMaestro()` — `POST/PUT/DELETE /maestros`
  - `createCiudad()`, `updateCiudad()`, `deleteCiudad()` — `POST/PUT/DELETE /ciudades`
  - `getMarcas()` — alias for `getEntidades({ estado: 'Propia' })`
  
  Each function follows the existing pattern (axios call, ApiResponse wrapper, typed).
- **Files**: MODIFY `src/api/crmApi.ts`
- **Dependencies**: A12, B6, C3
- **Verification**: All functions can be imported and called; types match API response envelope

### D2. Add/extend TypeScript types in `types.ts`
- **Title**: New types for seguridad module
- **Description**: Add to `src/api/types.ts`:
  - `ProductoCreate` — `{ nombre, linea_negocio?, iva?, estado?, medida?, descripcion?, referencia?, vr_unitario? }`
  - `MaestroCreate` — `{ nombre, campo, habilitado }`
  - `CiudadCreate` — `{ cod_municipio, nombre, departamento? }`
  - `MarcaCreate` — extends `EntidadCreate` with `{ estado: 'Propia' }` and additional fields: `nombre_comercial?`, `dominio?`, `tipo_identificacion?`, `digito_verificacion?`, `departamento?`, `pais?`, `logo_url?`
  - `SeguridadDashboardData` — matches backend response: `{ kpi: { total_usuarios, usuarios_activos, total_productos, total_marcas }, distribucion_roles, actividad_reciente }`
  - Update `Entidad` type to include `estado: 'Activo' | 'Propia' | 'Cliente' | 'Prospecto' | 'Inactivo'`
  - Update `EntidadCreate` to include optional fields: `nombre_comercial?`, `dominio?`, `direccion?`, `departamento?`, `pais?`, `logo_url?`
- **Files**: MODIFY `src/api/types.ts`
- **Dependencies**: D1
- **Verification**: TypeScript compiles without errors; types match API shapes

### D3. Add routes to `App.tsx`
- **Title**: Add new seguridad routes in App.tsx
- **Description**: Add imports for `SeguridadDashboardPage`, `MarcasPage`. Add routes inside ProtectedLayout:
  - `/seguridad` → `SeguridadDashboardPage`
  - `/marcas` → `MarcasPage`
  Keep existing `/productos`, `/maestros`, `/ciudades` routes unchanged (they already exist).
- **Files**: MODIFY `src/App.tsx`
- **Dependencies**: D2
- **Verification**: Navigating to `/seguridad` and `/marcas` renders correct components

### D4. Update `src/pages/index.ts` export
- **Title**: Export new pages from index
- **Description**: Add exports for `SeguridadDashboardPage` and `MarcasPage` (and any other new pages) to the barrel export file.
- **Files**: MODIFY `src/pages/index.ts`
- **Dependencies**: D3
- **Verification**: `import { SeguridadDashboardPage, MarcasPage } from './pages'` works

### D5. (Optional) Add `MARCAS` module to roles config
- **Title**: Register 'marcas' as a module in roles.ts
- **Description**: Add `MARCAS: 'marcas'` to `MODULES` constant. Add permission entries to `allPermissions`. Add it to `super_admin` modules list. Optionally add to admin role. Add `/marcas` mapping in `moduleToPath` (or reuse `/marcas` directly).
- **Files**: MODIFY `src/config/roles.ts`
- **Dependencies**: D3
- **Verification**: Module appears in role config; sidebar can show/hide based on permissions

---

## Group E: Frontend — Seguridad Dashboard

*Dependencies: D1 (API functions). Tasks sequential within group.*

### E1. Create `SeguridadDashboardPage.tsx`
- **Title**: Security dashboard page replacing stub
- **Description**: Create new page in `src/pages/SeguridadDashboardPage.tsx`:
  - Fetches `getSecurityDashboard()` on mount via `useQuery`
  - Renders 4 KPI stat cards (teal-themed): Total Usuarios, Usuarios Activos, Total Productos, Total Marcas
  - Renders role distribution (list or simple chart)
  - Renders recent activity timeline (if data available)
  - Quick access navigation cards: Usuarios (/usuarios), Productos (/productos), Marcas (/marcas), Maestros (/maestros), Ciudades (/ciudades)
  - Error state: "Error al cargar dashboard de seguridad" banner, KPIs show "—"
  - Loading state: skeleton cards with animate-pulse
  - Empty state: KPIs show 0
  - Use existing SlidePanel pattern for layouts (bg-slate-800 cards, teal-500/600 accent)
- **Files**: CREATE `src/pages/SeguridadDashboardPage.tsx`
- **Dependencies**: D1, D2
- **Verification**: Page renders all KPIs, nav cards, role distribution; handles loading/error/empty states

### E2. Update `ModulosPages.tsx` — replace stub
- **Title**: Replace SeguridadPage stub with import from new component
- **Description**: Either:
  - (a) Change `SeguridadPage` export to re-export `SeguridadDashboardPage`, OR
  - (b) Remove the stub and have `App.tsx` import directly from `SeguridadDashboardPage`
  
  Recommended: Remove the stub, have `App.tsx` import directly, remove `SeguridadPage` from exports.
- **Files**: MODIFY `src/pages/ModulosPages.tsx` and `src/App.tsx`
- **Dependencies**: E1, D3
- **Verification**: `/seguridad` loads the real dashboard, not the stub

---

## Group F: Frontend — Marcas (Propia) CRUD

*Dependencies: D1 (API functions). Tasks sequential.*

### F1. Create `MarcasPage.tsx`
- **Title**: Full CRUD page for Marcas (Propia entities)
- **Description**: New page at `src/pages/MarcasPage.tsx`:
  - Calls `getEntidades({ estado: 'Propia', per_page: 50 })` via useQuery with `queryKey: ['marcas', search, page]`
  - Table columns: nombre, identificación, dominio, email, teléfono, acciones (Edit/Delete)
  - Header: "Marcas / Propia" title + "Nueva Marca" button
  - Search bar filtering
  - Pagination: 50 per page using `<Pagination>` component
  - Edit button → opens SlidePanel with MarcaFormModal
  - Delete button → `window.confirm('¿Eliminar marca {nombre}?')` → `deleteEntidad(id)` → invalidate ['marcas']
  - Loading skeletons, empty state "No hay marcas registradas", error state
  - Mutations: `createMutation` (POST /entidad with estado:Propia), `updateMutation` (PUT /entidad/{id}), `deleteMutation`
  - On mutation success: close SlidePanel, `queryClient.invalidateQueries({ queryKey: ['marcas'] })`
- **Files**: CREATE `src/pages/MarcasPage.tsx`
- **Dependencies**: D1, D2, D5
- **Verification**: List, create, edit, delete marcas; SlidePanel opens/closes; list refreshes; validations

### F2. Create `MarcaFormModal.tsx`
- **Title**: Form component for Marca create/edit
- **Description**: Form component rendered inside SlidePanel. Fields:
  - nombre (text, required)
  - nombre_comercial (text)
  - identificacion (text, NIT)
  - dominio (text)
  - email (email)
  - telefono (text)
  - direccion (text)
  - ciudad (text)
  - departamento (text)
  - logo_url (text)
  
  Props: `initialData?: Partial<Entidad>`, `onSave: (data: MarcaCreate) => void`, `onCancel: () => void`, `isLoading?: boolean`, `apiError?: string`.
  
  When `initialData` is provided → edit mode (pre-filled + "Actualizar" submit text).
  When no `initialData` → create mode (empty form + "Crear" submit text).
  Shows API error message inline (red alert).
  Submit button disabled while loading.
- **Files**: CREATE `src/pages/MarcaFormModal.tsx` (or `src/components/MarcaFormModal.tsx`)
- **Dependencies**: F1
- **Verification**: Form renders all fields, pre-fills in edit mode, validates required client-side, submits correct payload

### F3. Update Sidebar — add Marcas link (optional)
- **Title**: Add Marcas navigation item to Seguridad group
- **Description**: If not already handled by roles, add `/marcas` route to Sidebar. Add Marca icon (Tag or Shield) to `moduleIcons`, add label to `moduleLabels`, add to Seguridad group modules list.
- **Files**: MODIFY `src/components/layout/Sidebar.tsx`, `src/config/roles.ts`
- **Dependencies**: D5, F1
- **Verification**: Sidebar shows "Marcas" link in Seguridad group; clicking navigates to `/marcas`

---

## Group G: Frontend — Productos CRUD

*Dependencies: D1 (API functions). Tasks sequential.*

### G1. Modify `ProductosPage.tsx` — add CRUD with SlidePanel
- **Title**: Add create/edit/delete to ProductosPage
- **Description**: Modify existing `src/pages/ProductosPage.tsx`:
  - Add "Nuevo Producto" button in header area
  - Add SlidePanel state: `{ open, mode, data? }` 
  - Add Edit button per row (Pencil icon, opens SlidePanel with pre-filled ProductoFormModal)
  - Add Delete button per row → `window.confirm('¿Eliminar producto {nombre}?')` → `deleteProducto(id)` → invalidate `['productos']`
  - Add createMutation, updateMutation, deleteMutation using `useMutation`
  - On mutation success: close SlidePanel, `queryClient.invalidateQueries({ queryKey: ['productos'] })`
  - Loading states: disable submit button during mutation, show error inside SlidePanel
  - Convert list view to table or keep card view with action buttons
- **Files**: MODIFY `src/pages/ProductosPage.tsx`
- **Dependencies**: D1 (createProducto, updateProducto, deleteProducto functions)
- **Verification**: Create, edit, delete productos; list refreshes; SlidePanel opens/closes; validation errors show

### G2. Create `ProductoFormModal.tsx`
- **Title**: Form component for Producto create/edit
- **Description**: Form with fields:
  - nombre (text, required)
  - linea_negocio (text or select)
  - iva (number, default 19)
  - vr_unitario (number)
  - medida (select: Und, Hora, Día, Mes, Unidad, etc.)
  - descripcion (textarea)
  - estado (select: Activo/Inactivo)
  
  Props: `initialData?: Producto`, `onSave`, `onCancel`, `isLoading`, `apiError?`.
  Edit mode pre-fills, create mode empty. Shows API errors inline.
- **Files**: CREATE `src/components/ProductoFormModal.tsx`
- **Dependencies**: G1
- **Verification**: Form submits correct payload to API; pre-fills in edit mode

---

## Group H: Frontend — Maestros CRUD

*Dependencies: Group A complete (backend), D1 (API functions). Tasks sequential.*

### H1. Modify `MaestrosPage.tsx` — add CRUD with SlidePanel
- **Title**: Add create/edit/delete to MaestrosPage
- **Description**: Modify existing `src/pages/MaestrosPage.tsx`:
  - Add "Nuevo Maestro" button in header
  - Add SlidePanel state for create/edit
  - Add Edit button per row → opens pre-filled SlidePanel with form
  - Add Delete button per row (be careful with inline styling in table)
  - For grouped view, add action column to each table (or add a simple actions cell)
  - Add createMutation (POST /maestros), updateMutation (PUT /maestros/{id}), deleteMutation (DELETE /maestros/{id})
  - On mutation success: close SlidePanel, invalidate `['maestros']`
  - Search and campo filter still work with full data (client-side, since current implementation loads all)
- **Files**: MODIFY `src/pages/MaestrosPage.tsx`
- **Dependencies**: D1 (createMaestro, updateMaestro, deleteMaestro), A12
- **Verification**: Create, edit, delete maestros; grouped table view updates; SlidePanel opens/closes

### H2. Create inline form for Maestro (inside MaestrosPage or separate component)
- **Title**: Form for Maestro create/edit in SlidePanel
- **Description**: Form fields:
  - nombre (text, required)
  - campo (text or select from existing campos, required)
  - habilitado (select: Y/N)
  
  Can be inline inside MaestrosPage or extracted as `MaestroFormModal.tsx`.
  Props: `initialData`, `onSave`, `onCancel`, `isLoading`, `apiError?`.
- **Files**: MODIFY `src/pages/MaestrosPage.tsx` or CREATE `src/components/MaestroFormModal.tsx`
- **Dependencies**: H1
- **Verification**: Form submits correct payload; campo filter updates after create

---

## Group I: Frontend — Ciudades CRUD

*Dependencies: Group B complete (backend), D1 (API functions). Tasks sequential.*

### I1. Modify `CiudadesPage.tsx` — add CRUD with SlidePanel
- **Title**: Add create/edit/delete to CiudadesPage
- **Description**: Modify existing `src/pages/CiudadesPage.tsx`:
  - Add "Nueva Ciudad" button in header
  - Add SlidePanel state
  - Add Edit button per row (Pencil icon)
  - Add Delete button per row → `window.confirm` → `deleteCiudad(cod_municipio)` → invalidate `['ciudades']`
  - Add createMutation (POST /ciudades), updateMutation (PUT /ciudades/{cod_municipio}), deleteMutation
  - On mutation success: close SlidePanel, `queryClient.invalidateQueries({ queryKey: ['ciudades'] })`
  - Note: primary key is `cod_municipio` (string), not `id`
- **Files**: MODIFY `src/pages/CiudadesPage.tsx`
- **Dependencies**: D1 (createCiudad, updateCiudad, deleteCiudad), B6
- **Verification**: Create, edit, delete ciudades; list refreshes; SlidePanel opens/closes

### I2. Create form for Ciudad (inline or separate)
- **Title**: Form for Ciudad create/edit in SlidePanel
- **Description**: Form fields:
  - cod_municipio (text, required, disabled in edit mode)
  - nombre (text, required)
  - departamento (text)
  
  Can be inline or `CiudadFormModal.tsx`. Shows API errors inline. On edit, `cod_municipio` is read-only.
- **Files**: MODIFY `src/pages/CiudadesPage.tsx` or CREATE `src/components/CiudadFormModal.tsx`
- **Dependencies**: I1
- **Verification**: Form creates/updates ciudad; duplicate cod_municipio shows validation error

---

## Full Dependency Graph

```
Group A: ──────────────────────────────────────────────────────
  A1 → A2 → A3 → A4─┐              A9─┐
        A2 → A5──────┤       A10──────┤
        A2 → A6──────┤               │
        A2 → A7──────┤               │
        A2 → A8──────┼→ A11 → A12 → A13
                     │
Group B: ────────────┼─────────────────────────────────────────
  B1 → B2 → B3 → B4─┼→ B5 → B6 → B7
                     │
Group C: ────────────┼─────────────────────────────────────────
  C1 → C2 → C3 → C4─┘
                     │
Group D (after A,B,C): D1 → D2 → D3 → D4 → D5
                     │
Groups E,F,G,H,I: ───┘ (after D)
  E1 → E2
  F1 → F2 → F3
  G1 → G2
  H1 → H2
  I1 → I2
```

## Execution Order (Recommended)

### Phase 1 — Backend (parallel safe)
```
Day 1:	A1 → A2 → A3 → A4
        B1 → B2 → B3
        C1
```

### Phase 2 — Backend (sequential)
```
Day 2:	A5 → A6 → A7 → A8 → A9 → A10 → A11 → A12 → A13
        B4 → B5 → B6 → B7
        C2 → C3 → C4
```

### Phase 3 — Frontend API layer
```
Day 3:	D1 → D2 → D3 → D4 → D5
```

### Phase 4 — Frontend pages (parallel safe)
```
Day 4:	E1 → E2
        F1 → F2 → F3
        G1 → G2
        H1 → H2
        I1 → I2
```

### Phase 5 — Verification
```
Day 5:	php artisan test   (all backend tests pass)
        npm run build      (frontend builds without errors)
        npm run dev        (manual smoke test)
```

---

## Summary of Files to Create/Modify

### CREATE (Backend — 22 files)
1. `app/Domain/Entities/Maestro.php`
2. `app/Domain/Repositories/MaestroRepositoryInterface.php`
3. `app/Infrastructure/Persistence/EloquentMaestroRepository.php`
4. `app/Application/UseCases/Maestro/IndexMaestroUseCase.php`
5. `app/Application/UseCases/Maestro/ShowMaestroUseCase.php`
6. `app/Application/UseCases/Maestro/StoreMaestroUseCase.php`
7. `app/Application/UseCases/Maestro/UpdateMaestroUseCase.php`
8. `app/Application/UseCases/Maestro/DeleteMaestroUseCase.php`
9. `app/Http/Requests/MaestroRequest.php`
10. `app/Http/Resources/MaestroResource.php`
11. `app/Application/UseCases/Ciudad/StoreCiudadUseCase.php`
12. `app/Application/UseCases/Ciudad/UpdateCiudadUseCase.php`
13. `app/Application/UseCases/Ciudad/DeleteCiudadUseCase.php`
14. `app/Http/Requests/CiudadRequest.php`
15. `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php`
16. `app/Http/Controllers/API/SecurityDashboardController.php`
17. `tests/Feature/API/MaestroControllerTest.php` (~120 lines)
18. `tests/Feature/API/SecurityDashboardTest.php` (~80 lines)
19. (CREATE or modify) `app/Http/Resources/CiudadResource.php` (optional)

### MODIFY (Backend — 7 files)
20. `app/Http/Controllers/API/MaestroController.php` — refactor to UseCases
21. `app/Http/Controllers/API/CiudadController.php` — add store, update, destroy
22. `routes/api.php` — move maestros/ciudades under RBAC, add POST/PUT/DELETE, add seguridad/dashboard
23. `app/Domain/Repositories/CiudadRepositoryInterface.php` — add create, update, delete
24. `app/Infrastructure/Persistence/EloquentCiudadRepository.php` — implement create, update, delete
25. `tests/Feature/API/CiudadControllerTest.php` — add CRUD tests

### CREATE (Frontend — 4 files)
26. `src/pages/SeguridadDashboardPage.tsx`
27. `src/pages/MarcasPage.tsx`
28. `src/components/MarcaFormModal.tsx` (or in pages/)
29. `src/components/ProductoFormModal.tsx` (or in pages/)

### MODIFY (Frontend — 11 files)
30. `src/api/crmApi.ts` — add CRUD functions + getSecurityDashboard
31. `src/api/types.ts` — add new types
32. `src/App.tsx` — add routes
33. `src/pages/ModulosPages.tsx` — remove SeguridadPage stub
34. `src/pages/index.ts` — update exports
35. `src/pages/ProductosPage.tsx` — add CRUD with SlidePanel
36. `src/pages/MaestrosPage.tsx` — add CRUD with SlidePanel
37. `src/pages/CiudadesPage.tsx` — add CRUD with SlidePanel
38. `src/config/roles.ts` — add MARCAS module
39. `src/components/layout/Sidebar.tsx` — add Marcas to Seguridad group
