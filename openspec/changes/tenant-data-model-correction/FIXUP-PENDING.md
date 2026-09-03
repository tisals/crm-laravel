# Session log — fix-up de los 82 tests fallando

**Estado al cerrar** (Commit 5.5 = `2cadf5b`):

| | Inicio Commit 4 | Después Commit 5.5 |
|---|---|---|
| Passing | 318 | 248 |
| Failing | 0 | 82 |
| Skipped | 1 | 6 |

Los 82 failures son pre-existentes acumulados en Commits 4, 5, 5.5. Categorías:

1. **Tests que setean `entidad.estado` directo (legacy column dropped en Commit 5.5)**
   - Bulk-remove.ps1 ya quitó `'estado' => 'Activo'` de 14 archivos.
   - Falta migrar a `entidad_relacion` pivot writes — pendiente.

2. **Tests que leen `->email_principal`/`->telefono_principal`/`->direccion`/`->ciudad`/`->pais`**
   - `app/Models/Persona::getEmailAttribute()` accessor ya existe vía `emails()`.
   - `app/Models/Entidad::getDireccionAttribute()`/`getEmailAttribute()` accessors ya existen.
   - Tests que leen `$entidad->email` ahora SÍ funcionan via accessor — algunos pasan.
   - Pendiente: bulk-fix `->email_principal` reads via helper.

3. **`Contacto::factory()->create([..., 'entidad_id' => X])` y `'contacto_id' => X` en `Oportunidad::create`**
   - Bulk-fix-legacy-reads.ps1 ya arregló algunos.
   - `EntidadFactory::configure()` ya crea pivot row.
   - `ContactoFactory::afterCreating()` ya crea persona + emails + pivot.
   - Pendiente: revisar 1 test fallando en `SeguimientoControllerTest`.

4. **`SeguimientoControllerTest::it_updates_a_seguimiento_with_persona_id`**
   - Espera `estado='Completado'`, recibe `Pendiente`.
   - El use case `UpdateSeguimientoUseCase` o el modelo Seguimiento resetea estado.

## Lo que ya está hecho en esta sesión
- Commit 4 (`071c5f6`): drop legacy contact columns + code migration + accessor
- Commit 5 (`ac6d3c1`): entidad_relacion pivot
- Commit 5.5 (`2cadf5b`): frecuencia + recurrencia_cada_meses + vigencia_meses + drop estado/cliente_desde

## Próximo commit sugerido
**Commit 5.6 — Test fix-up sweep** (separado de los schema changes):
- Bulk-fix todos los tests que escriben a `entidad.estado` → migrar a pivot
- Bulk-fix `->email_principal` reads → usar accessors
- Bulk-fix `'contacto_id' => $X->id` en Oportunidad → `'persona_id' => $X->persona_id`
- Arreglar `SeguimientoControllerTest::it_updates_*` (UpdateSeguimientoUseCase bug)

## Commits pendientes (luego de 5.6)
- **Commit 6**: CQRS depth projection (ProjectionLevel enum, query param ?depth=1|2|3)
- **Commit 7**: entidad snapshot a Mercurio
- **Commit 8**: finalizar código de aplicación para escribir a las nuevas tablas (drop definitivo de legacy columns)

## Hallazgos importantes
- MariaDB 10.11 no permite CHECK con columnas FK → usar triggers con SIGNAL SQLSTATE
- `Entidad::getEstadoAttribute()` deriva desde pivot: 'activo' si hay al menos una relacion con `effective_to IS NULL`
- `EntidadFactory` ahora setea pivot row automaticamente en `configure()` (afterCreating)
- `refreshDatabase` solo corre `migrate:fresh` 1 vez por clase (estático `$migrated` flag); entre métodos usa transactions
- PowerShell regex replace necesita escape correcto de comillas (`''es_principal''` para PHP), sino inserta sintaxis rota

## Files importantes creados
- `database/migrations/2026_09_02_120000_create_entidad_relacion_table.php`
- `database/migrations/2026_09_02_130000_backfill_entidad_estado_to_entidad_relacion.php`
- `database/migrations/2026_09_03_140000_add_frecuencia_to_entidad_relacion_and_drop_legacy_estado.php`
- `database/migrations/2026_09_03_140100_add_vigencia_meses_to_entidad_relacion.php`
- `app/Models/EntidadRelacion.php`
- `app/Models/Persona.php` (5 HasMany relations + accessor helpers)
- `app/Models/Entidad.php` (5 HasMany relations + 4 accessors + getEstadoAttribute)
- `tests/CreatesEntidadForTesting.php` (trait no aplicado a tests aún)
- `tests/Feature/Schema/EntidadRelacionTableTest.php` (14 tests: 7 nuevos + 7 originales)
- `openspec/changes/tenant-shared-contact-tables/spec.md`
- `openspec/changes/tenant-business-state-pivot/spec.md`

Branch: `feat/iter4-persona-tracker`, HEAD: `2cadf5b`
