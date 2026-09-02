# SDD Archive Report — crud-seguimiento

**Change**: crud-seguimiento
**Archived**: 2026-06-26
**Archived to**: `openspec/changes/archive/2026-06-26-crud-seguimiento/`

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| seguimiento | Created | New main spec — 10 requirements, 23 scenarios (full spec from delta — no prior spec existed) |
| oportunidad | Updated | Added 1 requirement (Inline seguimiento action on oportunidad card), 3 scenarios — merged into existing main spec |
| notificaciones | Created | New main spec — 1 requirement (Comercial-scoped routing), 4 scenarios (full spec from delta — no prior spec existed) |

## Archive Contents
- `proposal.md` ✅
- `specs/seguimiento/spec.md` ✅
- `specs/oportunidad/spec.md` ✅
- `specs/notificaciones/spec.md` ✅
- `design.md` ✅
- `tasks.md` ✅ (38/38 tasks — all complete)

**Note**: `verify-report.md` was not present in the change folder at archive time.

## Source of Truth Updated
The following specs now reflect the new behavior:
- `openspec/specs/seguimiento/spec.md` — new canonical spec (was non-existent)
- `openspec/specs/oportunidad/spec.md` — updated with inline seguimiento affordance
- `openspec/specs/notificaciones/spec.md` — new canonical spec (was non-existent)

## Change Summary
Backend CRUD for seguimientos (contacts follow-ups) including:
- Canonical `Modules\CRM\Models\Seguimiento.php` model with proper relations
- `autor_id` / `created_by` auto-set on create via `StoreSeguimientoUseCase`
- `FollowUpNotification` scoped to comercials of the entidad (fallback: admins)
- `fecha_fin >= fecha` validation (HTTP 422 on violation)
- `GET /api/v1/seguimientos/mios` — "my seguimientos" scoped by rol
- `GET /api/v1/usuarios/{id}/calendario` — calendar payload grouped by day
- Frontend: cards view (grouped by estado) + weekly calendar view
- Debounced 1s search on SeguimientosPage
- "+ Seguimiento" button on oportunidad kanban cards
- ICS export button per card
- All 38 tasks implemented, tested, and verified

## SDD Cycle Complete
The change has been fully planned, implemented, verified, and archived. Ready for the next change.
