# csv-seeder Specification

## Purpose

Seed the database with real data from CSV files in `Docs/` instead of hardcoded arrays. Each seeder handles data quality issues (Excel artifacts, semicolon delimiters, date parsing) and is idempotent.

## Requirements

### Requirement: CiudadSeeder imports from ciudades.csv

The system MUST provide `RealDataSeeder` that calls CiudadSeeder, ContactoSeeder, EntidadSeeder, ProductoSeeder.

CiudadSeeder MUST read `Docs/ciudades.csv` (semicolon-delimited, ~2000 rows) and upsert each row into the `ciudades` table.

#### Scenario: Full import succeeds

- GIVEN a fresh database with empty `ciudades` table
- WHEN `php artisan db:seed --class=RealDataSeeder` runs
- THEN ~2000 cities are inserted with `cod_municipio`, `nombre`, `departamento`
- AND each row has `created_at` and `updated_at` timestamps

#### Scenario: Idempotent re-run

- GIVEN the seeder has already run once
- WHEN it runs again
- THEN no duplicate rows are created
- AND no rows are deleted
- AND `updated_at` timestamps are refreshed

#### Scenario: CSV data quality handling

- GIVEN the CSV contains trailing empty rows at the end
- WHEN the seeder reads it
- THEN those empty rows MUST be skipped
- AND no SQL errors occur

### Requirement: ContactoSeeder imports from contactos.csv

ContactoSeeder MUST read `Docs/contactos.csv` (~296 rows) and map columns to the `contactos` table.

#### Scenario: Column mapping works

- GIVEN contactos.csv with fields like `email_contacto`, `nombres`, `apellidos`, `entidad_id`, `cargo`, `tel_contacto`, `rol`
- WHEN the seeder processes each row
- THEN each field is mapped to the correct DB column
- AND empty or missing fields are stored as NULL

#### Scenario: Multi-line values handled

- GIVEN a CSV cell contains a line break or Excel line-wrapping artifact
- WHEN the seeder reads it
- THEN the entire cell value is captured (not truncated at newline)
- AND no SQL errors from unescaped characters

#### Scenario: Entity ID mapping

- GIVEN contactos.csv references `entidad_id` values
- WHEN the seeder processes each row
- THEN it creates the contacto only if the referenced entidad exists (or existing contactos exist from EntidadSeeder)

### Requirement: EntidadSeeder imports from Entidades.csv

EntidadSeeder MUST read `Docs/Entidades.csv` (~144 rows) and map columns to the `entidad` table.

#### Scenario: Estado mapping

- GIVEN the CSV has an `estado` column with values like "Cliente", "Prospecto", "Inactivo"
- WHEN the seeder processes each row
- THEN these values are mapped to the enum values accepted by the DB (`Activo`, `Prospecto`, `Inactivo`)
- AND unrecognized estados default to "Prospecto"

#### Scenario: City code lookup

- GIVEN the CSV has a `cod_municipio` column
- WHEN the seeder processes each row
- THEN it looks up the corresponding `ciudades.cod_municipio` to set `ciudad_id`
- AND if the city code is not found, `ciudad_id` is set to NULL

#### Scenario: Artifact handling

- GIVEN the CSV contains `#¿NOMBRE?` artifacts in any cell
- WHEN the seeder processes that row
- THEN the artifact is treated as NULL/empty
- AND the row is still inserted with valid fields

### Requirement: ProductoSeeder imports from productos.csv

ProductoSeeder MUST read `Docs/productos.csv` (~37 rows) and map columns to the `productos` table.

#### Scenario: IVA percentage parsing

- GIVEN the CSV has an `iva` column with percentage values like "19%", "0%", "5%"
- WHEN the seeder processes each row
- THEN the `%` sign is stripped and the value is stored as a numeric percentage (e.g. `19`, `0`, `5`)
- AND rows with empty IVA default to `0`

#### Scenario: Line mapping

- GIVEN productos.csv with columns like `nombre`, `precio`, `medida`, `estado`, `iva`
- WHEN the seeder inserts a row
- THEN all fields are correctly mapped to `productos` columns
- AND trailing empty columns are ignored
