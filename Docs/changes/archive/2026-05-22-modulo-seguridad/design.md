# Design: Módulo Seguridad

## 1. Seguridad Dashboard (NUEVO backend + frontend)

### Architecture
| Layer | File | Notes |
|-------|------|-------|
| Controller | `app/Http/Controllers/API/SecurityDashboardController.php` | Thin, single `index()` method |
| UseCase | `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php` | Follows `GetDashboardUseCase` pattern |
| Route | `GET /api/v1/seguridad/dashboard` | Under `auth:sanctum` + RBAC middleware |
| Frontend | `SeguridadDashboardPage.tsx` | Replaces stub in ModulosPages |

### Data Structures
**Response (GET /api/v1/seguridad/dashboard)**:
```json
{
  "success": true,
  "data": {
    "kpi": {
      "total_usuarios": 5,
      "usuarios_activos": 4,
      "total_productos": 36,
      "total_marcas": 2
    },
    "distribucion_roles": [
      { "rol": "Super Admin", "total": 1 },
      { "rol": "Admin", "total": 2 }
    ],
    "actividad_reciente": [
      {
        "id": 1,
        "tipo": "Login",
        "usuario": "admin@tecnoinnsoft.dev",
        "fecha": "2026-05-22",
        "hora": "10:30"
      }
    ]
  }
}
```

### Route Design
```php
// In routes/api.php, inside the auth:sanctum + throttle:api group:
Route::middleware('rbac')->group(function () {
    Route::get('/seguridad/dashboard', [SecurityDashboardController::class, 'index'])
        ->name('seguridad.dashboard');
});
```

### Error Handling
- **No data yet**: KPIs return 0 or empty arrays, no error
- **Unauthenticated**: 401 from Sanctum middleware
- **No permission**: 403 from RBAC middleware

## 2. Marcas (Propia) — Frontend only

### Architecture
| Layer | File | Notes |
|-------|------|-------|
| Page | `MarcasPage.tsx` | New, list + SlidePanel CRUD |
| Component | `MarcaFormModal.tsx` | Create/Edit form in SlidePanel |
| API | `crmApi.ts` → `getMarcas()` = alias de `getEntidades({ estado: 'Propia' })` | Reuses existing Entidad endpoints |
| Route | `/marcas` in `App.tsx` | Under Seguridad group |

### Data Structures
```typescript
// Extend existing Entidad type
interface Entidad {
  id: number;
  nombre: string;
  nombre_comercial?: string;
  identificacion?: string;
  dominio?: string;
  estado: 'Activo' | 'Propia' | 'Cliente' | 'Prospecto' | 'Inactivo';  // + 'Propia'
  email?: string;
  telefono?: string;
  // ... existing fields
}

interface MarcaCreate {
  nombre: string;
  nombre_comercial?: string;
  identificacion?: string;
  dominio?: string;
  estado: 'Propia';  // always 'Propia' for marcas
  email?: string;
  telefono?: string;
  tipo_identificacion?: string;
  digito_verificacion?: string;
  direccion?: string;
  ciudad?: string;
  departamento?: string;
  pais?: string;
  logo_url?: string;
}
```

### Component Tree
```
MarcasPage
├── Header (title "Marcas / Propia" + create button)
├── Search bar
├── Table (nombre, identificación, dominio, acciones)
│   ├── Edit button → opens SlidePanel
│   └── Delete button → confirm + destroy
└── SlidePanel (md:w-[30%] min-w-[380px])
    ├── MarcaFormModal
    │   ├── nombre (required)
    │   ├── nombre_comercial
    │   ├── identificación (NIT)
    │   ├── dominio
    │   ├── email, teléfono
    │   ├── dirección, ciudad, departamento
    │   └── logo_url
    └── Save / Cancel buttons
```

### State Management
```typescript
const queryKey = ['marcas', search, page];

// Queries
const { data, isLoading } = useQuery({
  queryKey,
  queryFn: () => getMarcas({ search, page, per_page: 50, estado: 'Propia' })
});

// Mutations
const createMutation = useMutation({
  mutationFn: (data: MarcaCreate) => createEntidad(data),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['marcas'] })
});
const updateMutation = useMutation({
  mutationFn: ({ id, data }) => updateEntidad(id, data),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['marcas'] })
});
const deleteMutation = useMutation({
  mutationFn: (id: number) => deleteEntidad(id),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['marcas'] })
});
```

### Pagination
- 50 per page (`per_page: 50`)
- Use existing pagination from EntidadController (LengthAwarePaginator)

## 3. Productos — Frontend CRUD only

### Architecture
| Layer | File | Notes |
|-------|------|-------|
| Page | `ProductosPage.tsx` | Modified: add create/edit/delete |
| Component | `ProductoFormModal.tsx` | New form in SlidePanel |
| Hooks | Use existing `useProductos` or inline | |
| API | `crmApi.ts` → +`createProducto()`, `updateProducto()`, `deleteProducto()` | Hooks into existing backend endpoints |

### Data Structures
```typescript
interface ProductoCreate {
  nombre: string;          // required
  linea_negocio?: string;
  iva: number;             // default 19
  estado: string;          // default 'Activo'
  medida?: string;         // default 'Und'
  descripcion?: string;
  referencia?: string;
  vr_unitario?: number;
}
```

### Component Tree
```
ProductosPage (modified)
├── Header (title + create button)  ← NEW
├── Search bar
├── Table / Grid
│   ├── Edit button → opens SlidePanel  ← NEW
│   └── Delete button → confirm + destroy  ← NEW
└── SlidePanel (md:w-[30%] min-w-[380px])  ← NEW
    └── ProductoFormModal
        ├── nombre (required)
        ├── línea_negocio (select from maestros campo='Linea_Negocio'?)
        ├── iva (number, default 19)
        ├── vr_unitario
        ├── medida (select: Und, Hora, etc.)
        ├── descripción
        └── estado (select: Activo/Inactivo)
```

### State Management
```typescript
const queryKey = ['productos', search, page];

const createMutation = useMutation({
  mutationFn: (data: ProductoCreate) => createProducto(data),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['productos'] })
});
// Similar for update and delete
```

## 4. Maestros — Full Clean Architecture (NEW backend + frontend)

### Architecture
| Layer | File | Notes |
|-------|------|-------|
| Domain Entity | `app/Domain/Entities/Maestro.php` | New, pure PHP |
| Repository Interface | `app/Domain/Repositories/MaestroRepositoryInterface.php` | New |
| UseCase: Listar | `app/Application/UseCases/Maestro/IndexMaestroUseCase.php` | New, list with filters |
| UseCase: Crear | `app/Application/UseCases/Maestro/StoreMaestroUseCase.php` | New |
| UseCase: Mostrar | `app/Application/UseCases/Maestro/ShowMaestroUseCase.php` | New |
| UseCase: Actualizar | `app/Application/UseCases/Maestro/UpdateMaestroUseCase.php` | New |
| UseCase: Eliminar | `app/Application/UseCases/Maestro/DeleteMaestroUseCase.php` | New |
| Repository | `app/Infrastructure/Persistence/EloquentMaestroRepository.php` | New, extends BaseRepository |
| Controller | `app/Http/Controllers/API/MaestroController.php` | Refactored to use UseCases |
| Request | `app/Http/Requests/MaestroRequest.php` | New |
| Resource | `app/Http/Resources/MaestroResource.php` | New |
| Model | `app/Models/Maestro.php` | Already exists, no changes needed |
| Routes | `routes/api.php` | Move bajo RBAC + POST/PUT/DELETE |
| Tests | `tests/Feature/API/MaestroControllerTest.php` | New, full CRUD |

### Data Structures

**Domain Entity** (`Maestro.php`):
```php
namespace App\Domain\Entities;
class Maestro
{
    public function __construct(
        public int $id,
        public string $nombre,
        public string $campo,
        public string $habilitado,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    public static function fromArray(array $data): self { /* ... */ }
}
```

**Repository Interface** (`MaestroRepositoryInterface.php`):
```php
interface MaestroRepositoryInterface
{
    public function paginate(int $perPage, ?string $search, array $filters): LengthAwarePaginator;
    public function findById(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): bool;
}
```

**FormRequest** (`MaestroRequest.php`):
```php
// POST: nombre required|string|max:255, campo required|string|max:100, habilitado required|in:Y,N
// PUT: same but all optional (sometimes)
public function rules(): array
{
    $rules = [
        'nombre' => 'required|string|max:255',
        'campo' => 'required|string|max:100',
        'habilitado' => 'required|string|in:Y,N',
    ];
    if ($this->isMethod('PUT')) {
        $rules = [
            'nombre' => 'sometimes|string|max:255',
            'campo' => 'sometimes|string|max:100',
            'habilitado' => 'sometimes|string|in:Y,N',
        ];
    }
    return $rules;
}
```

### Route Design
```php
// Replace current read-only routes:
// Route::get('/maestros', ...);
// Route::get('/maestros/{id}', ...);

// With full CRUD under RBAC:
Route::middleware('rbac')->group(function () {
    Route::get('/maestros', [MaestroController::class, 'index'])->name('maestros.index');
    Route::post('/maestros', [MaestroController::class, 'store'])->name('maestros.store');
    Route::get('/maestros/{id}', [MaestroController::class, 'show'])->name('maestros.show');
    Route::put('/maestros/{id}', [MaestroController::class, 'update'])->name('maestros.update');
    Route::delete('/maestros/{id}', [MaestroController::class, 'destroy'])->name('maestros.destroy');
});
```

### Controller (refactored)
```php
class MaestroController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexMaestroUseCase $indexUseCase,
        private ShowMaestroUseCase $showUseCase,
        private StoreMaestroUseCase $storeUseCase,
        private UpdateMaestroUseCase $updateUseCase,
        private DeleteMaestroUseCase $deleteUseCase,
    ) {}

    public function index(Request $request): JsonResponse { /* delegates to indexUseCase */ }
    public function store(MaestroRequest $request): JsonResponse { /* 201 */ }
    public function show(int $id): JsonResponse { /* 200 or 404 */ }
    public function update(int $id, MaestroRequest $request): JsonResponse { /* 200 or 404 */ }
    public function destroy(int $id): JsonResponse { /* 200 or 404 */ }
}
```

### Test Scenarios
1. **List maestros**: 200 with paginated results (same as existing)
2. **Create maestro**: 201 with valid data, 422 with missing fields
3. **Show maestro**: 200 with existing, 404 with nonexistent
4. **Update maestro**: 200 with valid data, 422 with invalid, 404 with nonexistent
5. **Delete maestro**: 200 success, 404 with nonexistent

### Pagination
- Keep existing style (no pagination in current index, returns all)
- For CRUD consistency, add pagination: `?per_page=50&search=...&campo=...`
- Use BaseRepository paginate

## 5. Ciudades — Extend existing Clean Architecture (backend + frontend)

### Architecture
| Layer | File | Notes |
|-------|------|-------|
| Repository Interface | `CiudadRepositoryInterface.php` | Extended: +`create()`, +`update()`, +`delete()` |
| Repository | `EloquentCiudadRepository.php` | Extended: implement new methods |
| UseCase: Crear | `StoreCiudadUseCase.php` | New |
| UseCase: Actualizar | `UpdateCiudadUseCase.php` | New |
| UseCase: Eliminar | `DeleteCiudadUseCase.php` | New |
| Controller | `CiudadController.php` | Extended: +store, +update, +destroy |
| Request | `CiudadRequest.php` | New |
| Resource | `CiudadResource.php` | New (optional, can reuse) |
| Routes | `routes/api.php` | Move bajo RBAC + POST/PUT/DELETE |
| Tests | `CiudadControllerTest.php` | Extended: +create, +update, +delete tests |

### Repository Interface Extension
```php
interface CiudadRepositoryInterface
{
    public function paginate(...): LengthAwarePaginator;
    public function findById(string $codMunicipio): mixed;
    public function create(array $data): mixed;        // NEW
    public function update(string $codMunicipio, array $data): mixed;  // NEW
    public function delete(string $codMunicipio): bool;  // NEW
}
```

### Route Design
```php
// Replace current read-only routes:
// Route::get('/ciudades', ...);
// Route::get('/ciudades/{cod_municipio}', ...);

// With full CRUD under RBAC:
Route::middleware('rbac')->group(function () {
    Route::get('/ciudades', [CiudadController::class, 'index'])->name('ciudades.index');
    Route::post('/ciudades', [CiudadController::class, 'store'])->name('ciudades.store');
    Route::get('/ciudades/{cod_municipio}', [CiudadController::class, 'show'])->name('ciudades.show');
    Route::put('/ciudades/{cod_municipio}', [CiudadController::class, 'update'])->name('ciudades.update');
    Route::delete('/ciudades/{cod_municipio}', [CiudadController::class, 'destroy'])->name('ciudades.destroy');
});
```

### Error Handling
- **Duplicate cod_municipio**: Catch UniqueConstraintViolationException, return 422 with message
- **Ciudad in use**: If referenced by other tables, return 409 Conflict (or handle gracefully)
- **Not found**: Return 404 with standardized message

## 6. Frontend — Shared Patterns

### SlidePanel Pattern (for all CRUD forms)
```typescript
// Reusable pattern used across all CRUD pages
const [slidePanel, setSlidePanel] = useState<{
  open: boolean;
  mode: 'create' | 'edit';
  data?: EntityType;
}>({ open: false, mode: 'create' });

// Trigger from table row:
<button onClick={() => setSlidePanel({ open: true, mode: 'edit', data: item })}>
  <Pencil className="w-4 h-4" />
</button>

// In JSX:
<SlidePanel open={slidePanel.open} onClose={() => setSlidePanel({ open: false, mode: 'create' })}>
  <FormModal
    initialData={slidePanel.data}
    onSave={slidePanel.mode === 'create' ? createMutation.mutate : (data) => updateMutation.mutate({ id: slidePanel.data!.id, data })}
    onCancel={() => setSlidePanel({ open: false, mode: 'create' })}
  />
</SlidePanel>
```

### Pagination Pattern (50 per page)
```typescript
const [page, setPage] = useState(1);
const perPage = 50;

const { data, isLoading } = useQuery({
  queryKey: ['entity', search, page],
  queryFn: () => getEntity({ search, page, per_page: perPage }),
  keepPreviousData: true,
});

// Pagination controls at bottom:
<Pagination
  currentPage={page}
  totalPages={Math.ceil((data?.total ?? 0) / perPage)}
  onPageChange={setPage}
/>
```

### Loading States
- **Initial load**: Skeleton array (divs with animate-pulse)
- **Mutation loading**: Disable submit button, show spinner
- **Mutation success**: Close SlidePanel, invalidate queries, optional toast
- **Mutation error**: Show error message inside SlidePanel (red alert)
- **Empty state**: Icon + "No hay registros" message
- **Delete confirmation**: `window.confirm('¿Estás seguro?')` before calling mutation

## 7. Frontend Routes (App.tsx)

```typescript
// Inside the main routes:
<Route path="/seguridad" element={<SeguridadDashboardPage />} />
<Route path="/marcas" element={<MarcasPage />} />
<Route path="/productos" element={<ProductosPage />} />
<Route path="/maestros" element={<MaestrosPage />} />
<Route path="/ciudades" element={<CiudadesPage />} />
```

## Implementation Order

1. **Backend first**: Maestros CRUD (Clean Arch), Ciudades CRUD (extend), Seguridad Dashboard (new)
2. **API layer**: Add functions to crmApi.ts and types to types.ts
3. **SeguridadDashboardPage**: Replace stub
4. **MarcasPage + MarcaFormModal**: New
5. **ProductosPage**: Modify with CRUD
6. **MaestrosPage**: Modify with CRUD
7. **CiudadesPage**: Modify with CRUD
8. **Tests**: All new backend endpoints + verify frontend builds
