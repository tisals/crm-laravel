# Archive Index — openspec/changes/archive/

> **Purpose**: this file is the single entry point for future agents to locate the full content of every archived/discarded change in crm-laravel.
>
> **Convention**: this `openspec/changes/` folder is a **parallel mirror** of `Docs/changes/`. Standard OpenSpec files (`proposal.md`, `tasks.md`, `design.md`, `spec.md`, `archive-report.md`) live in both folders. The **canonical content store** is `Docs/changes/archive/<date>-<name>/`; the `openspec/changes/archive/` mirror exists so OpenSpec CLI tooling (when present) can introspect the state machine without trawling Docs/.
>
> **Last updated**: 2026-09-09 (1 change archived: tenant-data-model-correction).

---

## Archived (Shipped) — 13 changes

| Date | Change | Type | Canonical source (Docs/changes/) | Mirror (here) | Cross-repo refs | Notes |
|------|--------|------|-----------------------------------|---------------|-----------------|-------|
| 2026-05-14 | crm-phase1-cierre | archive | `archive/2026-05-14-crm-phase1-cierre/` | ✅ mirrored | — | pre-OpenSpec era; 3 files (proposal/design/tasks) |
| 2026-05-15 | sailus-integration-v1 | archive | `archive/2026-05-15-sailus-integration-v1/` | ✅ mirrored | — | pre-OpenSpec era; 3 files |
| 2026-05-21 | mejoras-post-lanzamiento | archive | `archive/2026-05-21-mejoras-post-lanzamiento/` | ✅ mirrored | — | 3 files |
| 2026-05-22 | modulo-seguridad | archive | `archive/2026-05-22-modulo-seguridad/` | ✅ mirrored | — | 3 files (proposal/design/tasks) |
| 2026-06-26 | crud-seguimiento | archive | `archive/2026-06-26-crud-seguimiento/` | ✅ mirrored | — | has `archive-report.md` |
| 2026-07-09 | erp-unification-phase2 | archive | `archive/2026-07-09-erp-unification-phase2/` | ✅ mirrored | — | has `spec.md`; ERP scope (excluded from non-ERP status reports) |
| 2026-07-10 | opportunity-versioning | archive | `archive/2026-07-10-opportunity-versioning/` | ✅ mirrored | — | has `archive-report.md` |
| 2026-08-21 | AFIN-001-modulo-administrativo-financiero | archive | `archive/2026-08-21-AFIN-001-modulo-administrativo-financiero/` | ✅ mirrored (5 files) | — | ERP module; large archive (~190 KB total); has `verify-report.md` |
| **2026-08-27** | **production-blockers-and-ux-fixes** | archive | `archive/2026-08-27-production-blockers-and-ux-fixes/` | ✅ mirrored (4 files) | — | 13 items shipped; PDF C1+C2+C3 formally verified; others per user confirmation |
| **2026-08-27** | **pipelines-crud-webhook** | archive | `archive/2026-08-27-pipelines-crud-webhook/` | ✅ mirrored (5 files) | — | Production: 6 Pipeline use cases exist; **routes NOT wired** (open finding) |
| **2026-08-27** | **multi-app-auth-identity** | archive | `archive/2026-08-27-multi-app-auth-identity/` | ✅ mirrored (2 files) | Mercury `docs/design/ADD-PLATFORM-001-ITER-2-auth-sst.md` (cross-repo ADD) | Minimal docs (only proposal) by design; production: `MultiAppRbacService` + 4 Me/* use cases |
| **2026-08-27** | **dashboard-metrics-redesign** | archive | `archive/2026-08-27-dashboard-metrics-redesign/` | ✅ mirrored (3 files) | — | Minimal docs (proposal+design) but fully shipped; routes active |
| **2026-09-09** | **tenant-data-model-correction** | archive | (no Docs/changes mirror — openspec-only change) | ✅ mirrored (3 files: FIXUP-PENDING, mercurio-contracts spec, archive-report) | Mercury `Docs/integrations/mercurio-webhook-contracts.md` | 8 commits across 4 days; pivot-backed state model; CQRS-Lite depth projection; Mercury webhook contract; definitive drop of `entidad.estado` / `cliente_desde` (Commit 8) |

---

## Discarded (Misplaced — wrong repo) — 1 change

| Date | Change | Reason | Where the actual content lives | Mirror here |
|------|--------|--------|--------------------------------|-------------|
| **2026-08-27** | **gestion-contactos-oportunidades** | Frontend change (React/dashboard-crm), NEVER crm-laravel scope. Proposal listed Affected Areas in `dashboard-crm/src/` not `crm-laravel/app/` | **`D:\sitios desarrollo\dashboard-crm\docs\changes\gestion-contactos-oportunidades\`** ← all 4 standard files (proposal/spec/design/tasks) + `archive-report.md` proving delivery (ContactoFormModal + EntidadFormModal modals shipped) | Only `discard-report.md` mirrored here; cross-ref in discard-report.md body |

**The discard-report.md in this folder and in crm-laravel/Docs/changes/archive/ explains the displacement.** Future agents looking for the change's actual artifacts: go to dashboard-crm, not crm-laravel.

---

## Active (Not yet archived) — 2 changes

| Change | Folder | Status |
|--------|--------|--------|
| `multi-app-access` | `Docs/changes/multi-app-access/` | Apply "complete" per apply-progress.md batch 2i-b (02/08), all 9 criticals claimed fixed. **NO `verify-report-v3` written, NO `archive-report`. User feedback 2026-08-27: "no funciona ni veo que tenga seeds" — runtime broken.** Needs re-verify or escalation. |
| `erp-unification-phase1` | `Docs/changes/erp-unification-phase1/` | Has design.md + tasks.md; no verify-report. **ERP scope** (excluded from non-ERP reports). |

---

## What goes in canonical Docs/changes/ but NOT in this openspec/ mirror

Some crm-laravel-specific artifacts don't have a standard OpenSpec equivalent and live ONLY in Docs/changes/archive/:

| Artifact | Why mirrored? |
|----------|---------------|
| `explore.md` (some changes have it) | OpenSpec has no separate "exploration" phase; the exploration narrative lives in `proposal.md` §Context or §Background. Not mirrored. |
| `email-draft.md` (pipelines-crud-webhook) | crm-laravel-specific marketing/notification artifact. Not mirrored. |
| `verify-report-*.md` variants (production-blockers has `verify-report-c1-c2-c3.md`) | Standard OpenSpec would call this `verify-report.md`. Mirror policy: copy if the name is canonical; otherwise leave in Docs/changes/ only. The c1-c2-c3 variant is preserved in Docs/changes/ as historical evidence; openspec/ does not duplicate non-canonical verify report names. |
| `discard-report.md` (gestion-contactos-oportunidades) | OpenSpec has no explicit discard state; this artifact is mirrored as the closure doc. |

---

## File-system conventions for this mirror

```
openspec/changes/archive/<YYYY-MM-DD>-<change-name>/
├── proposal.md         # Required for archive
├── tasks.md            # If present in source
├── design.md           # If present in source
├── spec.md             # If present in source (rare — most crm-laravel changes use delta specs)
├── archive-report.md   # Required for archive (closure document)
└── discard-report.md   # ONLY for discarded changes (replaces archive-report.md)
```

Naming: date prefix `YYYY-MM-DD-` matches `Docs/changes/archive/` convention. Folder name matches the original change folder name verbatim.

**Creation policy**: when a change is archived in `Docs/changes/archive/`, copy the standard files into the corresponding `openspec/changes/archive/<date>-<name>/` folder in the SAME operation. The Docs/changes/ remains canonical; openspec/ is the introspection-friendly mirror.