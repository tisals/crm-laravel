# Discard Report: gestion-contactos-oportunidades

| Field | Value |
|-------|-------|
| Status | **Discarded (misplaced — wrong repo)** |
| Date | 2026-08-27 |
| Original location | `Docs/changes/gestion-contactos-oportunidades/` (4 files: proposal/spec/design/tasks) |
| Current location | removed from crm-laravel |
| Moved to | `D:\sitios desarrollo\dashboard-crm\docs\changes\gestion-contactos-oportunidades\` |
| Reason | Frontend change for dashboard-crm React app; never belonged in crm-laravel |
| Implementation status | **Delivered** (in dashboard-crm, NOT in crm-laravel) |

---

## 1. Why this change was in crm-laravel (and shouldn't have been)

The `proposal.md` (now in dashboard-crm) describes work for the **React frontend**:

- Affected Areas listed in proposal.md L43-49:
  - `src/pages/DirectorioPage.tsx`
  - `src/pages/CRMPage.tsx`
  - `src/components/ContactoFormModal.tsx`
  - `src/components/EntidadFormModal.tsx`
  - `src/api/crmApi.ts`
- Intent (proposal.md L5): *"Completar el flujo frontend de gestión de contactos y oportunidades en el dashboard React. El backend tiene CRUD completo para Contacto, Entidad, Oportunidad, DetalleOportunidad y Seguimiento, pero el frontend carece de formularios..."*

This is 100% dashboard-crm scope. crm-laravel's backend CRUD was already complete at proposal time and was explicitly marked Out-of-Scope (proposal.md L18).

The change was tracked in crm-laravel's `Docs/changes/` by mistake — likely because the docs/changes convention was set up before dashboard-crm had its own tracking structure.

## 2. Why we move (not archive in place)

Archiving in crm-laravel would be misleading because:
- No code in crm-laravel was added or changed by this change
- The "tests existing" success criterion (proposal.md L77) referred to backend tests that already existed
- The change's evidence of completion lives in dashboard-crm's source tree

Moving the docs to dashboard-crm + discarding here is the cleanest representation of what actually happened.

## 3. Evidence of delivery (in dashboard-crm)

All 4 capabilities are shipped. See the archive-report.md in dashboard-crm's change folder for full evidence per capability:

- `contacto-form`: `dashboard-crm/src/components/ContactoFormModal.tsx` + used in `DirectorioPage.tsx`, `MarcasPage.tsx`, `ContactosPage.tsx`
- `entidad-form`: `dashboard-crm/src/components/EntidadFormModal.tsx` (541 lines) + used in `DirectorioPage.tsx` (3 sites)
- `entidad-oportunidades-link`: `DirectorioPage.tsx:562` `navigate('/crm?entidad_id=...')` + `CRMPage.tsx:787` reads `searchParams.get('entidad_id')`
- `cotizacion-editor` (contact selector): `CRMPage.tsx` contact selector via `EntidadSearchSelect` + `getContactos({ entidad_id })` query

## 4. What's still needed in crm-laravel (unrelated to this discard)

None. This change touched zero crm-laravel files.

## 5. Cross-references for future readers

- **Original docs (now in dashboard-crm)**: `D:\sitios desarrollo\dashboard-crm\docs\changes\gestion-contactos-oportunidades\{proposal,spec,design,tasks}.md`
- **Archive report (delivery evidence)**: `D:\sitios desarrollo\dashboard-crm\docs\changes\gestion-contactos-oportunidades\archive-report.md`
- **Production code**: `D:\sitios desarrollo\dashboard-crm\src\components\{Contacto,Entidad}FormModal.tsx` + usages in `src/pages/`
- **Backend (crm-laravel) CRUD that this frontend wraps**: `app/Http/Controllers/API/ContactoController.php` + `app/Application/UseCases/Contacto/*.php` (existing, untouched)

## 6. Process note

This is the first change in crm-laravel/Docs/changes that was "discarded because misplaced" rather than "discarded because failed". Future similar cases should follow this same pattern: move docs to the correct repo's tracking structure + leave a discard-report here explaining the displacement.