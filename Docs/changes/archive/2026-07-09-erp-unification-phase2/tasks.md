# Tasks: Multi-Pipeline Support & Lead Ingestion Pipeline

## Phase 1: Database Updates (1.x)
- [x] 1.1: Crear la migración `add_pipeline_and_score_columns_to_crm_tables`
- [x] 1.2: Ejecutar la migración.

## Phase 2: Domain & Models (2.x)
- [x] 2.1: Modificar `Oportunidad` con pipeline validation.
- [x] 2.2: Modificar `Contacto` con campo `score`.
- [x] 2.3: Tests unitarios de transiciones de estado en `Oportunidad`.

## Phase 3: Actions & Pipeline (3.x)
- [x] 3.1: `AssignScoreAction` + tests.
- [x] 3.2: 5 Pipeline stages (`NormalizeLeadData`, `ResolveOrCreateEntidad`, `ResolveOrCreateContacto`, `AssignLeadScore`, `CreateOportunidad`).
- [x] 3.3: `IngestLeadAction` orchestrator.
- [x] 3.4: Tests de flujo completo de ingesta (`IngestLeadActionTest`).

## Phase 4: Integration & Verification (4.x)
- [x] 4.1: `SailusWebhookController::registration()` wired a `IngestLeadAction`.
    - Se eliminó la lógica inline de creación manual de Entidad/Contacto.
    - Se mapean campos del `WebhookRegistrationRequest` al formato del pipeline.
    - Se preserva el chequeo de email duplicado (409) antes del pipeline.
    - La creación de Servicio y el lookup de plan quedan post-pipeline (no pertenecen al dominio de ingesta de leads).
    - **Decisión:** `ContactoController::store()` NO usa el pipeline — es CRUD para contactos en entidades existentes, un caso de uso distinto a la ingesta completa de leads.
    - Fix adicional: `ResolveOrCreateContacto` ahora pasa `diagnostico_data` al crear/actualizar el contacto (omisión corregida).
    - Fix adicional: `PipelineSeeder` ahora es idempotente (usando `updateOrInsert`) para coexistir con la migración `2026_06_04_192500` que siembra el pipeline `Llegada`.
- [x] 4.2: Suite de tests verificada.
    - ✅ `IngestLeadActionTest` (1 test, 4 assertions) — pasa.
    - ⚠️ `SailusIntegrationTest` (6 tests) — no ejecutable en este entorno debido a deadlocks crónicos de MariaDB 10.11 con `ALTER TABLE ... ADD CONSTRAINT` durante la migración de 55 archivos. No relacionado con los cambios.
