# Technical Design: Mejoras Post-Lanzamiento

**Change**: `mejoras-post-lanzamiento`
**Phase**: Design
**Topic Key**: `sdd/mejoras-post-lanzamiento/design`

---

## 1. Architecture Overview

This change touches 7 independent capabilities across both backend (Laravel) and frontend (React). The implementation order is:

```
Backend-first:
  1. CSV Seeders (no API changes, pure DB)
  2. Directorio Filter (API param change)
  3. Cotizacion: IVA in request + Send Quote endpoint
  4. Seguimiento: ICS calendar param fix

Frontend-second:
  5. Menu Reorganization (routing + sidebar)
  6. Mobile Bottom Nav (new component)
  7. DetalleLineEditor fixes (UX + payload)
  8. Send Quote UI (modal + API call)
```

### Component Dependency Graph

```
┌─────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  CSV Seeders    │────▶│  DirectorioPage  │────▶│  Menu Reorg      │
│  (RealData)     │     │  (estado filter) │     │  (Sidebar+Routes)│
└─────────────────┘     └──────────────────┘     └────────┬─────────┘
                                                          │
┌─────────────────┐     ┌──────────────────┐     ┌────────▼─────────┐
│  Send Quote UI  │◀────│  CotizacionCtrl  │◀────│  Mobile BottomNav│
│  (modal+API)    │     │  (enviar+cambio) │     │  (3-group nav)   │
└────────┬────────┘     └──────────────────┘     └──────────────────┘
         │
┌────────▼────────┐     ┌──────────────────┐
│  DetalleLineEd  │────▶│  DetalleOppCtrl  │
│  (IVA+UX fix)   │     │  (iva in payload)│
└─────────────────┘     └──────────────────┘
```

---

## 2. CSV Seeders Architecture

### 2.1 File Structure

```
database/seeders/
├── RealDataSeeder.php          # Master seeder (orchestrator)
├── CsvSeederTrait.php          # Shared CSV parsing logic
├── CiudadCsvSeeder.php         # Docs/ciudades.csv → ciudades table
├── ContactoCsvSeeder.php       # Docs/contactos.csv → contactos table
├── EntidadCsvSeeder.php        # Docs/Entidades.csv → entidad table
└── ProductoCsvSeeder.php       # Docs/productos.csv → productos table
```

### 2.2 CsvSeederTrait — Shared Parsing Logic

```php
// database/seeders/CsvSeederTrait.php
trait CsvSeederTrait
{
    protected function parseCsv(string $path, string $delimiter = ';'): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        // Read header row
        $headers = $this->cleanHeaders(fgetcsv($handle, 0, $delimiter));

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Skip empty rows (all cells empty or just whitespace)
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $value = $data[$i] ?? null;
                $value = $this->cleanCell($value);
                $row[$header] = $value;
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    protected function cleanHeaders(array $headers): array
    {
        return array_map(function ($h) {
            // Normalize: lowercase, trim, replace spaces with underscores
            $h = trim(strtolower($h ?? ''));
            $h = str_replace([' ', '-'], '_', $h);
            return $h;
        }, $headers);
    }

    protected function cleanCell(?string $value): ?string
    {
        if ($value === null) return null;

        $value = trim($value);

        // Excel artifact: #¿NOMBRE? → null
        if (str_contains($value, '#¿NOMBRE?')) {
            return null;
        }

        // Empty string → null
        if ($value === '') {
            return null;
        }

        return $value;
    }

    protected function isEmptyRow(array $data): bool
    {
        return empty(array_filter($data, fn ($v) => trim($v ?? '') !== ''));
    }

    protected function parseExcelDate(?string $value): ?string
    {
        if (!$value) return null;

        // Format: dd/mm/YYYY or dd/mm/YYYY HH:MM:SS
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    protected function parsePercentage(?string $value): float
    {
        if (!$value) return 0.0;
        return (float) str_replace('%', '', trim($value));
    }
}
```

### 2.3 CiudadCsvSeeder

**CSV columns**: `cod_municipio`, `Municipio`, `Tipo_municipio`, `Cod_departamento`, `Departamento`, `Longitud`, `Latitud`, `created_at`, `updated_at`

**DB columns**: `cod_municipio`, `nombre`, `departamento`

**Mapping**:
- `cod_municipio` → `cod_municipio` (pad with leading zero: `5001` → `05001`)
- `Municipio` → `nombre` (title case: `MEDELLÍN` → `Medellín`)
- `Departamento` → `departamento` (title case)

**Idempotent strategy**: `DB::table('ciudades')->updateOrInsert(['cod_municipio' => $code], [...])`

### 2.4 EntidadCsvSeeder

**CSV columns**: `ID`, `Tipo Persona`, `Nombre`, `Dominio`, `Logo`, `Estado`, `Tipo_ID`, `Identificacion`, `Direccion`, `Nombre comercial`, `Ciudad`, `Fecha Creacion`, `Fecha actualizacion`, `Asignado`

**DB columns**: `nombre`, `identificacion`, `tipo`, `estado`, `email`, `telefono`, `ciudad_id`, `dominio`, `nombre_comercial`

**Mapping**:
- `Nombre` → `nombre`
- `Identificacion` → `identificacion`
- `Tipo Persona` → `tipo`: `18` → `Juridica`, else `Natural`
- `Estado` → `estado`: `25` → `Prospecto` (need to verify mapping), unrecognized → `Prospecto`
- `Ciudad` → lookup `ciudades.nombre` → `ciudad_id` (fuzzy match, NULL if not found)
- `Fecha Creacion` → `created_at` (via `parseExcelDate`)
- `#¿NOMBRE?` in any cell → `NULL`

**Estado mapping table** (from CSV numeric codes):
| CSV Value | DB Estado |
|-----------|-----------|
| `25` | `Prospecto` |
| `Activo` / `Cliente` | `Activo` |
| `Inactivo` | `Inactivo` |
| Other | `Prospecto` (default) |

### 2.5 ContactoCsvSeeder

**CSV columns**: `ID`, `Nombres`, `Apellidos`, `Cargo`, `tel contacto`, `Movil`, `email contacto`, `Email 2 Contacto`, `Entidad`, `Rol`, `etapa`, `EtiquetasId`

**DB columns**: `entidad_id`, `nombres`, `apellidos`, `cargo`, `tel_contacto`, `movil`, `email_contacto`, `email_secundario`, `rol`, `etapa`

**Mapping**:
- `Entidad` → `entidad_id` (integer, skip row if entidad doesn't exist)
- All other fields direct mapping with `cleanCell()` for NULL handling

**Ordering**: Must run AFTER EntidadCsvSeeder (foreign key dependency).

### 2.6 ProductoCsvSeeder

**CSV columns**: `ProductoID`, `Producto Detalle`, `IVA`, `Producto`, `Linea Negocio`

**DB columns**: `nombre`, `precio`, `medida`, `estado`, `iva`

**Mapping**:
- `Producto Detalle` → `nombre`
- `IVA` → `iva` (via `parsePercentage`: `19%` → `19.0`)
- `Producto` → could map to a category/group field if exists
- `Linea Negocio` → `estado` or a separate field

### 2.7 RealDataSeeder (Orchestrator)

```php
class RealDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->callWith(CiudadCsvSeeder::class, []);
        $this->command->info('Ciudades seeded.');

        $this->callWith(EntidadCsvSeeder::class, []);
        $this->command->info('Entidades seeded.');

        $this->callWith(ContactoCsvSeeder::class, []);
        $this->command->info('Contactos seeded.');

        $this->callWith(ProductoCsvSeeder::class, []);
        $this->command->info('Productos seeded.');
    }
}
```

### 2.8 Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| CSV delimiter is not `;` | Auto-detect: check first line for `;` vs `,` count |
| Excel artifacts in CSV | `cleanCell()` strips `#¿NOMBRE?`, empty rows skipped |
| Date format mismatch | `parseExcelDate()` handles `dd/mm/YYYY` and `dd/mm/YYYY HH:MM:SS` |
| Foreign key violations | Seed in order: ciudades → entidades → contactos → productos |
| Large CSV memory usage | Process row-by-row with `fgetcsv`, not `file()` |

---

## 3. Directorio Filter Implementation

### 3.1 Backend Change

**File**: `app/Infrastructure/Persistence/BaseRepository.php`

**Change**: Modify `applyFilters` to handle `estado` as comma-separated string → `whereIn` with case-insensitive matching.

```php
// BEFORE (BaseRepository::applyFilters)
protected function applyFilters($query, array $filters)
{
    foreach ($filters as $field => $value) {
        if ($value !== null && $value !== '') {
            $query->where($field, $value);
        }
    }
    return $query;
}

// AFTER
protected function applyFilters($query, array $filters)
{
    foreach ($filters as $field => $value) {
        if ($value === null || $value === '') continue;

        // Special handling for estado: support comma-separated values + case-insensitive
        if ($field === 'estado') {
            $estados = array_map('trim', explode(',', $value));
            $estados = array_filter($estados);
            if (!empty($estados)) {
                $query->whereIn(
                    DB::raw('LOWER(' . $field . ')'),
                    array_map('strtolower', $estados)
                );
            }
            continue;
        }

        $query->where($field, $value);
    }
    return $query;
}
```

**Alternative (cleaner)**: Override `applyFilters` in `EloquentEntidadRepository`:

```php
// app/Infrastructure/Persistence/EloquentEntidadRepository.php
protected function applyFilters($query, array $filters)
{
    // Handle estado as IN clause with case-insensitive match
    if (!empty($filters['estado'])) {
        $estados = array_map('trim', explode(',', $filters['estado']));
        $estados = array_filter($estados);
        if (!empty($estados)) {
            $query->where(function ($q) use ($estados) {
                foreach ($estados as $estado) {
                    $q->orWhereRaw('LOWER(estado) = ?', [strtolower($estado)]);
                }
            });
        }
        unset($filters['estado']);
    }

    // Default filter for remaining fields
    foreach ($filters as $field => $value) {
        if ($value !== null && $value !== '') {
            $query->where($field, $value);
        }
    }

    return $query;
}
```

**Decision**: Override in `EloquentEntidadRepository` — keeps BaseRepository clean and the IN clause logic is specific to this endpoint.

### 3.2 Frontend Change

**File**: `dashboard-crm/src/api/crmApi.ts`

```typescript
// BEFORE
export async function getEntidades(params?: { search?: string; per_page?: number }) {
  const { data } = await crmApi.get<...>('/entidad', { params })
  return data
}

// AFTER
export async function getEntidades(params?: {
  search?: string
  per_page?: number
  estado?: string  // comma-separated: "Activo,Prospecto"
}) {
  const { data } = await crmApi.get<...>('/entidad', { params })
  return data
}
```

**File**: `dashboard-crm/src/pages/DirectorioPage.tsx`

Add estado filter dropdown (multi-select) above the search bar:

```tsx
const [estadoFilter, setEstadoFilter] = useState<string>('') // "" | "Activo" | "Prospecto" | "Activo,Prospecto"

// In query:
queryKey: ['entidades', search, estadoFilter],
queryFn: () => getEntidades({
  search,
  per_page: 50,
  estado: estadoFilter || undefined,
}),
```

### 3.3 API Contract Change

| Param | Before | After |
|-------|--------|-------|
| `estado` | Not accepted | `string` — single value OR comma-separated (`"Activo,Prospecto"`) |
| Matching | Exact match | Case-insensitive, supports IN clause |
| Backward compat | N/A | Single value still works as before |

### 3.4 Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| SQL injection via estado param | Values are split and passed as bound parameters |
| Performance with many estados | Max 3 valid estados (Activo, Prospecto, Inactivo) — negligible |
| Existing clients break | Single value still works; case-insensitive is a superset |

---

## 4. Menu Reorganization

### 4.1 New Module Constants

**File**: `dashboard-crm/src/config/roles.ts`

```typescript
export const MODULES = {
  DASHBOARD: 'dashboard',
  SEGURIDAD: 'seguridad',
  MAESTROS: 'maestros',
  DIRECTORIO: 'directorio',
  TALENTO: 'talento',
  CRM: 'crm',
  OPERACIONES: 'operaciones',
  FINANZAS: 'finanzas',
  SEGUIMIENTOS: 'seguimientos',
  USUARIOS: 'usuarios',
  // NEW:
  CONTACTOS: 'contactos',
  CIUDADES: 'ciudades',
  PRODUCTOS: 'productos',
} as const;
```

### 4.2 Updated Role Module Lists

```typescript
export const ROLES: Record<RoleSlug, Role> = {
  super_admin: {
    // ... existing ...
    modules: Object.values(MODULES), // automatically includes new modules
  },

  comercial: {
    // ... existing permissions ...
    modules: [
      MODULES.DASHBOARD,
      MODULES.DIRECTORIO,
      MODULES.CRM,
      MODULES.CONTACTOS,     // NEW
      MODULES.OPERACIONES,
      MODULES.SEGUIMIENTOS,
    ],
  },

  admin: {
    // ... existing permissions ...
    modules: [
      MODULES.DASHBOARD,
      MODULES.SEGURIDAD,
      MODULES.MAESTROS,
      MODULES.DIRECTORIO,
      MODULES.TALENTO,
      MODULES.FINANZAS,
      MODULES.SEGUIMIENTOS,
      MODULES.USUARIOS,
      MODULES.CIUDADES,      // NEW
      MODULES.PRODUCTOS,     // NEW
    ],
  },
};
```

### 4.3 Sidebar Group Structure

**File**: `dashboard-crm/src/components/layout/Sidebar.tsx`

```typescript
const GROUPS: GroupDef[] = [
  {
    label: 'CRM',
    modules: [
      MODULES.DASHBOARD,
      MODULES.DIRECTORIO,
      MODULES.CONTACTOS,    // NEW
      MODULES.CRM,          // "Oportunidades"
    ],
  },
  {
    label: 'ERP',            // Renamed from "Administrativo-ERP"
    modules: [
      MODULES.FINANZAS,
      MODULES.TALENTO,
      MODULES.OPERACIONES,
    ],
  },
  {
    label: 'Seguridad',
    modules: [
      MODULES.SEGURIDAD,
      MODULES.MAESTROS,      // UNFILTERED (remove m !== MAESTROS check)
      MODULES.CIUDADES,      // NEW
      MODULES.PRODUCTOS,     // NEW
      MODULES.USUARIOS,
    ],
  },
];
```

**Key change**: Remove `filter(m => m !== MODULES.MAESTROS)` on line 72.

### 4.4 Icon Choices

```typescript
import {
  // ... existing imports ...
  Users,          // CONTACTOS
  MapPin,         // CIUDADES
  Package,        // PRODUCTOS
  BookOpen,       // MAESTROS
} from 'lucide-react'

const moduleIcons: Record<string, React.ComponentType<{ size?: number }>> = {
  // ... existing ...
  [MODULES.CONTACTOS]: Users,
  [MODULES.CIUDADES]: MapPin,
  [MODULES.PRODUCTOS]: Package,
  [MODULES.MAESTROS]: BookOpen,
}

const moduleLabels: Record<string, string> = {
  // ... existing ...
  [MODULES.CONTACTOS]: 'Contactos',
  [MODULES.CIUDADES]: 'Ciudades',
  [MODULES.PRODUCTOS]: 'Productos',
  [MODULES.MAESTROS]: 'Maestros',
}
```

### 4.5 New Pages

**File**: `dashboard-crm/src/pages/ModulosPages.tsx` (add 3 new placeholder pages)

```tsx
export function ContactosPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-50">Contactos</h1>
        <p className="text-slate-400">Gestión de contactos</p>
      </div>
      <div className="bg-slate-800 p-6 rounded-xl border border-slate-700">
        <p className="text-slate-400 text-center">Módulo en desarrollo...</p>
      </div>
    </div>
  )
}

export function CiudadesPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-50">Ciudades</h1>
        <p className="text-slate-400">Municipios y departamentos</p>
      </div>
      <div className="bg-slate-800 p-6 rounded-xl border border-slate-700">
        <p className="text-slate-400 text-center">Módulo en desarrollo...</p>
      </div>
    </div>
  )
}

export function ProductosPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-50">Productos</h1>
        <p className="text-slate-400">Catálogo de productos y servicios</p>
      </div>
      <div className="bg-slate-800 p-6 rounded-xl border border-slate-700">
        <p className="text-slate-400 text-center">Módulo en desarrollo...</p>
      </div>
    </div>
  )
}

export function MaestrosPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-50">Maestros</h1>
        <p className="text-slate-400">Tablas maestras del sistema</p>
      </div>
      <div className="bg-slate-800 p-6 rounded-xl border border-slate-700">
        <p className="text-slate-400 text-center">Módulo en desarrollo...</p>
      </div>
    </div>
  )
}
```

### 4.6 Route Additions

**File**: `dashboard-crm/src/App.tsx`

```tsx
// Add imports
import {
  ContactosPage,
  CiudadesPage,
  ProductosPage,
  MaestrosPage,
} from './pages/ModulosPages'

// Add routes inside <Routes>
<Route path="/contactos" element={<ContactosPage />} />
<Route path="/ciudades" element={<CiudadesPage />} />
<Route path="/productos" element={<ProductosPage />} />
<Route path="/maestros" element={<MaestrosPage />} />
```

### 4.7 File Change Matrix

| File | Change Type | Description |
|------|-------------|-------------|
| `src/config/roles.ts` | Modify | Add 3 MODULES constants, update role module arrays |
| `src/components/layout/Sidebar.tsx` | Modify | Update GROUPS, add icons/labels, remove MAESTROS filter |
| `src/pages/ModulosPages.tsx` | Modify | Add 4 new placeholder page components |
| `src/pages/index.ts` | Modify | Export new pages |
| `src/App.tsx` | Modify | Add 4 new routes |

---

## 5. Mobile Bottom Nav

### 5.1 Component Architecture

**File**: `dashboard-crm/src/components/layout/BottomNav.tsx` (complete rewrite)

```
BottomNav
├── 3 Group Buttons (CRM, ERP, Seguridad)
│   └── Each button has: icon + label
│
└── Submenu Panel (slide-up)
    ├── Header: Group name + close button
    ├── Nav items (filtered by role)
    └── "No access" message if role has no modules in group
```

### 5.2 Data Structure

```typescript
interface NavGroup {
  key: 'crm' | 'erp' | 'seguridad'
  label: string
  icon: LucideIcon
  modules: string[]  // All modules in this group
}

const NAV_GROUPS: NavGroup[] = [
  {
    key: 'crm',
    label: 'CRM',
    icon: TrendingUp,
    modules: [MODULES.DASHBOARD, MODULES.DIRECTORIO, MODULES.CONTACTOS, MODULES.CRM],
  },
  {
    key: 'erp',
    label: 'ERP',
    icon: Briefcase,
    modules: [MODULES.FINANZAS, MODULES.TALENTO, MODULES.OPERACIONES],
  },
  {
    key: 'seguridad',
    label: 'Seguridad',
    icon: Shield,
    modules: [MODULES.SEGURIDAD, MODULES.MAESTROS, MODULES.CIUDADES, MODULES.PRODUCTOS, MODULES.USUARIOS],
  },
]
```

### 5.3 State Management

```typescript
const [activeGroup, setActiveGroup] = useState<string | null>(null)

function toggleGroup(key: string) {
  setActiveGroup(prev => prev === key ? null : key)
}

function handleNavigate() {
  setActiveGroup(null)  // Close submenu on any navigation
}
```

### 5.4 Role Filtering Logic

```typescript
function getVisibleModules(group: NavGroup, roleModules: string[]): string[] {
  return group.modules.filter(m => roleModules.includes(m))
}
```

### 5.5 Responsive Breakpoints

- **Desktop** (`>= md / 768px`): BottomNav hidden (`hidden md:hidden`), Sidebar visible
- **Mobile** (`< md / 768px`): BottomNav visible (`md:hidden`), Sidebar only via hamburger overlay

The existing `Sidebar` already handles mobile overlay — BottomNav is an ADDITIONAL nav layer, not a replacement.

### 5.6 Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Submenu overlaps content | Use `fixed bottom-16` positioning with z-50 |
| Role has no access to group | Show "No tienes acceso" message instead of empty list |
| Multiple submenus open | Single `activeGroup` state ensures only one open at a time |

---

## 6. Send Quote Flow

### 6.1 Backend: CotizacionController::enviar()

**File**: `app/Http/Controllers/API/CotizacionController.php`

**API Contract Change**:

```
POST /api/v1/oportunidades/{id}/enviar

BEFORE: No body required
AFTER:  Optional JSON body

{
  "mensaje": "Estimado cliente, adjunto cotización...",  // optional string
  "contacto_id": 5                                       // optional int
}
```

**Logic changes**:

```php
public function enviar(Request $request, int $id): JsonResponse
{
    $oportunidad = Oportunidad::with(['entidad', 'contacto', 'detalles', 'contactos'])
        ->findOrFail($id);

    // ... existing guards (estado, detalles, etc.) ...

    // NEW: Resolve target contact
    $contactoId = $request->input('contacto_id');
    if ($contactoId) {
        // Validate contacto_id belongs to this opportunity's entity
        $contacto = Contacto::where('id', $contactoId)
            ->where('entidad_id', $oportunidad->entidad_id)
            ->first();

        if (!$contacto || !$contacto->email_contacto) {
            return $this->errorResponse(
                'El contacto no pertenece a la entidad de la oportunidad o no tiene email.',
                422
            );
        }
    } else {
        // Fallback to existing behavior
        $contacto = $oportunidad->contacto;
        if (!$contacto || !$contacto->email_contacto) {
            return $this->errorResponse(
                'La oportunidad debe tener un contacto con email.',
                422
            );
        }
    }

    // ... mark as Enviada ...

    // NEW: Custom message for email
    $mensaje = $request->input('mensaje');

    // Email: pass custom message to mailable
    Mail::to($contacto->email_contacto)
        ->queue(new CotizacionMail($oportunidad, $mensaje));

    // NEW: Seguimiento with custom message
    $notas = $mensaje
        ? $mensaje
        : "Cotización {$oportunidad->codigo} enviada a {$contacto->email_contacto}";

    Seguimiento::create([
        // ... existing fields ...
        'contacto_id' => $contacto->id,
        'notas' => $notas,
    ]);

    return $this->successResponse([...]);
}
```

### 6.2 Frontend: Email Composition Modal

**New component**: `dashboard-crm/src/components/SendQuoteModal.tsx`

```tsx
interface SendQuoteModalProps {
  oportunidadId: number
  contactos: Contacto[]
  onClose: () => void
  onSent: () => void
}

function SendQuoteModal({ oportunidadId, contactos, onClose, onSent }: Props) {
  const [mensaje, setMensaje] = useState('Estimado cliente,\n\nAdjunto encontrará la cotización...')
  const [contactoId, setContactoId] = useState<number | null>(
    contactos.find(c => c.rol === 'Decisor')?.id ?? contactos[0]?.id ?? null
  )
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSend() {
    setSending(true)
    setError(null)
    try {
      await enviarCotizacion(oportunidadId, {
        mensaje,
        contacto_id: contactoId,
      })
      onSent()
    } catch (err: unknown) {
      if (axios.isAxiosError(err) && err.response?.status === 422) {
        setError(err.response.data.error ?? 'Error de validación')
      } else {
        setError('Error de conexión')
      }
    } finally {
      setSending(false)
    }
  }

  // ... modal UI with textarea + contact dropdown + buttons ...
}
```

### 6.3 API Function Change

**File**: `dashboard-crm/src/api/crmApi.ts`

```typescript
// BEFORE
export async function enviarCotizacion(id: number) {
  const { data } = await crmApi.post<...>(`/oportunidades/${id}/enviar`)
  return data
}

// AFTER
export async function enviarCotizacion(
  id: number,
  body?: { mensaje?: string; contacto_id?: number }
) {
  const { data } = await crmApi.post<...>(`/oportunidades/${id}/enviar`, body ?? {})
  return data
}
```

### 6.4 Integration in CRMPage

Replace `handleEnviar()` in CRMPage.tsx:

```tsx
// BEFORE
function handleEnviar() {
  if (!selectedOportunidadId) return
  setActionLoading('enviar')
  enviar.mutate(selectedOportunidadId, { ... })
}

// AFTER
const [showSendQuoteModal, setShowSendQuoteModal] = useState(false)

function handleEnviar() {
  if (!selectedOportunidadId) return
  setShowSendQuoteModal(true)
}

// In sidebar JSX, replace ActionButtons onEnviar handler
{showSendQuoteModal && selectedOpp && (
  <SendQuoteModal
    oportunidadId={selectedOportunidadId}
    contactos={contactosData?.data?.data ?? []}
    onClose={() => setShowSendQuoteModal(false)}
    onSent={() => {
      setShowSendQuoteModal(false)
      queryClient.invalidateQueries({ queryKey: ['oportunidades'] })
      queryClient.invalidateQueries({ queryKey: ['oportunidad', selectedOportunidadId] })
      queryClient.invalidateQueries({ queryKey: ['seguimientos'] })
    }}
  />
)}
```

### 6.5 Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| 422 from missing `detalles` guard | Guard remains: `detalles->isEmpty()` check stays |
| contacto_id doesn't belong to entity | Backend validates `WHERE entidad_id = ?` |
| Email fails silently | Queue failure logged; seguimiento still created |
| Backward compat broken | Empty body `{}` works same as before |

---

## 7. DetalleLineEditor Fixes

### 7.1 Empty String vs 0 for New Lines

**File**: `dashboard-crm/src/components/DetalleLineEditor.tsx`

```typescript
// BEFORE (line 30)
function addLine() {
  onChange([...lines, { producto_id: null, concepto: '', cantidad: 1, vr_unitario: 0, iva: 0 }])
}

// AFTER
function addLine() {
  onChange([...lines, { producto_id: null, concepto: '', cantidad: 1, vr_unitario: '', iva: '' }])
}
```

**Input value handling** — change `value` prop to handle empty strings:

```tsx
// For vr_unitario input:
<input
  type="number"
  value={line.vr_unitario === 0 ? '' : line.vr_unitario}
  onChange={e => {
    const val = e.target.value === '' ? '' : Number(e.target.value)
    updateLine(i, 'vr_unitario', val)
  }}
  // ...
/>

// For iva input:
<input
  type="number"
  value={line.iva === 0 ? '' : line.iva}
  onChange={e => {
    const val = e.target.value === '' ? '' : Number(e.target.value)
    updateLine(i, 'iva', val)
  }}
  // ...
/>
```

**Type change**: `LineaForm` needs union types:

```typescript
export interface LineaForm {
  id?: number
  producto_id: number | null
  concepto: string
  cantidad: number
  vr_unitario: number | ''  // Changed from number
  iva: number | ''          // Changed from number
}
```

### 7.2 IVA in Save Payload

**File**: `dashboard-crm/src/pages/CRMPage.tsx` — `handleSaveLines()`

```typescript
// BEFORE (line ~705)
const payload = {
  producto_id: line.producto_id,
  concepto: line.concepto || undefined,
  cantidad: line.cantidad,
  vr_unitario: line.vr_unitario,
}

// AFTER
const payload = {
  producto_id: line.producto_id,
  concepto: line.concepto || undefined,
  cantidad: line.cantidad,
  vr_unitario: line.vr_unitario === '' ? 0 : line.vr_unitario,
  iva: line.iva === '' ? 0 : line.iva,
}
```

### 7.3 Backend: DetalleOportunidadRequest

**File**: `app/Http/Requests/DetalleOportunidadRequest.php`

```php
// Add iva to validation rules
$rules = [
    'producto_id' => 'required|integer|exists:productos,id',
    'concepto' => 'nullable|string|max:255',
    'medida' => 'nullable|string|max:10|in:Und,Hrs,Srv',
    'cantidad' => 'required|numeric|min:0.01',
    'vr_unitario' => 'required|numeric|min:0',
    'iva' => 'nullable|numeric|min:0|max:100',  // NEW
];
```

### 7.4 Backend: StoreDetalleOportunidadUseCase

The use case needs to calculate `vr_total` including IVA:

```php
// In StoreDetalleOportunidadUseCase::execute()
$iva = $data['iva'] ?? 0;
$subtotal = $data['cantidad'] * $data['vr_unitario'];
$vr_total = $subtotal * (1 + $iva / 100);

$data['vr_total'] = $vr_total;
```

### 7.5 Also Fix: CRMPage addLine button

**File**: `dashboard-crm/src/pages/CRMPage.tsx` — line ~1264

```tsx
// BEFORE
onClick={() => setLineas(prev => [...prev, { producto_id: null, concepto: '', cantidad: 1, vr_unitario: 0, iva: 0 }])}

// AFTER
onClick={() => setLineas(prev => [...prev, { producto_id: null, concepto: '', cantidad: 1, vr_unitario: '', iva: '' }])}
```

---

## 8. Calendar/Seguimiento Fixes

### 8.1 ICS URL Parameter Fix

**File**: `dashboard-crm/src/api/crmApi.ts`

```typescript
// BEFORE (line 248)
export function downloadMonthlyCalendarIcsUrl(mes: string, contactoId?: number) {
  const base = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8001/api/v1'
  let url = `${base}/seguimientos/calendar.ics?mes=${mes}`
  if (contactoId) url += `&contacto_id=${contactoId}`
  return url
}

// AFTER — rename param, support entidad_id
export function downloadMonthlyCalendarIcsUrl(mes: string, params?: {
  contacto_id?: number
  entidad_id?: number
}) {
  const base = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8001/api/v1'
  let url = `${base}/seguimientos/calendar.ics?mes=${mes}`
  if (params?.contacto_id) url += `&contacto_id=${params.contacto_id}`
  if (params?.entidad_id) url += `&entidad_id=${params.entidad_id}`
  return url
}
```

**File**: `dashboard-crm/src/pages/CRMPage.tsx` — line ~1288

```tsx
// BEFORE
href={downloadMonthlyCalendarIcsUrl(currentMonth, selectedOpp?.entidad_id)}

// AFTER
href={downloadMonthlyCalendarIcsUrl(currentMonth, { entidad_id: selectedOpp?.entidad_id })}
```

### 8.2 Backend: SeguimientoController::exportCalendarIcs

**File**: `app/Http/Controllers/API/SeguimientoController.php`

```php
// In exportCalendarIcs() — add entidad_id support
if ($request->filled('contacto_id')) {
    $query->where('contacto_id', $request->input('contacto_id'));
}

// NEW: Also accept entidad_id
if ($request->filled('entidad_id')) {
    $query->where('entidad_id', $request->input('entidad_id'));
}
```

### 8.3 Query Key Invalidation Fix

**File**: `dashboard-crm/src/pages/CRMPage.tsx` — `createQuickSeg` mutation

```typescript
// BEFORE (line 667)
onSuccess: () => {
  setQuickSegTexto('')
  // ...
  queryClient.invalidateQueries({ queryKey: ['seguimientos'] })
},

// AFTER — the query key ['seguimientos'] is too broad and may not match
// The timeline uses ['seguimientos', 'timeline', selectedOportunidadId]
// The list uses ['seguimientos', 'byOportunidad', selectedOportunidadId]
// Using prefix match:
onSuccess: () => {
  setQuickSegTexto('')
  setQuickSegFecha(new Date().toISOString().slice(0, 10))
  setQuickSegHora('')
  // Invalidate ALL seguimiento queries for this opportunity
  queryClient.invalidateQueries({ queryKey: ['seguimientos', 'timeline', selectedOportunidadId] })
  queryClient.invalidateQueries({ queryKey: ['seguimientos', 'byOportunidad', selectedOportunidadId] })
},
```

**Note**: The spec says to use `{ queryKey: ['seguimientos'] }` which would match both. But the current code already does this. The actual issue might be that the `selectedOportunidadId` is stale in the mutation closure. Fix: use a functional approach or read from ref.

**Better fix**: Use `queryClient.invalidateQueries({ queryKey: ['seguimientos'], exact: false })` — this invalidates ALL queries starting with `['seguimientos']`, which covers both `['seguimientos', 'byOportunidad', id]` and `['seguimientos', 'timeline', id]`.

```typescript
onSuccess: () => {
  setQuickSegTexto('')
  setQuickSegFecha(new Date().toISOString().slice(0, 10))
  setQuickSegHora('')
  queryClient.invalidateQueries({ queryKey: ['seguimientos'], exact: false })
},
```

---

## 9. Complete File Change Matrix

### Backend (crm-laravel/)

| File | Change | Lines Affected |
|------|--------|---------------|
| `database/seeders/CsvSeederTrait.php` | **NEW** | ~80 |
| `database/seeders/RealDataSeeder.php` | **NEW** | ~25 |
| `database/seeders/CiudadCsvSeeder.php` | **NEW** | ~50 |
| `database/seeders/EntidadCsvSeeder.php` | **NEW** | ~70 |
| `database/seeders/ContactoCsvSeeder.php` | **NEW** | ~50 |
| `database/seeders/ProductoCsvSeeder.php` | **NEW** | ~40 |
| `app/Infrastructure/Persistence/EloquentEntidadRepository.php` | Modify | +15 (applyFilters override) |
| `app/Http/Controllers/API/CotizacionController.php` | Modify | ~30 (enviar method) |
| `app/Http/Controllers/API/SeguimientoController.php` | Modify | +5 (entidad_id param) |
| `app/Http/Requests/DetalleOportunidadRequest.php` | Modify | +1 (iva rule) |
| `app/Mail/CotizacionMail.php` | Modify | TBD (accept custom message) |

### Frontend (dashboard-crm/)

| File | Change | Lines Affected |
|------|--------|---------------|
| `src/config/roles.ts` | Modify | +10 (new modules + role arrays) |
| `src/components/layout/Sidebar.tsx` | Modify | ~20 (groups, icons, labels) |
| `src/components/layout/BottomNav.tsx` | **REWRITE** | ~100 |
| `src/pages/ModulosPages.tsx` | Modify | +60 (4 new pages) |
| `src/pages/index.ts` | Modify | +1 (exports) |
| `src/App.tsx` | Modify | +8 (imports + routes) |
| `src/api/crmApi.ts` | Modify | ~15 (getEntidades, enviarCotizacion, downloadMonthlyCalendarIcsUrl) |
| `src/api/types.ts` | Modify | +1 (DetalleOportunidadCreate.iva) |
| `src/components/DetalleLineEditor.tsx` | Modify | ~20 (empty string, type change) |
| `src/pages/CRMPage.tsx` | Modify | ~30 (send quote, line save, query invalidation) |
| `src/components/SendQuoteModal.tsx` | **NEW** | ~80 |

---

## 10. Testing Strategy

### 10.1 Backend Tests

**CSV Seeders** (Feature tests):
```php
// tests/Feature/Seeders/RealDataSeederTest.php
public function test_ciudades_seeded_from_csv()
public function test_seeder_is_idempotent()
public function test_excel_artifacts_are_cleaned()
public function test_empty_rows_are_skipped()
public function test_entidades_reference_ciudades()
public function test_contactos_reference_entidades()
public function test_productos_iva_parsed_correctly()
```

**Directorio Filter** (Feature test):
```php
// tests/Feature/API/EntidadFilterTest.php
public function test_filter_by_single_estado()
public function test_filter_by_multiple_estados()
public function test_filter_is_case_insensitive()
public function test_no_filter_returns_all()
```

**Send Quote** (Feature test):
```php
// tests/Feature/API/CotizacionControllerTest.php
public function test_send_with_custom_message_and_contact()
public function test_send_without_body_uses_default()
public function test_send_with_invalid_contact_returns_422()
```

**ICS Calendar** (Feature test):
```php
// tests/Feature/API/SeguimientoIcsTest.php
public function test_calendar_filtered_by_entidad_id()
public function test_calendar_filtered_by_contacto_id()
```

### 10.2 Frontend Tests

- **Menu reorg**: Manual testing — verify each role sees correct modules
- **Mobile bottom nav**: Manual testing on device/emulator
- **Send quote modal**: Manual testing — verify email sent with custom message
- **DetalleLineEditor**: Manual testing — verify empty inputs, IVA inheritance, save payload
- **Timeline refresh**: Manual testing — verify new seguimiento appears without page refresh

### 10.3 Integration Testing

1. Run `php artisan db:seed --class=RealDataSeeder` — verify counts
2. Run `php artisan test` — all tests pass
3. Open Directorio — verify estado filter works
4. Login as comercial — verify only CRM group visible
5. Open mobile view — verify 3-group bottom nav
6. Create opportunity → add lines → save → verify IVA in DB
7. Send quote with custom message → verify email + seguimiento

---

## 11. Rollback Plan

Each capability is independent and can be reverted individually:

| Capability | Rollback Action |
|-----------|----------------|
| CSV Seeders | Delete new seeder files; existing data unaffected |
| Directorio Filter | Revert `EloquentEntidadRepository` to original |
| Menu Reorg | Restore `Sidebar.tsx`, `roles.ts`, `App.tsx` to previous state |
| Mobile Bottom Nav | Delete `BottomNav.tsx` rewrite; restore original file |
| Send Quote | Revert `CotizacionController::enviar()` and `crmApi.ts` |
| DetalleLineEditor | Revert type changes and payload changes |
| ICS Fix | Revert parameter name change |

---

## 12. Implementation Order

Recommended PR sequence (each can be merged independently):

1. **PR 1**: CSV Seeders (backend only, no API impact)
2. **PR 2**: Directorio Filter (backend + frontend, isolated)
3. **PR 3**: Menu Reorganization (frontend only, isolated)
4. **PR 4**: Mobile Bottom Nav (frontend only, isolated)
5. **PR 5**: DetalleLineEditor Fixes (frontend + backend request validation)
6. **PR 6**: Send Quote Flow (frontend modal + backend endpoint)
7. **PR 7**: Calendar/Seguimiento Fixes (frontend URL + backend param)
