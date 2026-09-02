# Change: crud-seguimiento

## Why

The Seguimiento (contact action) CRUD has most of its backend surface working — controller, request, resource, repository, five use cases, notification, seeder, and 11 passing tests. But it has accumulated technical debt: the canonical model lives only in a deprecated alias, the notification fan-outs to every admin instead of the assigned comercial, the author of a seguimiento is never recorded automatically, the frontend search has no debounce, and the kanban of oportunidades has no way to register a seguimiento from a card.

More importantly, comercials today have **no visual surface** to manage their pending follow-ups. The only view is the table in `SeguimientosPage`, which doesn't group by estado, doesn't surface the related empresa or oportunidad, and has no calendar view. This forces comercials to reconstruct their day from a raw list, leading to missed calls, missed emails, and lost pipeline.

This change closes the 8 known backend gaps and introduces a proper UX surface: cards that show pending and scheduled seguimientos with the empresa and oportunidad clearly visible, plus a calendar view organized by comercial so each salesperson sees only their own day/week/month.

## What Changes

### Backend gaps closure

- **[BREAKING] Create canonical `Modules\CRM\Models\Seguimiento.php` model** with proper Eloquent relations (`oportunidad`, `contacto`, `entidad`, `autor`). Deprecate `app/Models/Seguimiento.php` (it already extends a now-existing `Modules\CRM\Models\Seguimiento`).
- **[FIX] `StoreSeguimientoUseCase`** auto-sets `autor_id = Auth::id()` and `created_by = Auth::id()` on create. Today both stay NULL.
- **[FIX] `FollowUpNotification` routing** — notify only the comercials assigned to the seguimiento's entidad via `entidad_usuario`. Fall back to all admins only if no comercial is mapped. Recipients are now scoped, not global.
- **[FIX] `SeguimientoRequest`** validates `fecha_fin >= fecha` when both are present. Returns 422 with clear message otherwise.
- **[NEW]** `GET /api/v1/seguimientos/mios?estado=&fecha_desde=&fecha_hasta=` — shortcut for "my seguimientos" filtered by current user. Saves the frontend from composing `entidad_id IN (...)` filters for the comercial's entidad list.
- **[NEW]** `GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM` — calendar payload for a given comercial in a given month, grouped by day. Used by the new calendar view.

### Frontend UX (dashboard-crm)

- **[NEW] Cards view** on `SeguimientosPage`: seguimientos grouped by estado (`Pendiente` / `Agendado` / `Completado` / `Cancelado`). Each card displays: tipo badge, fecha + hora, notas, **empresa nombre**, **oportunidad codigo**, and action buttons (completar, editar, eliminar, exportar ICS).
- **[NEW] Calendar view per comercial** at `/seguimientos/calendario`. Month grid, seguimientos plotted on their `fecha`. Toggle from cards view via tab switcher. Defaults to the current user's calendar.
- **[FIX] Debounce 1 s on the search input** in `SeguimientosPage`. Reuse the existing `useDebouncedValue` hook from the kanban fix (already committed in `dashboard-crm`). Apply the same inline-skeleton pattern (no full-page unmount).
- **[FIX] "+ Seguimiento" button on oportunidad card** in the kanban (`CRMPage.tsx`). Opens the create modal pre-filled with `oportunidad_id`. Reachable from both the kanban card and the detail side panel.
- **[FIX] "Exportar a calendario (.ics)" button** on each card. Links to `GET /seguimientos/{id}/ics` (backend endpoint already exists, frontend never used it).

## Impact

### Affected specs

- New capability `seguimiento` — delta spec written in `spec` phase, covering CRUD rules, notification routing, debounced search, cards/calendar UX
- Modified capability `oportunidad` — relation to seguimientos already exposed via nested route, no schema change
- New capability `notificaciones` (lightweight) — routing rules for `FollowUpNotification`

### Affected code

**Backend** — `D:\sitios desarrollo\crm-laravel`:

| File | Action |
|------|--------|
| `Modules\CRM\app\Models\Seguimiento.php` | NEW — canonical Eloquent model with relations and `$fillable` |
| `app\Models\Seguimiento.php` | MODIFY — keep as backward-compat alias, deprecated comment updated |
| `app\Application\UseCases\Seguimiento\StoreSeguimientoUseCase.php` | MODIFY — auto-set `autor_id`, `created_by` from `Auth::id()` |
| `app\Notifications\FollowUpNotification.php` | MODIFY — recipients scoped to comercials of the seguimiento's entidad via `entidad_usuario`; falls back to admins |
| `app\Http\Requests\SeguimientoRequest.php` | MODIFY — validate `fecha_fin >= fecha` |
| `app\Http\Controllers\API\SeguimientoController.php` | MODIFY (additive) — `misSeguimientos()` and `calendarioUsuario()` actions |
| `app\Domain\Repositories\SeguimientoRepositoryInterface.php` | MODIFY — declare `findForUser()` and `findCalendarForUser()` |
| `app\Infrastructure\Persistence\EloquentSeguimientoRepository.php` | MODIFY — implement new repo methods |
| NEW tests | TDD — cover all gap closures and new endpoints |

**Frontend** — `D:\sitios desarrollo\dashboard-crm`:

| File | Action |
|------|--------|
| `src\pages\SeguimientosPage.tsx` | MODIFY — cards view (estado-grouped), debounced search, ICS button per card, tab switcher (Cards / Calendar) |
| `src\pages\SeguimientoCalendarioPage.tsx` | NEW — calendar grid, month navigation, click-through to detail |
| `src\components\SeguimientoModal.tsx` | MODIFY — accept optional `oportunidad_id` and `contacto_id` props to pre-fill |
| `src\components\SeguimientoTimeline.tsx` | MODIFY — wire to oportunidad detail panel (read-only mini-timeline) |
| `src\pages\CRMPage.tsx` | MODIFY — add "+ Seguimiento" button on oportunidad card and in the detail side panel |
| `src\api\crmApi.ts` | MODIFY — new methods `getMisSeguimientos`, `getCalendarioUsuario`, `exportSeguimientoIcs` URL builder |
| `src\api\types.ts` | MODIFY — extend `Seguimiento` with `entidad_nombre`, `contacto_nombre`, `oportunidad_codigo` (already present) |
| `src\hooks\useDebouncedValue.ts` | REUSE — already exists from prior commit `2e49762` |

### Affected data

- **No DB migrations required.** The schema (`seguimiento` table) is sufficient for everything in this change.
- **No seed data changes** — existing `SeguimientoCsvSeeder` is compatible.

### Backwards compatibility

- Deprecated `app/Models/Seguimiento.php` keeps working via inheritance from `Modules/CRM/Models/Seguimiento.php`.
- New endpoints (`/seguimientos/mios`, `/usuarios/{id}/calendario`) are additive — no existing route changes.
- Frontend `SeguimientosPage` is restructured but consumes the same backend response shape.
- `FollowUpNotification` recipients change from "all admins" to "comercials + admin fallback" — existing notifications still go out, just to a narrower (correct) audience. Backward compatible.

## Acceptance Criteria

1. `Modules\CRM\app\Models\Seguimiento.php` exists, is the canonical Eloquent model, and `app\Models\Seguimiento.php` extends it for backward compatibility.
2. `POST /api/v1/seguimientos` (auth required) sets `autor_id` and `created_by` to `Auth::id()` automatically. The response shows the populated values.
3. `POST /api/v1/contactos/{id}/acciones` triggers `FollowUpNotification` only for comercials assigned to the contacto's entidad via `entidad_usuario`. Falls back to admins only if no comercial is mapped. No notification if no recipients are found.
4. `SeguimientoRequest` rejects `fecha_fin < fecha` with HTTP 422 and a clear Spanish message.
5. `GET /api/v1/seguimientos/mios?estado=Pendiente` returns only seguimientos for entidades assigned to the current user (Comercial role) or all seguimientos (Admin role).
6. `GET /api/v1/usuarios/{usuarioId}/calendario?mes=YYYY-MM` returns seguimientos grouped by day for that user (validated that requester can see them).
7. `SeguimientosPage` (frontend) renders seguimientos as cards grouped by estado (`Pendiente`, `Agendado`, `Completado`, `Cancelado`). Each card shows empresa nombre and oportunidad codigo.
8. Calendar view at `/seguimientos/calendario` shows a month grid; clicking a day opens a list of that day's seguimientos. Defaults to current comercial (current user).
9. Search input in `SeguimientosPage` has a 1-second debounce. No full-page reload on each keystroke. Filtered query fires after the user stops typing.
10. Oportunidad card in kanban (`CRMPage.tsx`) has a "+ Seguimiento" button. Clicking opens the create modal pre-filled with `oportunidad_id`. Same in the detail side panel.
11. Each seguimiento card has an "Exportar a calendario (.ics)" button that opens/downloads the `.ics` file from `GET /seguimientos/{id}/ics`.
12. All existing 11 tests in `SeguimientoControllerTest.php` still pass.
13. New tests added: `autor_id` auto-set, notification routing per comercial, `fecha_fin` validation, `GET /seguimientos/mios`, `GET /usuarios/{id}/calendario`, debounce behavior in cards view.

## Risks

- **Notification routing change**: existing code notifies all admins; new code notifies only comercials (with admin fallback). If `entidad_usuario` is stale for some entidad, those comercials would miss the notification. *Mitigation*: graceful fallback to admins, log a warning when no comercial is mapped.
- **Frontend calendar dependency**: chose to use plain CSS grid (no new library). Month math is hand-rolled. *Mitigation*: extract `getMonthMatrix(year, month)` to a tiny utility with its own tests.
- **`Modules\CRM\Models\Seguimiento.php` namespace collision**: verified there is no existing model at that path. Safe to create.
- **`fecha_fin` validation may reject existing data** if some legacy rows have `fecha_fin < fecha`. *Mitigation*: validation runs only at write time, not on read.
- **Debounce on cards view**: the existing `useDebouncedValue` hook is generic; should work, but needs verification on the cards view specifically (the prior fix was on kanban).
