# Explore: Production Blockers + UX Fixes

> Note: This file is a skeleton reconstructed from context after accidental deletion 2026-08-27. Full content not recoverable from git; production evidence cited below.

---

## Context

This change was a surgical, post-launch triage. **Backend CRUD was already complete** (all entities, routes, controllers, use cases shipped in earlier changes), so the work focused on 13 discrete production blockers and UX defects that escaped the original implementation.

---

## Items (13 total, with severity)

| # | Item | Severity | Layer | Evidence |
|---|------|----------|-------|----------|
| **1** | Modal z-index occlusion in nested flows | High UX | Frontend | SlidePanel portal refactor |
| **2** | `ContactoFormModal` missing `apellidos` field | High UX | Frontend | ContactoFormModal.tsx |
| **3** | entity→CRM nav — `searchParams.get('entidad_id')` not wired | Medium UX | Frontend | CRMPage.tsx:787 |
| **4** | (Already resolved — excluded from scope) | — | — | — |
| **5** | `tecnoinnsoft_crm` rename | Low | Infra | Schema rename |
| **9** | Panel scroll leak — slide panel doesn't trap scroll | High UX | Frontend | SlidePanel + useModalOverlay |
| **10** (C1) | PDF "Atención:" line shows `—` when contacto_id is NULL | High | PDF | commit f9c9b10 |
| **11** | (Already resolved — excluded from scope) | — | — | — |
| **12** (C2) | PDF Total = subtotal only (IVA missing) | Critical | PDF | commit 6607368 |
| **13** (C3) | PDF multi-line fields have exaggerated blank space | Medium | PDF | commit ef371cd |
| **14** | DetalleOportunidad PUT/DELETE return 500 | Critical | Backend | DetalleOportunidad model + controller |
| **15** | Cross-semestre code validation accepts malformed codes | Critical | Backend | OportunidadCsvImportUseCase.php:188 |
| **16** | Calendar card onClick — clicks don't open seguimiento modal | Medium UX | Frontend | Calendar component |
| **17** | Mobile filter sidebar overlaps content | Medium UX | Frontend | Sidebar breakpoint |
| **18** | Textareas not resizable (`resize: vertical` missing) | Low UX | Frontend | Global stylesheet |

---

## Severity Tagging Convention

| Severity | Definition |
|----------|------------|
| Critical | Production-blocking bug; revenue or data integrity impact |
| High UX | Daily-use friction; users workaround in production |
| Medium UX | Discoverable; users notice but tolerate |
| Low UX | Cosmetic; rarely noticed |
| Low | Non-functional cleanup |

---

## Evidence That Backend CRUD Was Already Complete

All endpoints listed in `routes/api.php` (verified via grep) for the 13 items existed prior to this change. The change only **fixed bugs in existing flows**, not added missing flows. This is the reason the proposal framed the change as "surgical" rather than "feature."

---

## Production Evidence

- `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php:188` regex `/^GC-\d{2}-\d{4}-\d{3}$/`
- `app/Http/Controllers/API/CotizacionController.php` — PDF builder (C1+C2+C3 fixes)
- `dashboard-crm/src/components/SlidePanel.tsx` — SlidePanel portal + useModalOverlay
- `dashboard-crm/src/components/ContactoFormModal.tsx` — apellidos field
- `dashboard-crm/src/pages/CRMPage.tsx:787` — entity→CRM nav via searchParams
- `routes/api.php:260` `GET /api/v1/oportunidades/{id}/pdf` (PDF endpoint)