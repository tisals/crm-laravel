# Change Proposal: ERP Unification Phase 2 (Multi-Pipeline & Lead Ingestion)

## 1. Goal & Objectives
El objetivo es implementar la infraestructura lógica para soportar múltiples Pipelines simultáneos en el módulo CRM (ej. Pipeline de Llegada y Pipeline de Rescate) y refactorizar el ingreso de leads para que implemente el "Pipeline Pattern" y el "AssignScoreAction" para la calificación automatizada.

## 2. Scope & Affected Components
- **Módulo CRM (`Modules/CRM`):**
  - Actualización del modelo `Oportunidad` (adición del campo `pipeline` y nuevos estados).
  - Implementación de `AssignScoreAction` para calcular el score inicial de cada Lead.
  - Implementación de un pipeline de registro/ingesta mediante el patrón Pipeline de Laravel.
- **Base de datos:**
  - Migración para agregar la columna `pipeline` a la tabla `oportunidad`, y ajustar la restricción/definición del enum `estado` para incluir los nuevos estados del pipeline de rescate.
- **Rutas API:**
  - Garantizar que `/api/v1/contacto` y los webhooks expuestos sigan funcionando transparentemente (retrocompatibilidad).

## 3. Rollback Plan
- Revertir las migraciones de base de datos (`php artisan migrate:rollback`).
- Restaurar los archivos modificados a su estado original mediante Git (`git checkout`).

## 4. Risks & Mitigaciones
- **Riesgo:** Pérdida de datos en producción al modificar el campo `estado` (enum) de la tabla `oportunidad`.
- **Mitigación:** Diseñar una migración segura que modifique el tipo de columna sin truncar o corromper los registros existentes.
- **Riesgo:** Errores de validación en la SPA al enviar nuevos estados de oportunidades.
- **Mitigación:** Asegurar que los endpoints continúen retornando los estados antiguos sin problemas de serialización, y definir claramente los valores del pipeline por defecto ('Llegada').
