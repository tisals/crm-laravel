# Archive Index — Docs/changes/archive/

> **Purpose**: this file is the single entry point for future agents to locate the full content of every archived/discarded change in crm-laravel.
>
> **Convention**: `Docs/changes/` is the **canonical content store** for crm-laravel SDD artifacts. Each change folder here is mirrored (standard OpenSpec files only) into `openspec/changes/archive/` for tooling introspection. See `openspec/changes/archive/INDEX.md` for the full mapping.
>
> **Last updated**: 2026-08-27 (5 changes archived/discarded in this session — 4 archives + 1 discard)

---

## Archived (Shipped) — 12 changes

| Date | Change | Folder | Mirror in openspec/ | Notes |
|------|--------|--------|---------------------|-------|
| 2026-05-14 | crm-phase1-cierre | `archive/2026-05-14-crm-phase1-cierre/` | ✅ | pre-OpenSpec era; proposal/design/tasks only |
| 2026-05-15 | sailus-integration-v1 | `archive/2026-05-15-sailus-integration-v1/` | ✅ | proposal/design/tasks only |
| 2026-05-21 | mejoras-post-lanzamiento | `archive/2026-05-21-mejoras-post-lanzamiento/` | ✅ | proposal/design/tasks |
| 2026-05-22 | modulo-seguridad | `archive/2026-05-22-modulo-seguridad/` | ✅ | proposal/design/tasks |
| 2026-06-26 | crud-seguimiento | `archive/2026-06-26-crud-seguimiento/` | ✅ | + archive-report |
| 2026-07-09 | erp-unification-phase2 | `archive/2026-07-09-erp-unification-phase2/` | ✅ | + spec.md; ERP scope |
| 2026-07-10 | opportunity-versioning | `archive/2026-07-10-opportunity-versioning/` | ✅ | + archive-report |
| 2026-08-21 | AFIN-001-modulo-administrativo-financiero | `archive/2026-08-21-AFIN-001-modulo-administrativo-financiero/` | ✅ | ERP module; ~190 KB; has verify-report |
| **2026-08-27** | **production-blockers-and-ux-fixes** | `archive/2026-08-27-production-blockers-and-ux-fixes/` | ✅ | 13 items; PDF C1+C2+C3 formally verified |
| **2026-08-27** | **pipelines-crud-webhook** | `archive/2026-08-27-pipelines-crud-webhook/` | ✅ | Use cases exist; **routes NOT wired** (open finding from verify) |
| **2026-08-27** | **multi-app-auth-identity** | `archive/2026-08-27-multi-app-auth-identity/` | ✅ | Minimal docs (proposal only); cross-repo ref: Mercury ITER-2 ADD |
| **2026-08-27** | **dashboard-metrics-redesign** | `archive/2026-08-27-dashboard-metrics-redesign/` | ✅ | Minimal docs (proposal+design); fully shipped |

---

## Discarded (Misplaced — wrong repo) — 1 change

| Date | Change | Folder (here) | Reason | Where the actual content lives |
|------|--------|---------------|--------|--------------------------------|
| **2026-08-27** | **gestion-contactos-oportunidades** | `archive/2026-08-27-gestion-contactos-oportunidades/` (only `discard-report.md` here) | Frontend change (React/dashboard-crm), NEVER crm-laravel scope | **`D:\sitios desarrollo\dashboard-crm\docs\changes\gestion-contactos-oportunidades\`** ← proposal/spec/design/tasks + archive-report (delivery proof) |

The `discard-report.md` here explains the displacement in full. The 4 standard SDD files live in dashboard-crm because that was always the right location. This folder exists ONLY to leave an audit trail in crm-laravel so future crm-laravel agents don't search for a change that was never here.

---

## Active (Not yet archived) — 2 changes

| Change | Folder | Status |
|--------|--------|--------|
| `multi-app-access` | `Docs/changes/multi-app-access/` | Apply "complete" per apply-progress.md batch 2i-b (02/08), 9 criticals claimed fixed. **NO verify-report-v3, NO archive-report. User 2026-08-27: "no funciona ni veo que tenga seeds" — runtime broken.** Open work. |
| `erp-unification-phase1` | `Docs/changes/erp-unification-phase1/` | Has design.md + tasks.md; no verify-report. ERP scope. |

---

## What is in this folder but NOT mirrored in openspec/

| Artifact | Reason |
|----------|--------|
| `explore.md` | OpenSpec has no separate exploration phase |
| `email-draft.md` | crm-laravel-specific notification artifact |
| `verify-report-c1-c2-c3.md` (non-canonical name) | Only canonical `verify-report.md` is mirrored; this variant stays here as historical evidence |
| `discard-report.md` | Mirrored ONLY when no archive-report.md exists; otherwise the discard-reason is folded into the canonical archive-report.md |

---

## Archive folder conventions

```
Docs/changes/archive/<YYYY-MM-DD>-<change-name>/
├── proposal.md         # always
├── spec.md             # if present
├── design.md           # if present
├── explore.md          # if present (crm-laravel convention; not mirrored)
├── tasks.md            # if present
├── email-draft.md      # if present (crm-laravel convention; not mirrored)
├── verify-report*.md   # if present (canonical name mirrored; variants stay here)
├── archive-report.md   # closure doc for shipped changes
└── discard-report.md   # closure doc for discarded/misplaced changes
```

Mirror policy: standard OpenSpec files (proposal/spec/design/tasks/archive-report) go to `openspec/changes/archive/<date>-<name>/` in the SAME archive operation. crm-laravel-specific extras stay here as canonical.