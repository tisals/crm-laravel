# Memory — CRM Laravel Session

## Goal
Import 3,543 oportunidades CSV y limpiar calidad de datos (entidades/contactos duplicados, detalles reales)

## What Was Done
- ✅ SDD `importar-oportunidades-csv` completo (proposal→spec→design→tasks→apply→verify→archive)
- ✅ 3,458 / 3,543 oportunidades importadas (98.7%)
- ✅ Pipeline fila-por-fila: entidad→contacto→oportunidad→detalle
- ✅ Email splitting, keyword matching, parseFecha fallback, apellidos NOT NULL
- ✅ **Cleanup entidades/contactos**: `database/cleanup_dupes.php`
  - Phase 1: 178 merges por dominio
  - Phase 2: 234 merges por nombre normalizado
  - Phase 3-5: contactos mergeados
  - Phase 6: 31 entidades sin contactos eliminadas
  - Phase 7: 1,109 contactos mergeados por email GLOBAL
  - **Resultado**: 3,183→2,740 entidades, 4,172→2,679 contactos
- ✅ `superNormalize()` implementado en UseCase y Seeder (previene futuros duplicados)
- ✅ **Detalles reales importados** desde `Docs/detalle_oportunidad.csv`
  - 2,747 sintéticos → **3,601 reales** (854 filas recuperadas)
  - 601 ops con múltiples line items (hasta 6)
  - 708 ops sin detalle en CSV fuente (genuino)
  - Nuevo seeder: `DetalleOportunidadCsvSeeder`

## Key Discoveries
- **443 entidades duplicadas** (275 grupos por dominio, 298 por nombre)
- **1,109 contactos duplicados por email GLOBAL** (937 grupos) — el Phase 3 original solo dedup dentro de la misma entidad
- **CSV de detalles separado**: `Docs/detalle_oportunidad.csv` con 3,629 filas reales. El pipeline actual construía detalles sintéticos desde `valor_sin_iva`
- **Sufijos SAS/LTDA/SA**: `superNormalize()` maneja TODAS las variantes (S.A.S., S. A. S., s a s, etc.)
- **Unique constraint** `contacto(entidad_id, email_contacto)` requiere merge individual de contactos
- DB en MariaDB Docker, **NO ejecutar php artisan test** (conecta a MariaDB no SQLite)
- `concepto` en `detalle_oportunidad` es `varchar(255)` — conceptos del CSV tienen párrafos largos
- `medida` es `varchar(10)` — "Metro Lineal" (12 chars) no entra

## How to Proceed
1. Considerar migrar `concepto` a `text` y `medida` a `varchar(20)` en detalle_oportunidad
2. Validar datos limpios en frontend CRMPage
3. Ajustar tipos Contacto en frontend (`nombre` vs `nombres`)
4. Revisar 708 ops sin detalle (si corresponde)

## Files Created/Modified
- `database/cleanup_dupes.php` — script de limpieza (6 fases + Phase 7 global contact dedup)
- `database/seeders/DetalleOportunidadCsvSeeder.php` — importa detalles reales desde CSV
- `database/csv/detalle_oportunidad.csv` — copia de Docs/ como fuente de verdad
- `app/Application/UseCases/Oportunidad/OportunidadCsvImportUseCase.php` — superNormalize
- `database/seeders/OportunidadCsvSeeder.php` — superNormalize
