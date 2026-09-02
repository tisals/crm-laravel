# Design: crud-seguimiento

## Overview

This document describes the technical architecture for closing 8 known backend gaps in the Seguimiento CRUD and adding a frontend surface (cards + calendar view) so comercials can manage their pending follow-ups. The change spans two repos: `crm-laravel` (backend, this repo) and `dashboard-crm` (frontend). It introduces one canonical model, three new endpoints, one scoped notification, and two new frontend pages. Zero DB migrations.

## Architecture

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                              dashboard-crm (React + TS)                       │
│                                                                              │
│  CRMPage.tsx                                                                │
│   └─ KanbanCard ──[+ Seguimiento]──> CreateSeguimientoModal ──┐              │
│                                                                              │
│  SeguimientosPage.tsx                                                      │
│   ├─ Tab: Cards  ──> CardsViewGrouped ──> SeguimientoCard ──┐              │
│   └─ Tab: Calendar ──> SeguimientoCalendarioPage ───────────┤              │
│                                                                              │
│  SeguimientoCalendarioPage.tsx                                              │
│   └─ MonthGrid ──[click day]──> DayDetailModal ─────────────┤              │
│                                                                              │
│                              ▼ axios (Bearer token)                          │
└──────────────────────────────────────────────────────────────────────────────┘
                              │
                              │ /api/v1/seguimientos/*
                              │ /api/v1/usuarios/{id}/calendario
                              │ /api/v1/contactos/{id}/acciones
                              ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                            crm-laravel (Laravel 12)                          │
│                                                                              │
│  Routes (routes/api.php)                                                   │
│   ├─ GET    /seguimientos/mios                    ← NEW                      │
│   ├─ GET    /usuarios/{id}/calendario             ← NEW                      │
│   ├─ (existing) /seguimientos CRUD + ICS                                    │
│                                                                              │
│  Controllers                                                                │
│   ├─ SeguimientoController (additive: misSeguimientos, calendarioUsuario)   │
│   ├─ ContactoAccionController (notification recipient resolution)            │
│                                                                              │
│  Application (UseCases)                                                     │
│   ├─ StoreSeguimientoUseCase  ← MODIFY: sets autor_id, created_by            │
│   ├─ ListarMisSeguimientosUseCase ← NEW                                     │
│   ├─ ObtenerCalendarioUsuarioUseCase ← NEW                                 │
│                                                                              │
│  Domain (clean architecture)                                                │
│   ├─ Models\CRM\Models\Seguimiento.php  ← NEW (canonical Eloquent)          │
│   ├─ Models\Seguimiento.php  ← KEEP as alias                               │
│   └─ Repositories\SeguimientoRepositoryInterface ← MODIFY (new methods)     │
│                                                                              │
│  Notifications                                                              │
│   └─ FollowUpNotification  ← MODIFY: scoped recipients                      │
│                                                                              │
│  Persistence                                                                │
│   └─ EloquentSeguimientoRepository ← MODIFY: new findForUser(),             │
│                                              findCalendarForUser()           │
│                                                                              │
│  Validation                                                                 │
│   └─ SeguimientoRequest  ← MODIFY: fecha_fin >= fecha rule                   │
└──────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼ Eloquent ORM
                              ▼
                       seguimiento (table)
```

## Backend components

### 1. Canonical model — `Modules/CRM/Models/Seguimiento.php` (NEW)

```php
<?php
namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seguimiento extends Model
{
    use SoftDeletes;

    protected $table = 'seguimiento';

    protected $fillable = [
        'oportunidad_id', 'contacto_id', 'entidad_id',
        'tipo', 'fecha', 'hora', 'fecha_fin',
        'notas', 'autor_id',
        'estado',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_fin' => 'datetime',
        'hora' => 'string',
    ];

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autor_id');
    }
}
```

`app/Models/Seguimiento.php` stays as a deprecated alias:

```php
class Seguimiento extends \Modules\CRM\Models\Seguimiento
{
    // Inherits everything; kept for backward compatibility during the
    // modular-migration transition. New code MUST use Modules\CRM\Models\Seguimiento.
}
```

### 2. `StoreSeguimientoUseCase` — auto-set author

Current behavior: `autor_id` and `created_by` are NOT set (stay NULL).

New behavior: inject `Auth::id()` into the data before `create()`:

```php
public function execute(array $data): mixed
{
    $userId = Auth::id();

    return $this->repository->create(array_merge($data, [
        'autor_id'   => $userId,
        'created_by' => $userId,
    ]));
}
```

`updated_by` is set automatically by the Eloquent `updated_by` observer (existing pattern). Same change applied to `ContactoAccionController::acciones` for both seguimientos it creates (current + next).

### 3. Validation — `SeguimientoRequest`

New rule:

```php
'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha'],
```

`after_or_equal:fecha` ensures `fecha_fin >= fecha` (Laravel uses ISO semantics, and Laravel's `after_or_equal` interprets "after" as strict, "or equal" as inclusive — exactly the spec).

### 4. Notification routing — `FollowUpNotification`

Current behavior: `Notification::send(Usuario::admins()->get(), new FollowUpNotification($seguimiento))`.

New behavior:

```php
private function resolveRecipients(Seguimiento $seguimiento): Collection
{
    // Primary: comercials mapped to the seguimiento's entidad
    $comerciales = Usuario::where('rol_id', Rol::where('nombre', 'Comercial')->value('id'))
        ->whereIn('id', function ($q) use ($seguimiento) {
            $q->select('usuario_id')
                ->from('entidad_usuario')
                ->where('entidad_id', $seguimiento->entidad_id);
        })
        ->where('estado', 'Activo')
        ->get();

    if ($comerciales->isNotEmpty()) {
        return $comerciales;
    }

    // Fallback: all admins and superadmins
    return Usuario::admins()->get();
}

// In ContactoAccionController::scheduleFollowUpNotification:
$recipients = $this->resolveRecipients($seguimiento);
if ($recipients->isEmpty()) {
    Log::warning("FollowUpNotification for seguimiento {$seguimiento->id}: no recipients found");
    return;
}
Notification::send($recipients, new FollowUpNotification($seguimiento));
```

### 5. New endpoint — `GET /api/v1/seguimientos/mios`

```php
// SeguimientoController::misSeguimientos(Request $request): JsonResponse
{
    $user = Auth::user();
    $perPage = min((int) $request->input('per_page', 50), 100);

    $filters = $request->only(['estado', 'fecha_desde', 'fecha_hasta', 'tipo']);

    if ($user->rol?->nombre === 'Comercial') {
        $entidadIds = DB::table('entidad_usuario')
            ->where('usuario_id', $user->id)
            ->pluck('entidad_id');
        $filters['entidad_ids'] = $entidadIds;  // special filter key
    }
    // Admin/SuperAdmin: no entity filter, sees all

    $result = $this->listarMisSeguimientosUseCase->execute($perPage, null, $filters);
    return $this->successResponse($result);
}
```

### 6. New endpoint — `GET /api/v1/usuarios/{usuarioId}/calendario`

```php
// SeguimientoController::calendarioUsuario(Request $request, int $usuarioId): JsonResponse
{
    $authUser = Auth::user();
    if ($authUser->id !== $usuarioId && ! in_array($authUser->rol?->nombre, ['Admin', 'SuperAdmin'])) {
        return $this->errorResponse('No autorizado.', 403);
    }

    $mes = $request->input('mes', now()->format('Y-m'));
    [$year, $month] = explode('-', $mes);

    $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth();
    $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

    $seguimientos = $this->obtenerCalendarioUsuarioUseCase->execute(
        $usuarioId,
        $startOfMonth->toDateString(),
        $endOfMonth->toDateString()
    );

    // Group by day: { "2026-06-01": [...], "2026-06-05": [...] }
    $grouped = $seguimientos->groupBy(fn ($s) => $s->fecha->format('Y-m-d'));

    return $this->successResponse([
        'mes' => $mes,
        'dias' => $grouped,
    ]);
}
```

### 7. Repository extensions

```php
// SeguimientoRepositoryInterface adds:
public function findForUser(int $userId, int $perPage = 50, ?string $search = null, array $filters = []): LengthAwarePaginator;
public function findCalendarForUser(int $userId, string $fechaDesde, string $fechaHasta): Collection;

// EloquentSeguimientoRepository implements them using the same query
// builder pattern as the existing paginate() method, with an IN clause
// on entidad_id when userId is a Comercial.
```

## Frontend components

### 1. `SeguimientosPage.tsx` — upgraded

Two tabs: **Cards** (default) and **Calendar**.

**Cards view**:
- Grouped by `estado`: Pendiente, Agendado (fecha futura), Completado, Cancelado
- Each card displays: tipo badge, fecha+hora, notas (line-clamp-2), `entidad.nombre`, `oportunidad.codigo` (if present), estado badge, action buttons
- "Exportar a calendario (.ics)" button on each card → `window.open('/api/v1/seguimientos/{id}/ics', '_blank')`

**Calendar tab**: renders `<Link to="/seguimientos/calendario">Ver calendario completo</Link>` for now; full implementation in `SeguimientoCalendarioPage`.

**Debounce**: local `searchInput` state, `useDebouncedValue(searchInput, 1000)` from `src/hooks/useDebouncedValue.ts` (already exists from prior work). Query fires via `useQuery({ queryKey: ['seguimientos', debouncedSearch, ...] })`.

### 2. `SeguimientoCalendarioPage.tsx` (NEW)

- Month grid (7 columns × 5-6 rows) of `Date` cells
- Each cell shows: day number + count badge if seguimientos on that day
- Click cell → side panel listing that day's seguimientos (same UI as cards)
- "Previous month" / "Next month" buttons
- Default to current user (call `/usuarios/{Auth::id}/calendario?mes=YYYY-MM`)
- Add a `User` selector for admins to view other comercials' calendars

Implementation note: hand-rolled `getMonthMatrix(year, month): (Carbon[])[]` utility, with its own test in `tests/Unit/getMonthMatrix.test.ts`.

### 3. `SeguimientoModal.tsx` — pre-fill support

Accept optional props:

```typescript
interface SeguimientoModalProps {
  oportunidadId?: number
  contactoId?: number
  entidadId?: number
  // ...existing props
}
```

If provided, initialize the corresponding form fields and show in the title:
"Nuevo seguimiento para {oportunidad.codigo}" or "Nuevo seguimiento para {contacto.nombre}".

### 4. `CRMPage.tsx` — add "+ Seguimiento" button

In the kanban card component (the card that wraps each oportunidad):

```tsx
<button
  onClick={(e) => {
    e.stopPropagation()
    setSeguimientoModal({ oportunidadId: opp.id, entidadId: opp.entidad_id })
  }}
  className="..."
>
  <CalendarPlus size={14} />
</button>
```

State: `seguimientoModal: { oportunidadId: number; entidadId?: number } | null`. When set, render `<SeguimientoModal {...seguimientoModal} onClose={() => setSeguimientoModal(null)} ... />`.

Same button in the opportunity detail side panel.

### 5. `crmApi.ts` — new methods

```typescript
export async function getMisSeguimientos(params?: {
  estado?: string
  fecha_desde?: string
  fecha_hasta?: string
  tipo?: string
  page?: number
  per_page?: number
}) { /* GET /seguimientos/mios */ }

export async function getCalendarioUsuario(usuarioId: number, mes: string) {
  /* GET /usuarios/{usuarioId}/calendario?mes=YYYY-MM */
}

export function buildSeguimientoIcsUrl(id: number): string {
  return `/seguimientos/${id}/ics`
}
```

## Data flows

### Flow 1: Create seguimiento from kanban card

```
User clicks "+ Seguimiento" on kanban card
  └─> setSeguimientoModal({ oportunidadId: 5, entidadId: 10 })
        └─> Render <SeguimientoModal oportunidadId={5} entidadId={10} />
              └─> User fills tipo, notas, fecha, hora, estado
                    └─> submit POST /api/v1/seguimientos { oportunidad_id: 5, entidad_id: 10, ... }
                          └─> StoreSeguimientoUseCase.execute(data)
                                └─> Auth::id() → autor_id, created_by
                                └─> repository.create()
                          └─> 201 Created with populated autor_id
                          └─> If has fecha_fin / scheduled time: FollowUpNotification
                                └─> resolveRecipients (comercials of entidad 10 → fallback admins)
                                      └─> Notification::send(recipients, FollowUpNotification)
        └─> queryClient.invalidateQueries({ queryKey: ['seguimientos'] })
              └─> Cards view refetches with the new card
```

### Flow 2: Calendar view per comercial

```
User navigates to /seguimientos/calendario
  └─> useQuery({ queryKey: ['calendario', usuarioId, mes], queryFn: () => getCalendarioUsuario(usuarioId, mes) })
        └─> GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM
              └─> calendarioUsuario() controller
                    └─> Authorization check (self or admin)
                    └─> ObtenerCalendarioUsuarioUseCase.execute(usuarioId, start, end)
                          └─> EloquentSeguimientoRepository.findCalendarForUser()
                                └─> SELECT * FROM seguimiento
                                    WHERE fecha BETWEEN ? AND ?
                                    AND (entidad_id IN (SELECT entidad_id FROM entidad_usuario WHERE usuario_id = ?)
                                         OR contacto_id IN (SELECT id FROM contacto WHERE entidad_id IN (...)))
                                    AND estado IN ('Pendiente', 'Agendado')
                                    ORDER BY fecha, hora
              └─> 200 OK { mes: "2026-06", dias: { "2026-06-01": [...], ... } }
        └─> Render MonthGrid with markers
              └─> User clicks June 5
                    └─> setSelectedDay("2026-06-05")
                          └─> Side panel opens with that day's seguimientos (cards)
```

### Flow 3: Notification routing change

```
ContactoAccionController::scheduleFollowUpNotification(seguimiento)
  └─> resolveRecipients(seguimiento)
        ├─> Comerciales: SELECT u.* FROM usuarios u
        │   WHERE u.rol_id = (SELECT id FROM roles WHERE nombre = 'Comercial')
        │   AND u.estado = 'Activo'
        │   AND u.id IN (
        │       SELECT usuario_id FROM entidad_usuario
        │       WHERE entidad_id = seguimiento.entidad_id
        │   )
        ├─> if empty: fallback to Usuario::admins()
        └─> if BOTH empty: Log::warning(...); return

  └─> Notification::send(recipients, new FollowUpNotification(seguimiento))
        └─> Each recipient gets the notification via the queue (ShouldQueue interface on FollowUpNotification)
```

## Sequence / rollout

Build in this order to keep each step independently testable:

| # | Phase | Backend | Frontend | Tests |
|---|-------|---------|----------|-------|
| 1 | Canonical model | NEW `Modules\CRM\Models\Seguimiento.php` + relations | — | unit: model relations load correctly |
| 2 | Auto-set author | MODIFY `StoreSeguimientoUseCase` | — | unit: StoreSeguimientoUseCase sets autor_id |
| 3 | Date validation | MODIFY `SeguimientoRequest` | — | feature: 422 on fecha_fin < fecha |
| 4 | Notification routing | MODIFY `FollowUpNotification` recipients logic | — | feature: only comercials notified |
| 5 | Repository extensions | MODIFY repo + interface | — | unit: findForUser, findCalendarForUser |
| 6 | New endpoints | MODIFY controller + new use cases | — | feature: GET /mios, GET /calendario |
| 7 | crmApi methods | — | MODIFY `crmApi.ts`, `types.ts` | — |
| 8 | Debounced cards | — | MODIFY `SeguimientosPage.tsx` (debounce + cards grouping + ICS button) | FE: search debounce |
| 9 | Calendar page | — | NEW `SeguimientoCalendarioPage.tsx` | FE: month matrix utility |
| 10 | "+ Seguimiento" on kanban | — | MODIFY `CRMPage.tsx`, `SeguimientoModal.tsx` | — |
| 11 | Integration | end-to-end via Docker | run e2e (manual) | full suite |

## Test strategy

Strict TDD — tests written first.

**Backend** (`crm-laravel`):

| Test file | Coverage |
|-----------|----------|
| `tests/Unit/Modules/CRM/Models/SeguimientoTest.php` | Relations (oportunidad, contacto, entidad, autor) load; SoftDeletes works |
| `tests/Feature/Seeders/StoreSeguimientoUseCaseTest.php` | autor_id and created_by auto-set; client-provided values ignored |
| `tests/Feature/API/SeguimientoRequestValidationTest.php` | fecha_fin >= fecha rule (422 on violation) |
| `tests/Feature/Notifications/FollowUpNotificationRoutingTest.php` | Comercials receive when mapped; admins fallback when not; no recipients = warning log |
| `tests/Feature/API/SeguimientosMiosEndpointTest.php` | GET /seguimientos/mios scopes by rol (Comercial: own entidades; Admin: all) |
| `tests/Feature/API/SeguimientoCalendarioEndpointTest.php` | GET /usuarios/{id}/calendario groups by day; 403 on cross-user |
| `tests/Feature/API/SeguimientoControllerTest.php` (extend) | All existing 11 tests still pass; +4 new tests for mios/calendario/fecha_fin |

**Frontend** (`dashboard-crm`):

| Test file | Coverage |
|-----------|----------|
| `src/hooks/getMonthMatrix.test.ts` (NEW) | Returns 5-6 rows of 7 days each; correct month boundaries (e.g., June 2026 starts Monday); leap year handling |
| `src/pages/SeguimientosPage.test.tsx` (extend) | Cards grouping by estado; debounce 1s; ICS button opens correct URL |
| `src/pages/SeguimientoCalendarioPage.test.tsx` (NEW) | Month grid renders; click on day opens detail panel |
| `src/pages/CRMPage.test.tsx` (extend) | "+ Seguimiento" button on oportunidad card opens modal pre-filled with oportunidad_id |

CI gate: `composer test` (PHPUnit) + `npm run typecheck` + `npm run test` (Vitest) + `npm run build` all green before merge.

## Migration / rollout

This change has **zero DB migrations**. Rollout steps:

1. Merge backend changes to `main`. Existing tests pass.
2. Deploy `crm-laravel` to staging. Manually test:
   - Create seguimiento via UI → check `autor_id` populated
   - Create seguimiento with `fecha_fin < fecha` → expect 422
   - Create seguimiento for entidad with assigned comercial → check notification goes only to that comercial
   - `GET /seguimientos/mios` as Comercial → only own seguimientos
3. Merge frontend changes. Frontend is decoupled (additive new endpoints, modified page).
4. Deploy frontend. Manually test:
   - Cards view shows empresa + oportunidad
   - Calendar view per comercial
   - "+ Seguimiento" on kanban
   - ICS export

Rollback plan: all changes are backwards compatible. `app\Models\Seguimiento` still works. New endpoints are additive. New frontend components are additive. Worst case: frontend feature flag off (or revert the frontend commit).

## Open risks

1. **Stale `entidad_usuario` mapping** — if comercials are removed but not migrated, they may miss notifications. *Mitigation*: admin fallback in `resolveRecipients`. Log warning if no recipient.
2. **Calendar without date library** — hand-rolled `getMonthMatrix`. *Mitigation*: utility with its own unit tests.
3. **`Modules\CRM\Models\Seguimiento.php` import path collision** — verified no existing model at this path. Safe.
4. **Test environment for `Modules\*` namespace** — Laravel's autoloader must handle `Modules\CRM\app\Models\` (PSR-4 `Modules\\` → `Modules/`). Verified in composer.json.
5. **Performance: 1000+ seguimientos per comercial per month** — calendar query hits `seguimiento` directly. Should be fast enough with proper indexes (`fecha`, `entidad_id`). Add index in a follow-up if needed (NOT this change).
